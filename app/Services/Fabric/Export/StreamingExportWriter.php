<?php

declare(strict_types=1);

namespace App\Services\Fabric\Export;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/**
 * Escritor de exports en UNA SOLA PASADA sobre los datos.
 *
 * PROBLEMA QUE RESUELVE:
 *   La versión anterior hacía tres pasadas completas sobre el mismo dataset:
 *     Python → r2_data.gz  (escritura 1)
 *     gz     → data.tmp    (lectura 1 + escritura 2, con json_encode por fila)
 *     tmp    → archivo.csv (lectura 2 + escritura 3, con json_decode por fila)
 *
 *   Para 800K filas eso son ~3.5 GB de I/O y 1.6 millones de operaciones
 *   json_encode/json_decode que no aportan nada: el archivo intermedio existía
 *   solo porque no se sabía el total de filas antes de empezar a escribir.
 *
 * CÓMO LO RESUELVE:
 *   Se acumulan en memoria las primeras XLSX_THRESHOLD filas. Si el dataset
 *   termina ahí, se genera un xlsx con formato corporativo (PhpSpreadsheet).
 *   Si se supera el umbral, el búfer se vuelca a CSV y el resto de las filas
 *   se escriben directo al disco a medida que llegan. Sin archivo intermedio.
 *
 *   El búfer acota la memoria: 20K filas es el mismo volumen que PhpSpreadsheet
 *   necesitaría cargar de todas formas para ese caso.
 *
 * USO:
 *   $writer = new StreamingExportWriter($dir, $baseName, $schema, $view);
 *   foreach ($rows as $row) { $writer->writeRow($row); }
 *   $result = $writer->finish();
 */
final class StreamingExportWriter
{
    /** Hasta este número de filas se genera xlsx con PhpSpreadsheet (con formato
     *  corporativo: header azul, zebra, autofit). Por encima se usa OpenSpout
     *  streaming (sin buffer en RAM). En ambos casos sale como .xlsx.
     *  Solo por encima del límite de Excel (1,048,576) se cae a CSV. */
    private const XLSX_THRESHOLD = 50000;

    /**
     * Máximo de CELDAS (filas × columnas) que se envían al camino de
     * PhpSpreadsheet, el que agrega portada, zebra y autofit.
     *
     * El límite es por celdas y no por filas porque el costo de PhpSpreadsheet
     * depende del total de celdas, y crece peor que lineal. Medido en local
     * (PHP 8.2, 57 columnas):
     *
     *      5.000 filas →   285K celdas →  28 s · 272 MB de RAM
     *     20.000 filas → 1.140K celdas → 272 s · 960 MB de RAM   ← OOM con 512M
     *
     * Con FastXlsxWriter esos mismos 20.000 × 57 tardan 1.4 s y 12 MB. Por eso
     * el camino rico queda reservado a exports realmente chicos, donde su costo
     * es de un segundo y el formato extra sí se aprecia.
     */
    private const RICH_MAX_CELLS = 40000;

    /** Cada cuántas filas se invoca el callback de progreso. */
    private const PROGRESS_EVERY = 50000;

    /** @var list<string> Nombres de columna, derivados de la primera fila. */
    private array $headers = [];

    /** @var array<int, true> Índices de columna que se escriben como texto. */
    private array $textColumns = [];

    /** @var array<int, true> Índices de columna con formato de fecha (solo xlsx). */
    private array $dateColumns = [];

    /** @var list<list<mixed>> Búfer de filas mientras no se supera el umbral. */
    private array $buffer = [];

    /** Handle del CSV, abierto solo cuando se supera el umbral. */
    private mixed $csvHandle = null;

    private int $rowCount = 0;

    private bool $finished = false;

    /** @var (callable(int): void)|null */
    private $onProgress = null;

    public function __construct(
        private readonly string $targetDir,
        private readonly string $baseName,
        private readonly string $schema,
        private readonly string $view,
    ) {
        if (!is_dir($this->targetDir) && !mkdir($this->targetDir, 0775, true) && !is_dir($this->targetDir)) {
            throw new RuntimeException("No se pudo crear el directorio de export: {$this->targetDir}");
        }
    }

