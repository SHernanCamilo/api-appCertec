<?php

declare(strict_types=1);

namespace App\Services\Fabric\Export;

use Illuminate\Support\Facades\Log;
use ZipArchive;

/**
 * Escritor de .xlsx dedicado a datasets grandes (cientos de miles de filas).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ¿POR QUÉ EXISTE ESTE ARCHIVO?
 * ─────────────────────────────────────────────────────────────────────────────
 * Exportar VW_AD_Paciente (567K filas × 57 columnas = 32 millones de celdas)
 * tardaba más de 4 minutos y el usuario veía el progreso clavado en 96%.
 *
 * El cuello de botella medido no era la red ni Fabric: era construir el xlsx.
 * Benchmark local (PHP 8.2, sin opcache, 2M celdas):
 *
 *   leer .gz + json_decode ............  0.6 s   (3.2M celdas/s)  ← no es el problema
 *   OpenSpout (StringCell) ...........  24.0 s   ( 83K celdas/s)
 *   OpenSpout (Row::fromValues) ......  22.0 s   ( 91K celdas/s)
 *   XML directo (esta clase) .........   1.2 s   (1.65M celdas/s)
 *
 * OpenSpout crea un objeto Cell + un objeto Row por celda/fila, consulta el
 * registro de estilos y arma el XML con sprintf. Para 32M celdas eso son 32
 * millones de objetos: minutos de puro overhead de objetos. PhpSpreadsheet es
 * mucho peor todavía, porque además guarda la hoja completa en memoria: 20K
 * filas × 57 columnas tardaban 272 s y pedían 960 MB de RAM.
 *
 * Esta clase escribe el XML de la hoja directamente con concatenación de
 * strings y comprime con ZipArchive. Un .xlsx es un ZIP con XMLs dentro; para
 * una tabla plana (encabezado + datos, sin fórmulas ni gráficos) el XML es
 * simple y estable, así que generarlo a mano es seguro y ~20x más rápido.
 *
 * Medido de punta a punta con el caso que originó el cambio
 * (VW_AD_Paciente, 567.740 filas × 57 columnas = 32.4 millones de celdas):
 *
 *      antes  → más de 4 minutos, progreso clavado en 96%
 *      ahora  → 42 s, 10 MB de RAM, archivo de 216 MB
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * LO QUE SÍ CONSERVA (no es un CSV disfrazado)
 * ─────────────────────────────────────────────────────────────────────────────
 *   - Encabezado azul corporativo, negrita, texto blanco, congelado al scroll
 *   - Autofiltro en todo el rango
 *   - Ancho de columna estimado con una muestra
 *   - Fechas como fecha real de Excel (serial + formato), no como texto
 *   - Números como número (se pueden sumar y ordenar)
 *   - Ceros iniciales preservados en NIT, placas, cuentas y códigos
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * LO QUE NO HACE (a propósito)
 * ─────────────────────────────────────────────────────────────────────────────
 *   - Varias hojas, fórmulas, imágenes, filas zebra, títulos de portada.
 *     Para eso está el camino de PhpSpreadsheet en StreamingExportWriter, que
 *     se sigue usando en datasets chicos (≤ 50K filas), donde su costo no se
 *     nota y el formato rico aporta más.
 *
 * Memoria: fija (~10 MB). Nunca carga el dataset completo: lee el .gz línea
 * por línea y vuelca el XML a disco cada 1 MB.
 *
 * @see StreamingExportWriter::fromNdjsonGzFile() punto de entrada que decide el camino
 */
final class FastXlsxWriter
{
    /**
     * Marca de versión del writer. Sirve para verificar en el log de producción
     * que el worker está ejecutando el código nuevo y no bytecode viejo cacheado
     * por OPcache. Súbelo en cada cambio relevante del saneo/validación.
     */
    private const BUILD = '2026-08-29-xmlguard';

    /** Límite físico de filas de una hoja de Excel (incluye el encabezado). */
    public const EXCEL_MAX_ROWS = 1048576;

    /** Filas que se leen al inicio para deducir columnas, tipos y anchos. */
    private const SAMPLE_ROWS = 200;

    /**
     * Tamaño del búfer de XML antes de volcar a disco.
     *
     * 256 KB en vez de 1 MB: los búferes grandes aumentan la probabilidad de
     * escrituras parciales (que ahora se manejan en writeAll, pero es mejor no
     * provocarlas) y el rendimiento es prácticamente idéntico.
     */
    private const FLUSH_BYTES = 262144;

