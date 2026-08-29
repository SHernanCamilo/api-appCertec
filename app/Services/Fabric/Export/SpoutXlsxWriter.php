<?php

declare(strict_types=1);

namespace App\Services\Fabric\Export;

use Illuminate\Support\Facades\Log;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\CellVerticalAlignment;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use ZipArchive;

/**
 * Escritor de .xlsx para datasets grandes, sobre OpenSpout.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * POR QUÉ EXISTE (y por qué reemplaza al writer de XML a mano)
 * ─────────────────────────────────────────────────────────────────────────────
 * Antes armábamos el XML de la hoja por concatenación de strings (FastXlsxWriter).
 * Era rápido, pero un .xlsx es un ZIP con XMLs bajo reglas estrictas, y ese
 * enfoque fue acumulando fallas difíciles de cazar:
 *
 *   - Caracteres que XML 1.0 prohíbe y que Excel rechaza (U+FFFE, controles).
 *   - fwrite() con escrituras parciales que truncaban el XML.
 *   - El empaquetado ZIP de una hoja de cientos de MB.
 *
 * Cada una se arregló, pero el patrón era claro: mantener un generador de OOXML
 * propio es una fuente constante de bugs. OpenSpout es una librería mantenida y
 * probada en producción que ya resuelve el ZIP, el escapado y el streaming.
 * Escribe con RAM fija, así que sirve igual para 500K filas.
 *
 * LO QUE SÍ SEGUIMOS HACIENDO NOSOTROS (OpenSpout no lo hace):
 *   1. Sanear los codepoints que XML prohíbe → ExportValueFormatter::xmlSafe().
 *      OpenSpout escapa < > & pero NO filtra los caracteres ilegales; si uno
 *      llega a la celda, Excel abre el archivo "reparando".
 *   2. Recuperar filas con NDJSON malformado → decodeNdjsonLine().
 *   3. Decidir el tipo de celda (fecha real, número, texto con ceros iniciales).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * FORMATO QUE PRODUCE
 * ─────────────────────────────────────────────────────────────────────────────
 * Portada (título + fecha de exportación), encabezado azul corporativo congelado
 * al hacer scroll, autofiltro, anchos estimados, fechas como fecha real de Excel,
 * números sumables y ceros iniciales preservados en NIT/placas/documentos.
 *
 * @see StreamingExportWriter::fromNdjsonGzFile() punto de entrada que elige el camino
 */
final class SpoutXlsxWriter
{
    /** Marca de versión, para verificar en el log qué código corre en el server. */
    private const BUILD = '2026-08-29-spout';

    /** Límite físico de filas de una hoja de Excel. */
    public const EXCEL_MAX_ROWS = 1048576;

    /** Filas que se leen al inicio para deducir columnas, tipos y anchos. */
    private const SAMPLE_ROWS = 200;

    /** Azul corporativo del encabezado. */
    private const HEADER_BG = '1B3A5C';

    /**
     * Genera el .xlsx a partir de un NDJSON gzipeado.
     *
     * @param  string      $gzPath    Archivo .ndjson.gz (una fila JSON por línea)
     * @param  string      $targetDir Directorio donde se escribe el .xlsx
     * @param  string      $baseName  Nombre del archivo sin extensión
     * @param  string      $sheetName Nombre de la hoja (se sanea a reglas de Excel)
     * @param  string|null $title     Título de portada; null = sin portada
     * @return ExportResult|null      Null si no es apto (vacío, excede filas, o el
     *                                archivo resultante no valida)
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
            return null;
        }

        /** @var list<string> $headers */
        $headers = $layout['headers'];
        /** @var array<int,string> $types */
        $types = $layout['types'];
        /** @var array<int,int> $widths */
        $widths = $layout['widths'];

        $colCount = count($headers);
        $xlsxPath = "{$targetDir}/{$baseName}.xlsx";
        $tempDir  = "{$targetDir}/spout_temp_" . getmypid();