    /**
     * Registra un callback que recibe el número de filas escritas.
     * Se invoca cada PROGRESS_EVERY filas para reportar avance.
     *
     * @param callable(int): void $callback
     */
    public function onProgress(callable $callback): void
    {
        $this->onProgress = $callback;
    }

    /**
     * Escribe una fila. La primera fila define las columnas.
     *
     * @param array<string|int, mixed> $row Fila asociativa como la devuelve Fabric
     */
    public function writeRow(array $row): void
    {
        if ($this->finished) {
            throw new RuntimeException('El writer ya fue finalizado.');
        }

        if ($this->headers === []) {
            $this->initializeFromFirstRow($row);
        }

        $values = $this->extractValues($row);

        if ($this->csvHandle !== null) {
            $this->writeCsvRow($values);
        } else {
            $this->buffer[] = $values;

            // Al superar el umbral dejamos de acumular y pasamos a modo streaming
            if (count($this->buffer) > self::XLSX_THRESHOLD) {
                $this->switchToCsv();
            }
        }

        $this->rowCount++;

        if ($this->onProgress !== null && $this->rowCount % self::PROGRESS_EVERY === 0) {
            ($this->onProgress)($this->rowCount);
        }
    }

    /**
     * Cierra el archivo y devuelve la ruta, formato y tamaño resultantes.
     */
    public function finish(): ExportResult
    {
        if ($this->finished) {
            throw new RuntimeException('El writer ya fue finalizado.');
        }
        $this->finished = true;

        if ($this->rowCount === 0) {
            return new ExportResult('', '', 'csv', 0, 0);
        }

        if ($this->csvHandle !== null) {
            fclose($this->csvHandle);
            $this->csvHandle = null;

            // Convertir el CSV a xlsx con OpenSpout (streaming, sin cargar en RAM)
            // Esto elimina el CSV crudo que confunde a los usuarios.
            $baseName = pathinfo($this->csvPath(), PATHINFO_FILENAME);
            $result = self::fromCsvFile($this->csvPath(), $this->targetDir, $baseName, $this->schema, $this->view);

            // Si la conversión falló por algún motivo, devolver el CSV como fallback
            if ($result->isEmpty()) {
                return $this->buildResult($this->csvPath(), 'csv');
            }

            return $result;
        }

        // Dataset pequeño: xlsx con formato corporativo
        $this->writeXlsx();

        return $this->buildResult($this->xlsxPath(), 'xlsx');
    }

    /**
     * Libera recursos si el job muere a mitad de camino.
     */
    public function abort(): void
    {
        if ($this->csvHandle !== null) {
            fclose($this->csvHandle);
            $this->csvHandle = null;
            @unlink($this->csvPath());
        }
        $this->buffer   = [];
        $this->finished = true;
    }

    public function rowCount(): int
    {
        return $this->rowCount;
    }

    // =========================================================================
    // INICIALIZACIÓN
    // =========================================================================

    /**
     * Deriva columnas y detecta tipos a partir de la primera fila.
     *
     * @param array<string|int, mixed> $row
     */
    private function initializeFromFirstRow(array $row): void
    {
        // strval es obligatorio: las vistas pivot tienen columnas con nombre
        // numérico ("315", "051") y array_keys las devuelve como int.
        $this->headers = array_map('strval', array_keys($row));

        $firstValues = array_values($row);

        $this->textColumns = ExportValueFormatter::detectTextColumns($this->headers, $firstValues);

        // Detectar columnas de fecha/datetime por nombre Y por contenido de la primera fila
        foreach ($this->headers as $index => $header) {
            $headerLower = strtolower($header);

            // Por nombre: columnas que típicamente contienen fechas
            $isDateByName = str_contains($headerLower, 'fecha')
                || str_contains($headerLower, 'date')
                || str_contains($headerLower, 'fec_')
                || str_ends_with($headerLower, '_at')
                || str_starts_with($headerLower, 'dt_');

            // Por contenido de la primera fila
            $value = $firstValues[$index] ?? null;
            $isDateByValue = ExportValueFormatter::looksLikeIsoDate($value)
                || ExportValueFormatter::looksLikeDateOnly($value);

            if ($isDateByName || $isDateByValue) {
                $this->dateColumns[$index] = true;
            }
        }
    }