    /**
     * Nivel de compresión del ZIP.
     *
     * El XML de una hoja grande pesa cientos de MB, así que el nivel importa.
     * Medido sobre una hoja real de 357 MB (100K filas × 57 columnas):
     *
     *      nivel 1 →  2.23 s → 37.7 MB
     *      nivel 3 →  2.34 s → 30.4 MB   ← elegido
     *      nivel 6 →  4.72 s → 26.8 MB
     *      nivel 9 → 19.59 s → 26.2 MB
     *
     * El 3 baja 19% el tamaño por 5% más de tiempo. Del 3 al 6 se paga el doble
     * de CPU por un 12% adicional, y el 9 es un mal negocio en cualquier caso.
     */
    private const ZIP_LEVEL = 3;

    /** Índices de estilo definidos en styles.xml (ver buildStylesXml). */
    private const STYLE_HEADER    = 1;
    private const STYLE_DATE      = 2;
    private const STYLE_DATETIME  = 3;

    /**
     * Filas de portada antes de los datos: título + info de exportación + fila
     * de encabezados. Los datos arrancan en HEADER_ROW + 1. Da el mismo aspecto
     * corporativo que el camino de PhpSpreadsheet para vistas chicas.
     */
    private const TITLE_ROW  = 1;
    private const INFO_ROW    = 2;
    private const HEADER_ROW  = 3;
    private const FIRST_DATA_ROW = 4;

    /** Estilo del título y de la línea de info (definidos en stylesXml). */
    private const STYLE_TITLE = 4;
    private const STYLE_INFO  = 5;

