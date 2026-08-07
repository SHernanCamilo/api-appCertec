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
    /** Hasta este número de filas se genera xlsx con formato; por encima, CSV directo. */
    private const XLSX_THRESHOLD = 50000;

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

            return $this->buildResult($this->csvPath(), 'csv');
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
        // Indica a Excel que el separador es ';' (evita el asistente de importación)
        fwrite($handle, "sep=;\n");
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
     * Serializa una fila a CSV.
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

        return implode(';', $fields) . "\r\n";
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
            str_contains($asString, ';')
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
     * Genera un xlsx a partir de un CSV ya existente en disco.
     *
     * Usa OpenSpout para escribir en streaming: ~50MB RAM para 500K filas
     * (vs PhpSpreadsheet que necesita ~500MB+ y se cuelga con >50K).
     *
     * Las fechas vienen de DuckDB como "2024-03-12 18:02:33" (sin T) y los
     * decimales como 1234.56 — ambos formatos que Excel reconoce nativamente.
     *
     * @param  string  $csvPath     Ruta al CSV en disco
     * @param  string  $targetDir   Directorio donde dejar el resultado
     * @param  string  $baseName    Nombre base del archivo sin extensión
     * @param  string  $schema      Para metadatos
     * @param  string  $view        Para metadatos
     * @return ExportResult
     */
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
        // Usar storage/temp en vez de /tmp (que en cPanel puede estar lleno/limitado)
        $tempDir = storage_path('app/temp/openspout');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        $options = new \OpenSpout\Writer\XLSX\Options();
        $options->DEFAULT_ROW_STYLE = (new \OpenSpout\Common\Entity\Style\Style());
        $options->SHOULD_USE_INLINE_STRINGS = true;
        $options->setTempFolder($tempDir);

        $writer = new \OpenSpout\Writer\XLSX\Writer($options);
        $writer->openToFile($xlsxPath);

        // Header sin estilo especial de OpenSpout — PhpSpreadsheet lo formatea después
        $headerRow = \OpenSpout\Common\Entity\Row::fromValues($headers);
        $writer->addRow($headerRow);

        // Escribir datos fila por fila (streaming: cada fila va directo a disco)
        $dataRows = 0;
        while (($fields = fgetcsv($handle, 0, $separator)) !== false) {
            $cells = [];

            foreach ($fields as $index => $value) {
                $value = trim((string) $value);

                // Columnas de texto: forzar como string (preserva ceros iniciales)
                if (isset($textColumns[$index])) {
                    $cells[] = \OpenSpout\Common\Entity\Cell\StringCell::fromValue($value);
                    continue;
                }

                // Columnas de fecha: escribir como serial de Excel + formato de fecha
                // Así Excel la reconoce como fecha nativa (filtros de fecha, ordenar, rango)
                if (isset($dateColumns[$index]) && $value !== '') {
                    // Quitar T y milisegundos si vienen
                    $cleanDate = preg_replace('/T/', ' ', $value);
                    $cleanDate = preg_replace('/\.\d+Z?$/', '', $cleanDate);

                    $serial = ExportValueFormatter::toExcelSerial(trim($cleanDate));
                    if ($serial !== null) {
                        $hasTime = (bool) preg_match('/\d{2}:\d{2}/', $cleanDate);
                        $fmt = $hasTime ? 'yyyy-mm-dd hh:mm:ss' : 'yyyy-mm-dd';

                        $dateStyle = (new \OpenSpout\Common\Entity\Style\Style())
                            ->setFormat($fmt);

                        $cells[] = \OpenSpout\Common\Entity\Cell\NumericCell::fromValue($serial, $dateStyle);
                        continue;
                    }
                    // Si no se pudo parsear como fecha, escribir como string
                    $cells[] = \OpenSpout\Common\Entity\Cell\StringCell::fromValue($value);
                    continue;
                }

                // Valores numéricos: escribir como número para que Excel
                // permita sumar/filtrar/ordenar numéricamente
                if ($value !== '' && is_numeric($value)) {
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
        $writer->close();

        // Eliminar el CSV temporal (ya está en el xlsx)
        if (is_file($csvPath)) {
            @unlink($csvPath);
        }

        // Post-procesamiento con PhpSpreadsheet: agregar filtros y autofit
        // Solo manipula metadatos de la hoja, NO recorre las celdas de datos.
        self::applyExcelFormatting($xlsxPath, $headers, $dataRows);

        return new ExportResult(
            path: $xlsxPath,
            filename: basename($xlsxPath),
            format: 'xlsx',
            rows: $dataRows,
            bytes: (int) filesize($xlsxPath),
        );
    }

    /**
     * Agrega filtros automáticos, freeze pane y autofit a un xlsx ya generado.
     *
     * Usa PhpSpreadsheet para post-procesamiento de metadatos.
     * Para archivos grandes (>5K filas), solo lee las primeras filas para
     * estimar el ancho de columnas (no recorre todo el dataset).
     */
    private static function applyExcelFormatting(string $xlsxPath, array $headers, int $dataRows): void
    {
        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($xlsxPath);
            $reader->setReadDataOnly(false);
            $reader->setReadEmptyCells(false);

            // Leer solo las primeras 50 filas para estimar anchos de columna
            $sampleRows = min($dataRows, 50);
            $reader->setReadFilter(new class($sampleRows) implements \PhpOffice\PhpSpreadsheet\Reader\IReadFilter {
                public function __construct(private int $maxRow) {}
                public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                {
                    return $row <= ($this->maxRow + 1); // +1 por el header
                }
            });

            $spreadsheet = $reader->load($xlsxPath);
            $sheet = $spreadsheet->getActiveSheet();

            $colCount = count($headers);
            $lastCol = Coordinate::stringFromColumnIndex($colCount);

            // ═══ AutoFilter (flechitas de filtro en el header) ═══
            $sheet->setAutoFilter("A1:{$lastCol}1");

            // ═══ Freeze Pane (header fijo al hacer scroll) ═══
            $sheet->freezePane('A2');

            // ═══ AutoFit de columnas basado en contenido real ═══
            for ($colIdx = 1; $colIdx <= $colCount; $colIdx++) {
                $col = Coordinate::stringFromColumnIndex($colIdx);

                // Empezar con el ancho del header
                $maxLen = mb_strlen($headers[$colIdx - 1] ?? '');

                // Muestrear las primeras filas de datos para encontrar el más ancho
                for ($row = 2; $row <= min($sampleRows + 1, $dataRows + 1); $row++) {
                    $cellValue = $sheet->getCell("{$col}{$row}")->getValue();
                    if ($cellValue !== null) {
                        $len = mb_strlen((string) $cellValue);
                        if ($len > $maxLen) {
                            $maxLen = $len;
                        }
                    }
                }

                // Calcular ancho: caracteres + margen, con min 8 y max 50
                $width = max(8, min(50, $maxLen + 3));
                $sheet->getColumnDimension($col)->setWidth($width);
            }

            // ═══ Estilo del header — Verde profesional tipo tabla de Excel ═══
            $headerStyle = [
                'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'size' => 10, 'name' => 'Calibri'],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FF4472C4'], // Azul corporativo Excel
                ],
                'alignment' => [
                    'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                    'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                    'wrapText' => false,
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                        'color' => ['argb' => 'FF2F5496'],
                    ],
                ],
            ];
            $sheet->getStyle("A1:{$lastCol}1")->applyFromArray($headerStyle);
            $sheet->getRowDimension(1)->setRowHeight(22);

            // ═══ Estilo de datos: bordes + zebra striping azul suave ═══
            $lastDataRow = min($dataRows + 1, $sampleRows + 1);

            $dataStyle = [
                'font' => ['size' => 10, 'name' => 'Calibri'],
                'alignment' => [
                    'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                        'color' => ['argb' => 'FFD6DCE4'],
                    ],
                ],
            ];
            $sheet->getStyle("A2:{$lastCol}{$lastDataRow}")->applyFromArray($dataStyle);

            // Zebra striping: filas alternas con azul muy suave
            for ($row = 3; $row <= $lastDataRow; $row += 2) {
                $sheet->getStyle("A{$row}:{$lastCol}{$row}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FFD9E2F3');
            }

            // Guardar
            $writer = new Xlsx($spreadsheet);
            $writer->setPreCalculateFormulas(false);
            $writer->save($xlsxPath);

            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        } catch (\Throwable $e) {
            // Si falla, el xlsx de OpenSpout sigue válido — solo sin formato extra
            \Illuminate\Support\Facades\Log::warning('Export: post-procesamiento xlsx falló', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