    /**
     * Alinea la fila al orden de los headers y limpia los valores.
     *
     * @param  array<string|int, mixed> $row
     * @return list<mixed>
     */
    private function extractValues(array $row): array
    {
        $values = [];

        foreach ($this->headers as $header) {
            $values[] = ExportValueFormatter::sanitize($row[$header] ?? '');
        }

        return $values;
    }

    // =========================================================================
    // CSV (datasets grandes)
    // =========================================================================

    /**
     * Abre el CSV, escribe la cabecera y vuelca el búfer acumulado.
     */
    private function switchToCsv(): void
    {
        $handle = fopen($this->csvPath(), 'w');
        if ($handle === false) {
            throw new RuntimeException('No se pudo abrir el archivo CSV de export.');
        }

        // BOM UTF-8 para que Excel respete los acentos
        fwrite($handle, "\xEF\xBB\xBF");
        fwrite($handle, $this->encodeCsvLine($this->headers));

        $this->csvHandle = $handle;

        foreach ($this->buffer as $bufferedRow) {
            $this->writeCsvRow($bufferedRow);
        }

        // Liberar la memoria del búfer: ya está en disco
        $this->buffer = [];
    }

    /**
     * @param list<mixed> $values
     */
    private function writeCsvRow(array $values): void
    {
        $formatted = [];

        foreach ($values as $index => $value) {
            // Normalizar fechas ISO: quitar la T y milisegundos
            if (isset($this->dateColumns[$index]) && $value !== null && $value !== '') {
                $value = ExportValueFormatter::normalizeDate($value);
            }

            $formatted[] = ExportValueFormatter::forCsv($value, isset($this->textColumns[$index]));
        }

        fwrite($this->csvHandle, $this->encodeCsvLine($formatted));
    }

    /**
     * Serializa una fila a CSV con separador coma (universal).
     *
     * No se usa fputcsv() a propósito: fputcsv escaparía las comillas de la
     * fórmula ="036004835" convirtiéndola en "=""036004835""", y Excel dejaría
     * de interpretarla como texto, perdiendo el cero inicial. Aquí las fórmulas
     * de protección se escriben literales y solo se entrecomilla lo que de
     * verdad necesita escape.
     *
     * @param list<mixed> $values
     */
    private function encodeCsvLine(array $values): string
    {
        $fields = [];

        foreach ($values as $value) {
            $fields[] = $this->encodeCsvField($value);
        }

        return implode(',', $fields) . "\r\n";
    }

    private function encodeCsvField(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $asString = is_bool($value) ? ($value ? '1' : '0') : (string) $value;

        // Fórmula de protección de ceros iniciales: se escribe tal cual para que
        // Excel la evalúe. sanitize() ya garantizó que no hay saltos de línea.
        if (str_starts_with($asString, '="') && str_ends_with($asString, '"')) {
            return $asString;
        }

        if (
            str_contains($asString, ',')
            || str_contains($asString, '"')
            || str_contains($asString, "\n")
            || str_contains($asString, "\r")
        ) {
            return '"' . str_replace('"', '""', $asString) . '"';
        }

        return $asString;
    }

    // =========================================================================
    // XLSX (datasets pequeños, con formato corporativo)
    // =========================================================================

    private function writeXlsx(): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle(substr($this->view, 0, 31));

        $lastCol = Coordinate::stringFromColumnIndex(count($this->headers));

        $this->writeXlsxHeader($sheet, $lastCol);
        $this->applyTextColumnFormat($sheet);

