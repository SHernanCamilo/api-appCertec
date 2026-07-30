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
    /** Hasta este número de filas se genera xlsx con formato; por encima, CSV. */
    private const XLSX_THRESHOLD = 20000;

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

        foreach ($firstValues as $index => $value) {
            if (ExportValueFormatter::looksLikeIsoDate($value)) {
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
}