    /**
     * Genera el .xlsx a partir de un NDJSON gzipeado.
     *
     * @param  string      $gzPath    Archivo .ndjson.gz (una fila JSON por línea)
     * @param  string      $targetDir Directorio donde se escribe el .xlsx
     * @param  string      $baseName  Nombre del archivo sin extensión
     * @param  string      $sheetName Nombre de la hoja (se sanea a reglas de Excel)
     * @param  string|null $title     Título de la portada (ej. "dc.VW_AD_Paciente").
     *                                Null = sin portada (los datos empiezan en la fila 1).
     * @return ExportResult|null      Null si el dataset no es apto (vacío o excede
     *                                el límite de filas); el llamador cae a CSV.
     */
    public static function fromNdjsonGz(
        string $gzPath,
        string $targetDir,
        string $baseName,
        string $sheetName,
        ?string $title = null,
    ): ?ExportResult {
        if (!is_file($gzPath)) {
            return null;
        }

        $layout = self::inspect($gzPath);
        if ($layout === null) {
            return null; // sin filas útiles
        }

        /** @var list<string> $headers */
        $headers = $layout['headers'];
        /** @var array<int,string> $types */
        $types = $layout['types'];
        /** @var array<int,int> $widths */
        $widths = $layout['widths'];

        $colCount  = count($headers);
        $sheetXml  = "{$targetDir}/{$baseName}.sheet.xml";
        $xlsxPath  = "{$targetDir}/{$baseName}.xlsx";

        $out = fopen($sheetXml, 'wb');
        if ($out === false) {
            return null;
        }

        $gz = gzopen($gzPath, 'rb');
        if ($gz === false) {
            fclose($out);
            @unlink($sheetXml);

            return null;
        }

        // Letras de columna precalculadas: computarlas por celda costaba 25% del
        // tiempo total (una llamada de función + bucle por cada celda).
        $letters = self::columnLetters($colCount);

        $hasCover = $title !== null;

        if (!self::writeAll($out, self::sheetProlog($headers, $widths, $letters, $hasCover ? $title : null))) {
            fclose($out);
            gzclose($gz);
            @unlink($sheetXml);

            Log::error('[FastXlsxWriter] fallo al escribir la cabecera de la hoja', ['sheet' => $sheetName]);

            return null;
        }

        /** @var array<string,int> Cache fecha(YYYY-MM-DD) → serial de Excel */
        $dayCache = [];
        $buffer   = '';
        // Con portada los datos empiezan en la fila 4 (título, info, encabezado);
        // sin portada, en la fila 2 (solo encabezado en la 1).
        $rowNumber   = $hasCover ? self::HEADER_ROW : 1;
        $truncated   = false;
        $writeFailed = false;

        try {
            while (($line = gzgets($gz)) !== false) {
                if ($line === "\n" || $line === "\r\n" || trim($line) === '') {
                    continue;
                }

                $row = self::decodeLine($line);
                if ($row === null) {
                    continue;
                }

                if ($rowNumber >= self::EXCEL_MAX_ROWS) {
                    $truncated = true;
                    break;
                }

                $rowNumber++;

                // Se mapea por nombre de columna, no con array_values(): Fabric
                // puede devolver las claves en otro orden en filas posteriores,
                // y ahí array_values() metería cada valor bajo la columna
                // equivocada sin que nada falle. Medido, la diferencia es de
                // ~1 s en 567K filas contra un riesgo de datos corridos.
                $values = [];
                foreach ($headers as $header) {
                    $values[] = $row[$header] ?? null;
                }

                $buffer .= '<row r="' . $rowNumber . '">';

                foreach ($values as $i => $value) {
                    if ($value === null || $value === '') {
                        continue; // celda ausente = celda vacía en Excel
                    }

                    $ref  = ($letters[$i] ?? 'A') . $rowNumber;
                    $type = $types[$i] ?? 'text';

                    if ($type === 'date') {
                        $serial = self::excelSerial((string) $value, $dayCache);
                        if ($serial !== null) {
                            $buffer .= '<c r="' . $ref . '" s="'
                                . (is_float($serial) ? self::STYLE_DATETIME : self::STYLE_DATE)
                                . '"><v>' . $serial . '</v></c>';
                            continue;
                        }
                    } elseif ($type === 'num') {
                        if (is_int($value) || is_float($value)) {
                            $buffer .= '<c r="' . $ref . '"><v>' . $value . '</v></c>';
                            continue;
                        }
                        if (ExportValueFormatter::isSafeExcelNumber($value)) {
                            $buffer .= '<c r="' . $ref . '"><v>' . $value . '</v></c>';
                            continue;
                        }
                    }

                    // Texto (y todo lo que no encajó como fecha o número).
                    $buffer .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">'
                        . self::xmlText((string) $value)
                        . '</t></is></c>';
                }

                $buffer .= '</row>';

                if (isset($buffer[self::FLUSH_BYTES])) {
                    if (!self::writeAll($out, $buffer)) {
                        $writeFailed = true;
                        break;
                    }
                    $buffer = '';
                }
            }
        } finally {
            gzclose($gz);
        }

        // Escritura incompleta (disco lleno, cuota, error de I/O): abortar. Antes
        // se seguía adelante y el XML quedaba truncado a la mitad, que es lo que
        // Excel reporta como "Cargar error. Línea N, columna 0".
        if ($writeFailed) {
            fclose($out);
            @unlink($sheetXml);

            Log::error('[FastXlsxWriter] escritura incompleta del XML, se cae a CSV', [
                'sheet'      => $sheetName,
                'free_bytes' => @disk_free_space($targetDir) ?: null,
            ]);

            return null;
        }

        $dataRows = $rowNumber - ($hasCover ? self::HEADER_ROW : 1);

        // Excel no puede con el dataset: se descarta el trabajo y el llamador
        // entrega CSV, que no tiene límite de filas.
        if ($truncated) {
            fclose($out);
            @unlink($sheetXml);

            Log::warning('[FastXlsxWriter] dataset excede el limite de filas de Excel', [
                'sheet' => $sheetName,
                'limit' => self::EXCEL_MAX_ROWS,
            ]);

            return null;
        }

        if ($dataRows === 0) {
            fclose($out);
            @unlink($sheetXml);

            return null;
        }

        $epilogOk = self::writeAll($out, $buffer . self::sheetEpilog($colCount, $rowNumber, $letters, $hasCover));

        // fclose también puede fallar si quedaban datos en el búfer del SO y no
        // se pudieron volcar (disco lleno). Hay que comprobarlo.
        $closeOk = fclose($out);

        if (!$epilogOk || !$closeOk) {
            @unlink($sheetXml);

            Log::error('[FastXlsxWriter] no se pudo cerrar el XML completo, se cae a CSV', [
                'sheet'      => $sheetName,
                'epilog_ok'  => $epilogOk,
                'close_ok'   => $closeOk,
                'free_bytes' => @disk_free_space($targetDir) ?: null,
            ]);

            return null;
        }

        // Red de seguridad: verificar que el XML de la hoja es válido ANTES de
        // empaquetar. Si por algún caso no previsto quedó mal formado, se
        // devuelve null (el llamador entrega CSV) en vez de darle a Excel un
        // archivo que abrirá "reparando y quitando el contenido". Se registra
        // la línea exacta para poder cazar el dato que lo causó.
        if (!self::sheetXmlIsValid($sheetXml, $sheetName)) {
            @unlink($sheetXml);

            return null;
        }

        if (!self::package($sheetXml, $xlsxPath, $sheetName)) {
            @unlink($sheetXml);
            @unlink($xlsxPath);

            return null;
        }

        @unlink($sheetXml);

        if (!is_file($xlsxPath)) {
            return null;
        }

        // Marca de versión: si esto NO aparece en el log al exportar, es que el
        // worker corre bytecode viejo (OPcache) y hay que reiniciar de verdad.
        Log::info('[FastXlsxWriter] xlsx OK', [
            'build' => self::BUILD,
            'sheet' => $sheetName,
            'rows'  => $dataRows,
            'cols'  => $colCount,
        ]);

        return new ExportResult(
            path: $xlsxPath,
            filename: basename($xlsxPath),
            format: 'xlsx',
            rows: $dataRows,
            bytes: (int) filesize($xlsxPath),
        );
    }