        $rowNumber = 5;
        foreach ($this->buffer as $values) {
            $this->writeXlsxRow($sheet, $rowNumber, $values);
            $rowNumber++;
        }

        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);
        $writer->save($this->xlsxPath());

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        $this->buffer = [];
    }

    private function writeXlsxHeader(Worksheet $sheet, string $lastCol): void
    {
        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->setCellValue('A1', "JadeOne — {$this->schema}.{$this->view}");
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(12);

        $sheet->mergeCells("A2:{$lastCol}2");
        $sheet->setCellValue(
            'A2',
            'Exportado: ' . now()->format('d/m/Y H:i') . ' | Registros: ' . number_format($this->rowCount)
        );
        $sheet->getStyle('A2')->getFont()->setItalic(true)->setSize(9);

        foreach ($this->headers as $index => $header) {
            $col = Coordinate::stringFromColumnIndex($index + 1);
            $sheet->setCellValue("{$col}4", $header);
        }

        $sheet->getStyle("A4:{$lastCol}4")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => '1B3A5C']],
        ]);
        $sheet->setAutoFilter("A4:{$lastCol}4");
        $sheet->freezePane('A5');
    }

    /**
     * Marca las columnas de texto con formato TEXT para que Excel no reinterprete
     * los ceros iniciales al abrir el archivo.
     */
    private function applyTextColumnFormat(Worksheet $sheet): void
    {
        $lastRow = $this->rowCount + 4;

        foreach (array_keys($this->textColumns) as $index) {
            $col = Coordinate::stringFromColumnIndex($index + 1);
            $sheet->getStyle("{$col}5:{$col}{$lastRow}")
                ->getNumberFormat()
                ->setFormatCode(NumberFormat::FORMAT_TEXT);
        }
    }

    /**
     * @param list<mixed> $values
     */
    private function writeXlsxRow(Worksheet $sheet, int $rowNumber, array $values): void
    {
        foreach ($values as $index => $value) {
            $col  = Coordinate::stringFromColumnIndex($index + 1);
            $cell = "{$col}{$rowNumber}";

            // Las columnas de texto se escriben explícitamente como string para
            // que PhpSpreadsheet no las convierta a número.
            if (isset($this->textColumns[$index]) && $value !== null && $value !== '') {
                $sheet->setCellValueExplicit(
                    $cell,
                    (string) $value,
                    \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
                );
                continue;
            }

            // Columnas de fecha: convertir ISO a serial de Excel + formato
            if (isset($this->dateColumns[$index]) && $value !== null && $value !== '') {
                $serial = ExportValueFormatter::toExcelSerial($value);
                if ($serial !== null) {
                    $sheet->setCellValue($cell, $serial);

                    // Formato según si tiene hora o solo fecha
                    $hasTime = is_string($value) && preg_match('/\d{2}:\d{2}/', $value);
                    $fmt = $hasTime ? 'dd/mm/yyyy hh:mm:ss' : 'dd/mm/yyyy';
                    $sheet->getStyle($cell)->getNumberFormat()->setFormatCode($fmt);
                    continue;
                }
            }

            // Números de más de 15 dígitos: PhpSpreadsheet los convertiría a
            // float y saldrían como 6,00621E+36 o INF. Van como texto.
            if (
                is_string($value) && $value !== '' && is_numeric($value)
                && !ExportValueFormatter::isSafeExcelNumber($value)
            ) {
                $sheet->setCellValueExplicit(
                    $cell,
                    $value,
                    \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
                );
                continue;
            }

            $sheet->setCellValue($cell, $value);
        }
    }

    // =========================================================================
    // RUTAS Y RESULTADO
    // =========================================================================

    private function csvPath(): string
    {
        return "{$this->targetDir}/{$this->baseName}.csv";
    }

    private function xlsxPath(): string
    {
        return "{$this->targetDir}/{$this->baseName}.xlsx";
    }

    private function buildResult(string $path, string $format): ExportResult
    {
        $bytes = is_file($path) ? (int) filesize($path) : 0;

        return new ExportResult(
            path: $path,
            filename: basename($path),
            format: $format,
            rows: $this->rowCount,
            bytes: $bytes,
        );
    }

    // =========================================================================
    // EXPORT DESDE CSV DE R2 (sin parsear NDJSON)
    // =========================================================================

    /**
     * Genera un archivo xlsx desde un CSV en disco.
     *
     * Siempre genera xlsx con formato profesional (filtros, autofit, header azul).
     * OpenSpout escribe en streaming (RAM fija ~5 MB) hasta el límite de Excel
     * (1,048,576 filas). Solo excediendo ese límite se entrega el CSV crudo.
     *
     * Los CSV sin formato confundían a los usuarios: los campos con comas
     * internas (notas médicas, descripciones) rompían la separación de columnas.
     */
    /**
     * Construye el .xlsx a partir de un archivo NDJSON gzipeado (formato que
     * devuelve el export async de Graph-Fabric).
     *
     * Hay dos caminos y el tamaño estimado en celdas decide cuál:
     *
     *   grande  → FastXlsxWriter: escribe el XML de la hoja directamente. Es
     *             ~20x más rápido porque no crea un objeto Cell por celda
     *             (567K × 57 = 32 millones de objetos). Conserva encabezado
     *             azul, autofiltro, anchos, fechas y números.
     *
     *   chico   → camino clásico con PhpSpreadsheet, que agrega portada, zebra
     *             y autofit. Solo por debajo de RICH_MAX_CELLS, donde ese
     *             formato extra cuesta ~1 s en vez de minutos.
     *
     * El tamaño sale de $rowHint (el total que reporta Graph en X-Total-Rows)
     * multiplicado por las columnas de la primera fila. Si no se sabe el total
     * se asume grande: equivocarse hacia el camino rápido cuesta un poco de
     * formato; hacia el lento cuesta minutos y puede agotar la memoria.
     *
     * En ambos casos se lee el .gz línea por línea con gzgets() — nunca se carga
     * el archivo completo en RAM. Así se exportan 500K+ filas / 160+ MB sin
     * agotar memoria, cosa que el navegador no puede hacer.
     */
    public static function fromNdjsonGzFile(
        string $gzPath,
        string $targetDir,
        string $baseName,
        string $schema,
        string $view,
        int $rowHint = 0,
    ): ExportResult {
        if (!is_file($gzPath)) {
            return new ExportResult('', '', 'xlsx', 0, 0);
        }

        if (!self::qualifiesForRichFormat($gzPath, $rowHint)) {
            $fast = FastXlsxWriter::fromNdjsonGz($gzPath, $targetDir, $baseName, $view);

            if ($fast !== null) {
                return $fast;
            }

            // Null = no apto para xlsx (supera el límite de filas de Excel).
            // Se continúa por el camino clásico, que entrega CSV en ese caso.
        }

        // Export chico: PhpSpreadsheet con formato corporativo completo
        $gz = gzopen($gzPath, 'rb');
        if ($gz === false) {
            return new ExportResult('', '', 'xlsx', 0, 0);
        }

        $writer = new self($targetDir, $baseName, $schema, $view);

        try {
            while (($line = gzgets($gz)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                /** @var array<string,mixed>|null $row */
                $row = json_decode($line, true);
                if (is_array($row) && $row !== []) {
                    $writer->writeRow($row);
                }
            }
        } finally {
            gzclose($gz);
        }

        return $writer->finish();
    }

    /**
     * ¿El export es lo bastante chico para el formato rico de PhpSpreadsheet?
     *
     * Cuenta las columnas leyendo solo la primera línea del .gz (una llamada a
     * gzgets, sin costo apreciable) y las multiplica por el total de filas.
     */
    private static function qualifiesForRichFormat(string $gzPath, int $rowHint): bool
    {
        if ($rowHint <= 0) {
            return false; // total desconocido → se asume grande
        }

        $gz = gzopen($gzPath, 'rb');
        if ($gz === false) {
            return false;
        }

        $columns = 0;

        while (($line = gzgets($gz)) !== false) {
            if (trim($line) === '') {
                continue;
            }

            $row = json_decode($line, true);
            if (is_array($row) && $row !== []) {
                $columns = count($row);
            }

            break;
        }

        gzclose($gz);

        if ($columns === 0) {
            return false;
        }

        return $rowHint * $columns <= self::RICH_MAX_CELLS;
    }

    public static function fromCsvFile(
        string $csvPath,
        string $targetDir,
        string $baseName,
        string $schema,
        string $view,
    ): ExportResult {
        if (!is_file($csvPath)) {
            return new ExportResult('', '', 'xlsx', 0, 0);
        }

        // Contar filas rápido para decidir formato
        $handle = fopen($csvPath, 'r');
        if ($handle === false) {
            return new ExportResult('', '', 'xlsx', 0, 0);
        }

        $lineCount = 0;
        while (fgets($handle) !== false) {
            $lineCount++;
        }
        fclose($handle);

        // Descontar header (y línea sep= si existe)
        $dataRows = max(0, $lineCount - 1);
        if ($dataRows > 0) {
            $f = fopen($csvPath, 'r');
            $first = fgets($f);
            fclose($f);
            if (str_starts_with(trim((string) $first), 'sep=')) {
                $dataRows--;
            }
        }

        // Siempre generar xlsx — el CSV sin formato confunde a los usuarios
        // (los campos con comas internas rompen la separación de columnas).
        // OpenSpout escribe en streaming: ~5 MB de RAM fijos, sin importar el
        // tamaño. Para >1M filas (límite de Excel) se trunca al máximo.
        if ($dataRows > 1048576) {
            // Excel no soporta más de 1,048,576 filas — se entrega como CSV
            // porque no hay forma de abrirlo en Excel de todas formas.
            $finalPath = "{$targetDir}/{$baseName}.csv";
            if (realpath($csvPath) !== realpath($finalPath)) {
                rename($csvPath, $finalPath);
            } else {
                $finalPath = $csvPath;
            }

            return new ExportResult(
                path: $finalPath,
                filename: basename($finalPath),
                format: 'csv',
                rows: $dataRows,
                bytes: (int) filesize($finalPath),
            );
        }

        // ≤ 100K filas: generar xlsx con formato profesional
        return self::generateXlsxFromCsv($csvPath, $targetDir, $baseName, $schema, $view, $dataRows);
    }

    /**
     * Genera xlsx con OpenSpout + post-procesamiento PhpSpreadsheet.
     */
    private static function generateXlsxFromCsv(
        string $csvPath,
        string $targetDir,
        string $baseName,
        string $schema,
        string $view,
        int $dataRows,
    ): ExportResult {
        $xlsxPath = "{$targetDir}/{$baseName}.xlsx";

        // Detectar separador del CSV
        $handle = fopen($csvPath, 'r');
        if ($handle === false) {
            return new ExportResult('', '', 'xlsx', 0, 0);
        }

        $firstLine = (string) fgets($handle);
        rewind($handle);

        // Detectar separador: probar sep=X, luego heurística por frecuencia
        $separator = ','; // Default universal
        if (str_starts_with(trim($firstLine), 'sep=')) {
            $separator = trim(str_replace('sep=', '', trim($firstLine)));
            if ($separator === '') $separator = ',';
            fgets($handle); // consume la línea sep=
        } else {
            // Heurística: contar ; y , en la primera línea
            $semicolons = substr_count($firstLine, ';');
            $commas = substr_count($firstLine, ',');
            $separator = $semicolons > $commas ? ';' : ',';
        }

        // Saltar BOM si existe
        $pos = ftell($handle);
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            fseek($handle, $pos);
        }

        // Leer headers
        $headerLine = fgetcsv($handle, 0, $separator);
        if (!$headerLine) {
            fclose($handle);
            return new ExportResult('', '', 'xlsx', 0, 0);
        }

        $headers = array_map('trim', $headerLine);

        // Detectar columnas de texto (ceros iniciales) y fecha por nombre
        $textColumns = ExportValueFormatter::detectTextColumns($headers);
        $dateColumns = [];
        foreach ($headers as $index => $header) {
            $h = strtolower($header);
            if (str_contains($h, 'fecha') || str_contains($h, 'date')
                || str_contains($h, 'fec_') || str_ends_with($h, '_at')
                || str_starts_with($h, 'dt_')
                || str_contains($h, 'nacimiento')
                || str_contains($h, 'ingreso') && str_contains($h, 'fecha')
                || str_contains($h, 'egreso')
                || str_contains($h, 'vencimiento')
                || str_contains($h, 'creacion')
                || str_contains($h, 'modificacion')
                || str_contains($h, 'radicacion') && str_contains($h, 'fecha')
            ) {
                $dateColumns[$index] = true;
            }
        }

        // Detectar columnas de fecha por contenido de la primera fila de datos
        $dataPos = ftell($handle);
        $firstDataLine = fgetcsv($handle, 0, $separator);
        if ($firstDataLine) {
            foreach ($firstDataLine as $index => $val) {
                if (isset($dateColumns[$index]) || isset($textColumns[$index])) continue;
                $v = trim((string) $val);
                // Detectar patrones: "2024-03-12 18:02:33" o "2024-03-12"
                if ($v !== '' && preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $v)) {
                    $dateColumns[$index] = true;
                }
            }
        }
        fseek($handle, $dataPos); // Volver al inicio de datos

        // Crear writer OpenSpout (streaming: escribe directo a disco, RAM fija)
        // Usar un directorio temp dentro del targetDir en vez de /tmp
        $tempDir = "{$targetDir}/openspout_temp";
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        $options = new \OpenSpout\Writer\XLSX\Options();
        $options->SHOULD_USE_INLINE_STRINGS = true;
        $options->setTempFolder($tempDir);

        $writer = new \OpenSpout\Writer\XLSX\Writer($options);
        $writer->openToFile($xlsxPath);

        $sheet = $writer->getCurrentSheet();
        $sheet->setName(self::sanitizeSheetName($view));
        // Header congelado al hacer scroll
        $sheet->setSheetView(
            (new \OpenSpout\Writer\XLSX\Entity\SheetView())->setFreezeRow(2)
        );

        $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues($headers, self::headerStyle()));

        // Anchos de columna: se estiman con las primeras filas mientras se
        // escriben, sin una segunda pasada sobre el dataset.
        $colWidths = [];
        foreach ($headers as $index => $header) {
            $colWidths[$index] = mb_strlen((string) $header);
        }
        $widthSampleLimit = 200;

        // Estilos de fecha reutilizados: OpenSpout deduplica por contenido, pero
        // instanciarlos una sola vez evita millones de objetos temporales.
        $dateStyle     = (new \OpenSpout\Common\Entity\Style\Style())->setFormat('yyyy-mm-dd');
        $dateTimeStyle = (new \OpenSpout\Common\Entity\Style\Style())->setFormat('yyyy-mm-dd hh:mm:ss');

        // Escribir datos fila por fila (streaming: cada fila va directo a disco)
        $dataRows = 0;
        while (($fields = fgetcsv($handle, 0, $separator)) !== false) {
            $cells = [];

            foreach ($fields as $index => $value) {
                $value = trim((string) $value);

                // El CSV intermedio protege los ceros iniciales con la fórmula
                // ="036004835", que es lo que entiende Excel al abrir un CSV.
                // En un xlsx esa fórmula no aplica: la celda mostraría el texto
                // literal ="036004835". Se desenvuelve y se marca como texto.
                $wasQuotedFormula = strlen($value) > 3
                    && str_starts_with($value, '="')
                    && str_ends_with($value, '"');

                if ($wasQuotedFormula) {
                    $value = substr($value, 2, -1);
                }

                if ($dataRows < $widthSampleLimit) {
                    $len = mb_strlen($value);
                    if (!isset($colWidths[$index]) || $len > $colWidths[$index]) {
                        $colWidths[$index] = $len;
                    }
                }

                // Columnas de texto: forzar como string (preserva ceros iniciales)
                if ($wasQuotedFormula || isset($textColumns[$index])) {
                    $cells[] = \OpenSpout\Common\Entity\Cell\StringCell::fromValue($value);
                    continue;
                }

                // Columnas de fecha: escribir como serial de Excel + formato de fecha
                // Así Excel la reconoce como fecha nativa (filtros de fecha, ordenar, rango)
                if (isset($dateColumns[$index]) && $value !== '') {
                    $cleanDate = trim(preg_replace('/\.\d+Z?$/', '', str_replace('T', ' ', $value)));

                    $serial = ExportValueFormatter::toExcelSerial($cleanDate);
                    if ($serial !== null) {
                        $cells[] = \OpenSpout\Common\Entity\Cell\NumericCell::fromValue(
                            $serial,
                            preg_match('/\d{2}:\d{2}/', $cleanDate) === 1 ? $dateTimeStyle : $dateStyle
                        );
                        continue;
                    }
                    // Si no se pudo parsear como fecha, escribir como string
                    $cells[] = \OpenSpout\Common\Entity\Cell\StringCell::fromValue($value);
                    continue;
                }

                // Valores numéricos: escribir como número para que Excel
                // permita sumar/filtrar/ordenar numéricamente.
                // isSafeExcelNumber() descarta los que perderían precisión
                // (más de 15 dígitos) — esos van como texto para no salir
                // como 6,00621E+36 ni como INF.
                if ($value !== '' && ExportValueFormatter::isSafeExcelNumber($value)) {
                    $cells[] = \OpenSpout\Common\Entity\Cell\NumericCell::fromValue((float) $value);
                    continue;
                }

                // Todo lo demás como string
                $cells[] = \OpenSpout\Common\Entity\Cell\StringCell::fromValue($value);
            }

            $writer->addRow(new \OpenSpout\Common\Entity\Row($cells));
            $dataRows++;
        }

        fclose($handle);

        // Filtros automáticos y anchos: se aplican antes de cerrar porque
        // OpenSpout escribe la cabecera del sheet al ensamblar el archivo.
        $colCount = count($headers);
        if ($colCount > 0 && $dataRows > 0) {
            $sheet->setAutoFilter(
                new \OpenSpout\Writer\AutoFilter(0, 1, $colCount - 1, $dataRows + 1)
            );
        }

        foreach ($colWidths as $index => $len) {
            $sheet->setColumnWidth((float) max(9, min(50, $len + 3)), $index + 1);
        }

        $writer->close();

        // Eliminar el CSV temporal (ya está en el xlsx)
        if (is_file($csvPath)) {
            @unlink($csvPath);
        }

        return new ExportResult(
            path: $xlsxPath,
            filename: basename($xlsxPath),
            format: 'xlsx',
            rows: $dataRows,
            bytes: (int) filesize($xlsxPath),
        );
    }

    /**
     * Estilo del encabezado: azul corporativo con texto blanco centrado.
     */
    private static function headerStyle(): \OpenSpout\Common\Entity\Style\Style
    {
        return (new \OpenSpout\Common\Entity\Style\Style())
            ->setFontBold()
            ->setFontSize(10)
            ->setFontName('Calibri')
            ->setFontColor(\OpenSpout\Common\Entity\Style\Color::WHITE)
            ->setBackgroundColor('4472C4')
            ->setCellAlignment(\OpenSpout\Common\Entity\Style\CellAlignment::CENTER)
            ->setCellVerticalAlignment(\OpenSpout\Common\Entity\Style\CellVerticalAlignment::CENTER);
    }

    /**
     * Excel rechaza los nombres de hoja con \ / ? * : [ ] y de más de 31 chars.
     */
    private static function sanitizeSheetName(string $view): string
    {
        $clean = preg_replace('/[\\\\\/\?\*:\[\]]/', '_', $view) ?? $view;
        $clean = substr($clean, 0, 31);

        return $clean === '' ? 'Datos' : $clean;
    }
}