        if (!is_dir($tempDir) && !mkdir($tempDir, 0775, true) && !is_dir($tempDir)) {
            Log::error('[SpoutXlsxWriter] no se pudo crear el directorio temporal', ['dir' => $tempDir]);

            return null;
        }

        $options = new Options();
        // Inline strings: no acumula una tabla de strings compartidos en RAM.
        $options->SHOULD_USE_INLINE_STRINGS = true;
        $options->setTempFolder($tempDir);

        // Anchos de columna (OpenSpout los aplica al ensamblar la hoja)
        foreach ($widths as $i => $len) {
            $options->setColumnWidth((float) max(9, min(60, $len + 3)), $i + 1);
        }

        $writer   = new Writer($options);
        $dataRows = 0;
        $gz       = null;

        try {
            $writer->openToFile($xlsxPath);

            $sheet = $writer->getCurrentSheet();
            $sheet->setName(self::sheetName($sheetName));

            $headerRow = $title !== null ? 3 : 1;
            $sheet->setSheetView((new SheetView())->setFreezeRow($headerRow + 1));

            // ── Portada ──────────────────────────────────────────────────────
            if ($title !== null) {
                $writer->addRow(Row::fromValues(
                    [ExportValueFormatter::xmlSafe("JadeOne — {$title}")],
                    self::titleStyle()
                ));
                $writer->addRow(Row::fromValues(
                    ['Exportado: ' . now()->format('d/m/Y H:i')],
                    self::infoStyle()
                ));
            }

            // ── Encabezados ──────────────────────────────────────────────────
            $writer->addRow(Row::fromValues(
                array_map([ExportValueFormatter::class, 'xmlSafe'], $headers),
                self::headerStyle()
            ));

            // Estilos de fecha instanciados una sola vez
            $dateStyle     = (new Style())->setFormat('yyyy-mm-dd');
            $dateTimeStyle = (new Style())->setFormat('yyyy-mm-dd hh:mm:ss');

            // ── Datos ────────────────────────────────────────────────────────
            $gz = gzopen($gzPath, 'rb');
            if ($gz === false) {
                $writer->close();
                self::cleanup($tempDir, $xlsxPath);

                return null;
            }

            /** @var array<string,int> Memo fecha → serial de Excel */
            $dayCache  = [];
            $truncated = false;

            while (($line = gzgets($gz)) !== false) {
                $row = ExportValueFormatter::decodeNdjsonLine($line);
                if ($row === null) {
                    continue;
                }

                if (($dataRows + $headerRow + 1) >= self::EXCEL_MAX_ROWS) {
                    $truncated = true;
                    break;
                }

                $cells = [];

                foreach ($headers as $i => $header) {
                    $value = $row[$header] ?? null;

                    if ($value === null || $value === '') {
                        $cells[] = StringCell::fromValue('');
                        continue;
                    }

                    $type = $types[$i] ?? 'text';

                    if ($type === 'date') {
                        $serial = self::excelSerial((string) $value, $dayCache);
                        if ($serial !== null) {
                            $cells[] = NumericCell::fromValue(
                                $serial,
                                is_float($serial) ? $dateTimeStyle : $dateStyle
                            );
                            continue;
                        }
                    } elseif ($type === 'num') {
                        if (is_int($value) || is_float($value)) {
                            $cells[] = NumericCell::fromValue($value);
                            continue;
                        }
                        if (ExportValueFormatter::isSafeExcelNumber($value)) {
                            $cells[] = NumericCell::fromValue((float) $value);
                            continue;
                        }
                    }

                    // Texto. xmlSafe es imprescindible: OpenSpout escapa < > &
                    // pero NO quita los caracteres que XML prohíbe.
                    $cells[] = StringCell::fromValue(
                        ExportValueFormatter::xmlSafe((string) $value)
                    );
                }

                $writer->addRow(new Row($cells));
                $dataRows++;
            }

            gzclose($gz);
            $gz = null;

            if ($truncated) {
                $writer->close();
                self::cleanup($tempDir, $xlsxPath);

                Log::warning('[SpoutXlsxWriter] dataset excede el limite de filas de Excel', [
                    'sheet' => $sheetName,
                    'limit' => self::EXCEL_MAX_ROWS,
                ]);

                return null;
            }

            if ($dataRows === 0) {
                $writer->close();
                self::cleanup($tempDir, $xlsxPath);

                return null;
            }

            // Autofiltro sobre el rango de datos (antes de cerrar: OpenSpout
            // escribe la cabecera del sheet al ensamblar el archivo).
            if ($colCount > 0) {
                $sheet->setAutoFilter(
                    new \OpenSpout\Writer\AutoFilter(0, $headerRow, $colCount - 1, $headerRow + $dataRows)
                );
            }

            $writer->close();
        } catch (\Throwable $e) {
            if ($gz !== null) {
                gzclose($gz);
            }
            try {
                $writer->close();
            } catch (\Throwable) {
                // ya estaba cerrado
            }
            self::cleanup($tempDir, $xlsxPath);

            Log::error('[SpoutXlsxWriter] excepcion al generar el xlsx', [
                'sheet' => $sheetName,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        self::rmdirSafe($tempDir);

        if (!is_file($xlsxPath)) {
            return null;
        }

        // Validación final: abrir el .xlsx y leer la hoja DEL ZIP, igual que
        // hará Excel. Si no se puede leer, se descarta y el llamador cae a CSV.
        if (!self::packagedSheetIsReadable($xlsxPath, $sheetName)) {
            @unlink($xlsxPath);

            return null;
        }

        $bytes = (int) filesize($xlsxPath);

        Log::info('[SpoutXlsxWriter] xlsx OK', [
            'build' => self::BUILD,
            'sheet' => $sheetName,
            'rows'  => $dataRows,
            'cols'  => $colCount,
            'bytes' => $bytes,
        ]);

        return new ExportResult(
            path: $xlsxPath,
            filename: basename($xlsxPath),
            format: 'xlsx',
            rows: $dataRows,
            bytes: $bytes,
        );
    }

    // =========================================================================
    // MUESTREO
    // =========================================================================

    /**
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
            $row = ExportValueFormatter::decodeNdjsonLine($line);
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
     * Tipo por columna: 'date' | 'num' | 'text'. Se decide una vez con la
     * muestra para no evaluar expresiones regulares por celda.
     *
     * @param  list<string>      $headers
     * @param  list<list<mixed>> $sample
     * @return array<int,string>
     */
    private static function inferTypes(array $headers, array $sample): array
    {
        $textColumns = ExportValueFormatter::detectTextColumns($headers, $sample[0] ?? null);
        $types       = [];

        foreach ($headers as $i => $_header) {
            if (isset($textColumns[$i])) {
                $types[$i] = 'text';
                continue;
            }

            $seen = $dates = $nums = 0;

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
                $seen === 0            => 'text',
                $dates / $seen >= 0.8  => 'date',
                $nums / $seen >= 0.8   => 'num',
                default                => 'text',
            };
        }

        return $types;
    }

    /**
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
     * 'YYYY-MM-DD[ HH:MM:SS]' → serial de Excel, sin crear objetos DateTime.
     * Devuelve int si es solo fecha, float si trae hora, null si no es fecha.
     *
     * @param  array<string,int> $dayCache Memo por día, pasado por referencia
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

        if (strlen($value) < 16) {
            return $serial;
        }

        $seconds = ((int) substr($value, 11, 2)) * 3600
            + ((int) substr($value, 14, 2)) * 60
            + (strlen($value) >= 19 ? (int) substr($value, 17, 2) : 0);

        return $seconds === 0 ? $serial : $serial + ($seconds / 86400);
    }

    /**
     * Serial de Excel para 'YYYY-MM-DD'. Excel cuenta desde 1899-12-30 y
     * arrastra el falso 29/feb/1900, por eso solo vale desde 1900-03-01.
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

        // days_from_civil (Howard Hinnant)
        $y   = $month <= 2 ? $year - 1 : $year;
        $era = intdiv($y >= 0 ? $y : $y - 399, 400);
        $yoe = $y - $era * 400;
        $doy = intdiv(153 * ($month + ($month > 2 ? -3 : 9)) + 2, 5) + $dayNo - 1;
        $doe = $yoe * 365 + intdiv($yoe, 4) - intdiv($yoe, 100) + $doy;

        $serial = $era * 146097 + $doe - 719468 + 25569;

        return $serial >= 61 ? $serial : null;
    }

    // =========================================================================
    // ESTILOS
    // =========================================================================

    private static function headerStyle(): Style
    {
        return (new Style())
            ->setFontBold()
            ->setFontSize(11)
            ->setFontColor(Color::WHITE)
            ->setBackgroundColor(self::HEADER_BG)
            ->setCellAlignment(CellAlignment::CENTER)
            ->setCellVerticalAlignment(CellVerticalAlignment::CENTER)
            ->setShouldWrapText(true);
    }

    private static function titleStyle(): Style
    {
        return (new Style())
            ->setFontBold()
            ->setFontSize(14)
            ->setFontColor(self::HEADER_BG);
    }

    private static function infoStyle(): Style
    {
        return (new Style())
            ->setFontItalic()
            ->setFontSize(9)
            ->setFontColor('808080');
    }

    // =========================================================================
    // VALIDACIÓN Y LIMPIEZA
    // =========================================================================

    /**
     * Abre el .xlsx y parsea la hoja leyéndola DEL ZIP, igual que hace Excel.
     * Es la única comprobación que reproduce lo que hará el usuario al abrirlo.
     */
    private static function packagedSheetIsReadable(string $xlsxPath, string $sheetName): bool
    {
        $zip = new ZipArchive();
        if ($zip->open($xlsxPath) !== true) {
            Log::error('[SpoutXlsxWriter] el xlsx final no abre como ZIP', ['sheet' => $sheetName]);

            return false;
        }

        if ($zip->locateName('xl/worksheets/sheet1.xml') === false) {
            $zip->close();
            Log::error('[SpoutXlsxWriter] el xlsx final no contiene la hoja', ['sheet' => $sheetName]);

            return false;
        }

        $stream = $zip->getStream('xl/worksheets/sheet1.xml');
        if ($stream === false) {
            $zip->close();
            Log::error('[SpoutXlsxWriter] no se pudo leer la hoja del ZIP', ['sheet' => $sheetName]);

            return false;
        }

        $parser = xml_parser_create('UTF-8');
        $ok     = true;

        while (!feof($stream)) {
            $chunk = fread($stream, 262144);
            if ($chunk === false) {
                $ok = false;
                break;
            }
            if (!xml_parse($parser, $chunk, feof($stream))) {
                Log::error('[SpoutXlsxWriter] la hoja del ZIP tiene XML invalido', [
                    'sheet'  => $sheetName,
                    'line'   => xml_get_current_line_number($parser),
                    'column' => xml_get_current_column_number($parser),
                    'error'  => xml_error_string(xml_get_error_code($parser)),
                ]);
                $ok = false;
                break;
            }
        }

        xml_parser_free($parser);
        fclose($stream);
        $zip->close();

        return $ok;
    }

    private static function cleanup(string $tempDir, string $xlsxPath): void
    {
        @unlink($xlsxPath);
        self::rmdirSafe($tempDir);
    }

    private static function rmdirSafe(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }

    /** Nombre de hoja válido: sin caracteres prohibidos, máximo 31 caracteres. */
    private static function sheetName(string $name): string
    {
        $clean = str_replace(['\\', '/', '?', '*', '[', ']', ':'], '', $name);
        $clean = trim(mb_substr($clean, 0, 31));

        return $clean !== '' ? $clean : 'Datos';
    }
}