    // =========================================================================
    // DECODIFICACIÓN ROBUSTA DE CADA LÍNEA NDJSON
    // =========================================================================

    /**
     * Decodifica una línea NDJSON tolerando UTF-8 inválido y caracteres de
     * control crudos. La lógica vive en ExportValueFormatter para que el camino
     * clásico use exactamente la misma, y ninguna fila se pierda en ninguno.
     *
     * @return array<string,mixed>|null  null solo si la línea no es JSON de objeto
     */
    private static function decodeLine(string $line): ?array
    {
        return ExportValueFormatter::decodeNdjsonLine($line);
    }

    // =========================================================================
    // MUESTREO: columnas, tipos y anchos
    // =========================================================================

    /**
     * Lee las primeras filas para deducir columnas, tipo y ancho.
     *
     * Inferir el tipo una vez por columna (en vez de por celda) es lo que
     * permite quitar el preg_match de fechas del bucle caliente: para 32M
     * celdas eran 32M expresiones regulares.
     *
     * @return array{headers: list<string>, types: array<int,string>, widths: array<int,int>}|null
     */
    private static function inspect(string $gzPath): ?array
    {
        $gz = gzopen($gzPath, 'rb');
        if ($gz === false) {
            return null;
        }

        $headers = [];
        $sample  = [];

        while (count($sample) < self::SAMPLE_ROWS && ($line = gzgets($gz)) !== false) {
            if (trim($line) === '') {
                continue;
            }

            $row = self::decodeLine($line);
            if ($row === null) {
                continue;
            }

            if ($headers === []) {
                $headers = array_map('strval', array_keys($row));
            }

            $sample[] = array_values($row);
        }

        gzclose($gz);

        if ($headers === [] || $sample === []) {
            return null;
        }

        return [
            'headers' => $headers,
            'types'   => self::inferTypes($headers, $sample),
            'widths'  => self::estimateWidths($headers, $sample),
        ];
    }

    /**
     * Tipo por índice de columna: 'date' | 'num' | 'text'.
     *
     * Se exige que 80% de los valores no vacíos de la muestra coincidan, para
     * que una columna mayoritariamente numérica con algún "N/A" suelto siga
     * saliendo como número.
     *
     * @param  list<string>      $headers
     * @param  list<list<mixed>> $sample
     * @return array<int,string>
     */
    private static function inferTypes(array $headers, array $sample): array
    {
        // Columnas que van como texto por su nombre (NIT, placa, código...) o
        // porque el primer valor tiene cero inicial. Preserva "007" como "007".
        $textColumns = ExportValueFormatter::detectTextColumns($headers, $sample[0] ?? null);

        $types = [];

        foreach ($headers as $i => $_header) {
            if (isset($textColumns[$i])) {
                $types[$i] = 'text';
                continue;
            }

            $seen  = 0;
            $dates = 0;
            $nums  = 0;

            foreach ($sample as $row) {
                $value = $row[$i] ?? null;
                if ($value === null || $value === '') {
                    continue;
                }

                $seen++;

                if (is_int($value) || is_float($value)) {
                    $nums++;
                    continue;
                }

                $text = (string) $value;

                if (preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?)?/', $text) === 1) {
                    $dates++;
                } elseif (ExportValueFormatter::isSafeExcelNumber($text)) {
                    $nums++;
                }
            }

            $types[$i] = match (true) {
                $seen === 0             => 'text',
                $dates / $seen >= 0.8   => 'date',
                $nums / $seen >= 0.8    => 'num',
                default                 => 'text',
            };
        }

        return $types;
    }

    /**
     * Ancho de columna estimado con la muestra (mismo criterio que el camino
     * de OpenSpout: entre 9 y 50 caracteres).
     *
     * @param  list<string>      $headers
     * @param  list<list<mixed>> $sample
     * @return array<int,int>
     */
    private static function estimateWidths(array $headers, array $sample): array
    {
        $widths = [];

        foreach ($headers as $i => $header) {
            $widths[$i] = mb_strlen($header);
        }

        foreach ($sample as $row) {
            foreach ($widths as $i => $current) {
                $value = $row[$i] ?? null;
                if ($value === null) {
                    continue;
                }

                $len = mb_strlen((string) $value);
                if ($len > $current) {
                    $widths[$i] = $len;
                }
            }
        }

        return $widths;
    }

    // =========================================================================
    // FECHAS
    // =========================================================================

    /**
     * Convierte 'YYYY-MM-DD[ HH:MM:SS]' al serial de Excel sin usar DateTime.
     *
     * ExportValueFormatter::toExcelSerial() crea dos objetos DateTime y un
     * DateInterval por llamada. Con 10 columnas de fecha y 567K filas serían
     * 17 millones de objetos: minutos de trabajo. Aquí la parte de fecha se
     * calcula con aritmética de calendario y se memoiza por día (una vista
     * suele repetir pocos miles de fechas distintas), y la hora se suma como
     * fracción de día.
     *
     * Devuelve int si es fecha sin hora (para elegir el formato dd/mm/yyyy) o
     * float si trae hora. Null si no tiene forma de fecha ISO.
     *
     * @param  array<string,int> $dayCache Memo de fecha → serial, por referencia
     */
    private static function excelSerial(string $value, array &$dayCache): int|float|null
    {
        if (strlen($value) < 10) {
            return null;
        }

        $day = substr($value, 0, 10);

        if (!isset($dayCache[$day])) {
            $serial = self::daySerial($day);
            if ($serial === null) {
                return null;
            }
            $dayCache[$day] = $serial;
        }

        $serial = $dayCache[$day];

        // Sin hora: 'YYYY-MM-DD'
        if (strlen($value) < 16) {
            return $serial;
        }

        $hour   = (int) substr($value, 11, 2);
        $minute = (int) substr($value, 14, 2);
        $second = strlen($value) >= 19 ? (int) substr($value, 17, 2) : 0;

        $fraction = ($hour * 3600 + $minute * 60 + $second) / 86400;

        // Medianoche exacta: se trata como fecha simple para que Excel no
        // muestre "00:00:00" en columnas que en realidad son solo fecha.
        return $fraction === 0.0 ? $serial : $serial + $fraction;
    }

    /**
     * Serial de Excel para 'YYYY-MM-DD'.
     *
     * Excel cuenta días desde 1899-12-30 y arrastra el falso 29/feb/1900, por
     * eso el desplazamiento fijo 25569 solo vale desde 1900-03-01. Antes de esa
     * fecha se devuelve null: Excel no las representa bien y es preferible
     * escribirlas como texto que mostrar un día corrido.
     */
    private static function daySerial(string $day): ?int
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $day, $m) !== 1) {
            return null;
        }

        $year  = (int) $m[1];
        $month = (int) $m[2];
        $dayNo = (int) $m[3];

        if ($month < 1 || $month > 12 || $dayNo < 1 || $dayNo > 31) {
            return null;
        }

        // Días desde 1970-01-01 (algoritmo days_from_civil de Howard Hinnant,
        // válido para el calendario gregoriano proléptico).
        $y   = $month <= 2 ? $year - 1 : $year;
        $era = intdiv($y >= 0 ? $y : $y - 399, 400);
        $yoe = $y - $era * 400;
        $doy = intdiv(153 * ($month + ($month > 2 ? -3 : 9)) + 2, 5) + $dayNo - 1;
        $doe = $yoe * 365 + intdiv($yoe, 4) - intdiv($yoe, 100) + $doy;

        $daysFromEpoch = $era * 146097 + $doe - 719468;

        // 25569 = días entre 1899-12-30 y 1970-01-01
        $serial = $daysFromEpoch + 25569;

        // 61 = 1900-03-01. Por debajo, el bug del año 1900 desalinea el serial.
        return $serial >= 61 ? $serial : null;
    }

    // =========================================================================
    // XML
    // =========================================================================

    /**
     * Deja el texto listo para meterse en el XML de la hoja: escapa < > & " '
     * y elimina cualquier codepoint que XML 1.0 PROHÍBE.
     *
     * Por qué no basta con htmlspecialchars: un dato como "MAL\u{FFFE}CHAR"
     * (que aparece en vistas heredadas, p. ej. gd.VW_Glosa_EstadisticoGlosas)
     * es UTF-8 válido, así que htmlspecialchars lo deja pasar, pero U+FFFE está
     * prohibido en XML 1.0. Excel entonces "repara" el archivo quitando la hoja
     * y muestra "Cargar error. Línea N". Lo mismo con los caracteres de control
     * \x00–\x1F (salvo tab, LF y CR), U+FFFF y los surrogates sueltos.
     *
     * Caracteres XML 1.0 válidos:
     *   \x09 \x0A \x0D | \x20–\uD7FF | \uE000–\uFFFD | \u10000–\u10FFFF
     *
     * Camino rápido: si la cadena es ASCII imprimible pura (el 99% de los
     * valores), se salta el regex y solo se escapa. El regex Unicode solo corre
     * cuando de verdad hay bytes altos o de control.
     */
    private static function xmlText(string $value): string
    {
        // El saneo de codepoints vive en ExportValueFormatter para que el camino
        // clásico (writeRow → CSV → OpenSpout) use exactamente la misma regla.
        return htmlspecialchars(
            ExportValueFormatter::xmlSafe($value),
            ENT_QUOTES | ENT_XML1,
            'UTF-8'
        );
    }

    /**
     * Cabecera del XML de la hoja: vista congelada, anchos y fila de títulos.
     *
     * El orden de los elementos lo fija el esquema (sheetViews → sheetFormatPr →
     * cols → sheetData → autoFilter); cambiarlo hace que Excel declare el
     * archivo dañado.
     *
     * @param list<string>      $headers
     * @param array<int,int>    $widths
     * @param array<int,string> $letters
     * @param string|null       $title  Título de portada; null = sin portada
     */
    private static function sheetProlog(array $headers, array $widths, array $letters, ?string $title): string
    {
        $hasCover  = $title !== null;
        $headerRow = $hasCover ? self::HEADER_ROW : 1;
        // La vista se congela justo debajo de la fila de encabezados.
        $freezeRow = $headerRow;
        $topLeft   = 'A' . ($headerRow + 1);
        $lastCol   = $letters[count($headers) - 1] ?? 'A';

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView tabSelected="1" workbookViewId="0">'
            . '<pane ySplit="' . $freezeRow . '" topLeftCell="' . $topLeft
            . '" activePane="bottomLeft" state="frozen"/>'
            . '</sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="15"/>';

        if ($widths !== []) {
            $xml .= '<cols>';
            foreach ($widths as $i => $len) {
                $width = max(9, min(50, $len + 3));
                $xml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1)
                    . '" width="' . $width . '" customWidth="1"/>';
            }
            $xml .= '</cols>';
        }

        $xml .= '<sheetData>';

        // Portada: título + línea de exportación (mismo aspecto que el camino
        // de PhpSpreadsheet para vistas chicas). Las celdas fusionadas se
        // declaran al final, en el epílogo.
        if ($hasCover) {
            $titleText = self::xmlText($title);
            $info      = 'Exportado: ' . now()->format('d/m/Y H:i');

            $xml .= '<row r="' . self::TITLE_ROW . '" ht="20" customHeight="1">'
                . '<c r="A' . self::TITLE_ROW . '" s="' . self::STYLE_TITLE . '" t="inlineStr">'
                . '<is><t>JadeOne — ' . $titleText . '</t></is></c></row>';

            $xml .= '<row r="' . self::INFO_ROW . '">'
                . '<c r="A' . self::INFO_ROW . '" s="' . self::STYLE_INFO . '" t="inlineStr">'
                . '<is><t>' . $info . '</t></is></c></row>';
        }

        // Fila de encabezados
        $xml .= '<row r="' . $headerRow . '" ht="22" customHeight="1">';

        foreach ($headers as $i => $header) {
            $ref  = ($letters[$i] ?? 'A') . $headerRow;
            $text = self::xmlText($header);

            $xml .= '<c r="' . $ref . '" s="' . self::STYLE_HEADER . '" t="inlineStr"><is><t>'
                . $text . '</t></is></c>';
        }

        return $xml . '</row>';
    }

    /**
     * Cierre del XML: celdas fusionadas de la portada + autofiltro sobre el rango.
     *
     * @param array<int,string> $letters
     */
    private static function sheetEpilog(int $colCount, int $lastRow, array $letters, bool $hasCover): string
    {
        $lastCol   = $letters[$colCount - 1] ?? 'A';
        $headerRow = $hasCover ? self::HEADER_ROW : 1;

        $xml = '</sheetData>';

        // El orden lo fija el esquema OOXML: mergeCells va antes que autoFilter.
        if ($hasCover) {
            $xml .= '<mergeCells count="2">'
                . '<mergeCell ref="A' . self::TITLE_ROW . ':' . $lastCol . self::TITLE_ROW . '"/>'
                . '<mergeCell ref="A' . self::INFO_ROW . ':' . $lastCol . self::INFO_ROW . '"/>'
                . '</mergeCells>';
        }

        return $xml
            . '<autoFilter ref="A' . $headerRow . ':' . $lastCol . $lastRow . '"/>'
            . '</worksheet>';
    }

    /**
     * @return array<int,string> Índice 0 → 'A', 25 → 'Z', 26 → 'AA'...
     */
    private static function columnLetters(int $count): array
    {
        $letters = [];

        for ($i = 0; $i < $count; $i++) {
            $name = '';
            $n    = $i + 1;

            while ($n > 0) {
                $rest = ($n - 1) % 26;
                $name = chr(65 + $rest) . $name;
                $n    = intdiv($n - 1, 26);
            }

            $letters[$i] = $name;
        }

        return $letters;
    }

    // =========================================================================
    // VALIDACIÓN Y EMPAQUETADO ZIP
    // =========================================================================

    /**
     * Escribe TODO el contenido, reintentando las escrituras parciales.
     *
     * fwrite() no garantiza escribir los bytes pedidos: con búferes grandes, o
     * si el sistema de archivos está bajo presión, devuelve menos. El código
     * anterior ignoraba el retorno, así que un flush parcial dejaba el XML
     * truncado y Excel reportaba "Cargar error. Línea N, columna 0" (columna 0 =
     * fin de archivo inesperado, no un carácter inválido).
     *
     * @param resource $handle
     */
    private static function writeAll($handle, string $data): bool
    {
        $length  = strlen($data);
        $written = 0;

        while ($written < $length) {
            $chunk = fwrite($handle, substr($data, $written));

            // false = error de I/O; 0 = no se pudo avanzar (disco lleno)
            if ($chunk === false || $chunk === 0) {
                return false;
            }

            $written += $chunk;
        }

        return true;
    }

    /**
     * ¿El XML de la hoja es bien formado? Se recorre en streaming con XMLReader
     * (no carga los cientos de MB en RAM). Si algo quedó mal, registra la línea
     * y columna exactas para poder identificar el dato que lo causó.
     */
    private static function sheetXmlIsValid(string $sheetXmlPath, string $sheetName): bool
    {
        // Chequeo barato de truncamiento antes del recorrido completo: el archivo
        // debe terminar en </worksheet>. Detecta al instante el caso de XML
        // cortado sin tener que parsear cientos de MB.
        $size = @filesize($sheetXmlPath);
        if ($size === false || $size < 100) {
            Log::error('[FastXlsxWriter] XML de la hoja vacio o inexistente', [
                'sheet' => $sheetName,
                'bytes' => $size,
            ]);

            return false;
        }

        $tail = '';
        $fh   = @fopen($sheetXmlPath, 'rb');
        if ($fh !== false) {
            fseek($fh, -32, SEEK_END);
            $tail = (string) fread($fh, 32);
            fclose($fh);
        }

        if (!str_contains($tail, '</worksheet>')) {
            Log::error('[FastXlsxWriter] XML de la hoja TRUNCADO (no cierra en </worksheet>)', [
                'sheet' => $sheetName,
                'bytes' => $size,
                'tail'  => substr($tail, -40),
            ]);

            return false;
        }

        $prev = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $reader = new \XMLReader();
        if ($reader->open($sheetXmlPath) === false) {
            libxml_use_internal_errors($prev);

            return false;
        }

        $valid = true;
        try {
            while ($reader->read()) {
                // recorrer todo el árbol; cualquier char ilegal lanza error libxml
            }
        } catch (\Throwable) {
            $valid = false;
        }

        $errors = libxml_get_errors();
        $reader->close();
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if ($errors !== []) {
            $first = $errors[0];
            Log::error('[FastXlsxWriter] XML de la hoja invalido, se cae a CSV', [
                'sheet'   => $sheetName,
                'line'    => $first->line,
                'column'  => $first->column,
                'code'    => $first->code,
                'message' => trim($first->message),
            ]);

            return false;
        }

        return $valid;
    }

    /**
     * Arma el .xlsx (un ZIP con los XMLs del formato OOXML mínimo).
     */
    private static function package(string $sheetXml, string $xlsxPath, string $sheetName): bool
    {
        $zip = new ZipArchive();

        if ($zip->open($xlsxPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            Log::error('[FastXlsxWriter] no se pudo crear el zip', ['path' => $xlsxPath]);

            return false;
        }

        $zip->addFromString('[Content_Types].xml', self::contentTypesXml());
        $zip->addFromString('_rels/.rels', self::rootRelsXml());
        $zip->addFromString('xl/workbook.xml', self::workbookXml($sheetName));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRelsXml());
        $zip->addFromString('xl/styles.xml', self::stylesXml());
        $zip->addFile($sheetXml, 'xl/worksheets/sheet1.xml');

        // Nivel bajo a propósito: la hoja puede pesar cientos de MB y comprimir
        // al máximo multiplica el tiempo por 6 para ahorrar unos pocos MB.
        $zip->setCompressionName('xl/worksheets/sheet1.xml', ZipArchive::CM_DEFLATE, self::ZIP_LEVEL);

        return $zip->close();
    }

    private static function contentTypesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    private static function rootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private static function workbookXml(string $sheetName): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . self::sheetName($sheetName) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private static function workbookRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    /**
     * styles.xml con los estilos que usa la hoja.
     *
     * Excel exige que fills 0 sea "none" y fills 1 sea "gray125": si faltan,
     * declara el archivo dañado aunque no se usen.
     *
     * fonts:   0 normal · 1 encabezado (blanco, negrita) · 2 título (grande, negrita) · 3 info (gris, cursiva)
     * cellXfs: 0 general · 1 encabezado · 2 fecha · 3 fecha y hora · 4 título · 5 info
     */
    private static function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="2">'
            . '<numFmt numFmtId="164" formatCode="yyyy\-mm\-dd"/>'
            . '<numFmt numFmtId="165" formatCode="yyyy\-mm\-dd\ hh:mm:ss"/>'
            . '</numFmts>'
            . '<fonts count="4">'
            . '<font><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/><family val="2"/></font>'
            . '<font><b/><sz val="14"/><color rgb="FF1B3A5C"/><name val="Calibri"/><family val="2"/></font>'
            . '<font><i/><sz val="9"/><color rgb="FF808080"/><name val="Calibri"/><family val="2"/></font>'
            . '</fonts>'
            . '<fills count="3">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF1B3A5C"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="6">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1">'
            . '<alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1">'
            . '<alignment horizontal="left" vertical="center"/></xf>'
            . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    /**
     * Nombre de hoja válido: sin caracteres prohibidos, máximo 31 caracteres.
     */
    private static function sheetName(string $name): string
    {
        $clean = str_replace(['\\', '/', '?', '*', '[', ']', ':'], '', $name);
        $clean = trim(mb_substr($clean, 0, 31));

        if ($clean === '') {
            $clean = 'Datos';
        }

        return htmlspecialchars($clean, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
