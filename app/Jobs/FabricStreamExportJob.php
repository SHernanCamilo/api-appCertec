<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Export via streaming desde Graph-Fabric.
 *
 * Flujo:
 *   1. Frontend llama POST /api/fabric/viewer/export/start → recibe job_id
 *   2. Este job consume el stream de Python chunk por chunk (50K filas/chunk)
 *   3. Cada chunk se descomprime (gzip→NDJSON) y se acumula
 *   4. Al terminar, genera un CSV comprimido o Excel
 *   5. Frontend descarga con GET /export/download/{job_id}
 *
 * Ventaja: Graph-Fabric queda libre para atender otros usuarios.
 * El streaming es rápido (solo envía datos crudos), Laravel arma el archivo.
 */
final class FabricStreamExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 1;
    public int $timeout = 900; // 15 min max (Horizon lo respeta)

    private const STATUS_PENDING    = 'pending';
    private const STATUS_PROCESSING = 'processing';
    private const STATUS_COMPLETED  = 'completed';
    private const STATUS_FAILED     = 'failed';

    /**
     * Patrones de nombres de columnas que SIEMPRE deben tratarse como texto.
     * Esto previene que Excel elimine ceros iniciales en cuentas, placas, NIT, etc.
     * Se usa como respaldo cuando el valor ya llegó convertido a número desde Python.
     */
    private const TEXT_COLUMN_PATTERNS = [
        'nro_cuenta', 'num_cuenta', 'numero_cuenta', 'cuenta_bancaria',
        'placa', 'codigo', 'cod_', 'nit', 'documento', 'cedula',
        'identificacion', 'telefono', 'celular', 'consecutivo',
        'codigo_proveedor', 'codigo_banco', 'num_', 'nro_',
        'referencia', 'poliza', 'contrato',
    ];

    public function __construct(
        private readonly string $jobId,
        private readonly int    $userId,
        private readonly string $schema,
        private readonly string $view,
        private readonly array  $options,
    ) {
        // Cola dedicada para exports — aislada de jobs rápidos.
        $this->onQueue('exports');
    }

    public function handle(): void
    {
        // Excel con 150K+ filas necesita RAM para PhpSpreadsheet (genera XML interno)
        ini_set('memory_limit', '1G');
        set_time_limit(0); // Sin límite de tiempo (el job tiene su propio timeout de 600s)

        $this->updateStatus(self::STATUS_PROCESSING, null, ['progress' => 0, 'rows' => 0]);

        try {
            $user = User::findOrFail($this->userId);
            $this->exportToExcel($user);
        } catch (\Throwable $e) {
            $this->updateStatus(self::STATUS_FAILED, $e->getMessage());
            Log::error('FabricStreamExportJob [ERROR]', [
                'job_id' => $this->jobId,
                'schema' => $this->schema,
                'view'   => $this->view,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    /**
     * Genera Excel escribiendo directo a disco con XMLWriter.
     * NO carga todas las filas en RAM — escribe cada batch y lo libera.
     * Funciona con 500K+ filas sin agotar memoria.
     */
    private function exportToExcel(User $user): void
    {
        // Intentar R2 cache primero (12x más rápido para vistas grandes)
        if ($this->tryExportFromR2($user)) {
            return; // R2 resolvió el export completo
        }

        // Fallback: descargar de Fabric request por request
        $this->exportFromFabricDirect($user);
    }

    /**
     * Fast-path: Export desde R2 Parquet cache (~47s para 450K filas vs 9 min).
     * Retorna true si R2 resolvió el export, false para caer al fallback.
     */
    private function tryExportFromR2(User $user): bool
    {
        $url     = rtrim(env('GRAPHQL_URL', 'http://127.0.0.1:8001'), '/');
        $token   = env('TOKEN_ADMIN', '');
        $gateway = app(\App\Services\Fabric\GraphFabricGatewayService::class);

        $this->updateStatus(self::STATUS_PROCESSING, null, [
            'progress' => 5,
            'rows'     => 0,
            'message'  => 'Descargando de R2 (puede tardar 30-60s)...',
        ]);

        try {
            $response = Http::timeout(120)
                ->connectTimeout(10)
                ->post($url . '/api/data/export/r2', [
                    'token'       => $token,
                    'user_email'  => $user->email,
                    'user_name'   => $user->name ?? $user->email,
                    'department'  => $gateway->resolveDepartmentForGrantView($user, $this->view),
                    'groups'      => $gateway->getGruposBd($user),
                    'schema_name' => $this->schema,
                    'view'        => $this->view,
                    'filters'     => $gateway->normalizeFiltersPublic($this->options['filters'] ?? []),
                    'columns'     => $this->options['columns'] ?? [],
                    'max_rows'    => min((int)($this->options['max_rows'] ?? 500000), 1000000),
                    'format'      => 'gzip',
                ]);

            if ($response->status() !== 200) {
                // R2 no disponible (202 = generando, otro = error) → fallback
                Log::info('FabricStreamExportJob: R2 no disponible, usando Fabric directo', [
                    'job_id' => $this->jobId,
                    'status' => $response->status(),
                ]);
                return false;
            }

            // R2 respondió con datos — escribir gzip a disco y decodificar por streaming
            $this->updateStatus(self::STATUS_PROCESSING, null, [
                'progress' => 20,
                'rows'     => 0,
                'message'  => 'Descargando desde cache R2...',
            ]);

            $totalRows = (int) ($response->header('X-Total-Rows') ?? 0);

            // Preparar directorio
            $filename = "{$this->schema}_{$this->view}_" . date('Ymd_His') . '.xlsx';
            $dir      = storage_path("app/fabric_exports/{$this->jobId}");
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $filePath = "{$dir}/{$filename}";

            // Escribir gzip a disco (NO decodificar en RAM)
            $gzipFile = "{$dir}/r2_data.gz";
            file_put_contents($gzipFile, $response->body());
            unset($response); // Liberar RAM del response

            // Decodificar gzip por streaming → escribir a tmp file línea por línea
            $this->updateStatus(self::STATUS_PROCESSING, null, [
                'progress' => 35,
                'rows'     => $totalRows,
                'message'  => "Procesando {$totalRows} filas desde R2...",
            ]);

            $tmpFile = "{$dir}/data.tmp";
            $headers = [];
            $rowCount = 0;

            $gzStream = gzopen($gzipFile, 'rb');
            $tmpHandle = fopen($tmpFile, 'w');

            if (!$gzStream || !$tmpHandle) {
                @unlink($gzipFile);
                Log::warning('FabricStreamExportJob: No se pudo abrir stream gz', ['job_id' => $this->jobId]);
                return false;
            }

            while (!gzeof($gzStream)) {
                $line = gzgets($gzStream, 1048576); // 1 MB max por línea
                if ($line === false || trim($line) === '') continue;

                $row = json_decode(trim($line), true);
                if (!$row) continue;

                if (empty($headers)) {
                    $headers = array_keys($row);
                    fwrite($tmpHandle, json_encode($headers) . "\n");
                }

                $values = [];
                foreach ($headers as $h) {
                    $val = $row[$h] ?? '';
                    if (is_string($val)) {
                        $val = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $val);
                    }
                    $values[] = $val;
                }
                fwrite($tmpHandle, json_encode($values, JSON_UNESCAPED_UNICODE) . "\n");
                $rowCount++;

                // Actualizar progreso cada 50K filas
                if ($rowCount % 50000 === 0) {
                    $progress = min(65, 35 + intval($rowCount / max($totalRows, 1) * 30));
                    $this->updateStatus(self::STATUS_PROCESSING, null, [
                        'progress' => $progress,
                        'rows'     => $rowCount,
                        'message'  => "Procesando... ({$rowCount} filas)",
                    ]);
                }
            }

            gzclose($gzStream);
            fclose($tmpHandle);
            @unlink($gzipFile); // Limpiar archivo gzip temporal

            if ($rowCount === 0) {
                @unlink($tmpFile);
                $this->updateStatus(self::STATUS_COMPLETED, 'No hay datos con los filtros aplicados.', [
                    'rows' => 0, 'progress' => 100,
                ]);
                return true;
            }

            $this->updateStatus(self::STATUS_PROCESSING, null, [
                'progress' => 70,
                'rows'     => $rowCount,
                'message'  => 'Generando archivo Excel (desde R2)...',
            ]);

            // Generar Excel
            $this->writeXlsxFromTmpFile($tmpFile, $filePath, $headers, $rowCount);
            @unlink($tmpFile);

            // Si >20K filas, es CSV
            $format = 'xlsx';
            if ($rowCount > 20000) {
                $csvFilePath = str_replace('.xlsx', '.csv', $filePath);
                if (file_exists($filePath)) {
                    rename($filePath, $csvFilePath);
                }
                $filePath = $csvFilePath;
                $filename = str_replace('.xlsx', '.csv', $filename);
                $format = 'csv';
            }

            $fileSize    = filesize($filePath);
            $storagePath = "fabric_exports/{$this->jobId}/{$filename}";

            $this->updateStatus(self::STATUS_COMPLETED, null, [
                'progress'        => 100,
                'rows'            => $rowCount,
                'filename'        => $filename,
                'file_path'       => $storagePath,
                'file_size'       => $fileSize,
                'file_size_human' => $this->humanFileSize($fileSize),
                'format'          => $format,
                'source'          => 'r2',
            ]);

            Log::info('FabricStreamExportJob: Excel generado desde R2', [
                'job_id' => $this->jobId,
                'rows'   => $rowCount,
                'size'   => $fileSize,
                'source' => 'r2',
            ]);

            return true;

        } catch (\Throwable $e) {
            Log::warning('FabricStreamExportJob: R2 falló, usando fallback', [
                'job_id' => $this->jobId,
                'error'  => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Fallback: Export descargando de Fabric request por request (lento pero confiable).
     */
    private function exportFromFabricDirect(User $user): void
    {
        $url     = rtrim(env('GRAPHQL_URL', 'http://127.0.0.1:8001'), '/');
        $token   = env('TOKEN_ADMIN', '');
        $gateway = app(\App\Services\Fabric\GraphFabricGatewayService::class);

        $maxRows = min((int)($this->options['max_rows'] ?? 500000), 1000000);
        $limit   = 10000; // Graph-Fabric soporta hasta 10K por request
        $offset  = 0;
        $totalRows = 0;
        $headers = [];

        // Preparar directorio
        $filename = "{$this->schema}_{$this->view}_" . date('Ymd_His') . '.xlsx';
        $dir      = storage_path("app/fabric_exports/{$this->jobId}");
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $filePath = "{$dir}/{$filename}";

        // Archivo temporal para datos (escribimos filas como TSV temporal, luego armamos xlsx)
        $tmpFile = "{$dir}/data.tmp";
        $tmpHandle = fopen($tmpFile, 'w');

        $siteContext = $gateway->resolveSiteContext($user);
        $payload = [
            'token'       => $token,
            'groups'      => $gateway->getGruposBd($user),
            // Department alineado a la vista (ej: NvaGral → NVA aunque el usuario también tenga EAL)
            'department'  => $gateway->resolveDepartmentForGrantView($user, $this->view),
            'user_email'  => $user->email,
            'user_name'   => $user->name ?? $user->email,
            'schema_name' => $this->schema,
            'view'        => $this->view,
            'filters'     => $gateway->normalizeFiltersPublic($this->options['filters'] ?? []),
            'columns'     => $this->options['columns'] ?? [],
            'sort_col'    => $this->options['sort_col'] ?? '',
            'sort_dir'    => $this->options['sort_dir'] ?? 'asc',
            'skip_count'  => true,
            // Hint para que la API Python devuelva estas columnas como string (preserva ceros)
            'text_columns' => $this->options['text_columns'] ?? [],
        ];

        // Paso 1: Descargar todos los datos a archivo temporal (no en RAM)
        while ($offset < $maxRows) {
            $payload['limit']  = $limit;
            $payload['offset'] = $offset;

            $response = Http::timeout(130)
                ->connectTimeout(10)
                ->acceptJson()
                ->post($url . '/api/data/dynamic', $payload);

            if ($response->failed()) {
                fclose($tmpHandle);
                @unlink($tmpFile);
                $body = $response->json();
                if ($response->status() === 422 && ($body['error'] ?? '') === 'filters_required') {
                    $this->updateStatus(self::STATUS_FAILED, $body['message'] ?? 'Vista requiere filtros.');
                    return;
                }
                throw new \RuntimeException(
                    "Graph-Fabric HTTP {$response->status()}: " . substr($response->body(), 0, 200)
                );
            }

            $data  = $response->json();
            $items = $data['items'] ?? [];
            if (empty($items)) break;

            // Guardar headers
            if (empty($headers)) {
                $headers = array_keys($items[0]);
                fwrite($tmpHandle, json_encode($headers) . "\n");
            }

            // Escribir cada fila como JSON line
            foreach ($items as $row) {
                $values = [];
                foreach ($headers as $h) {
                    $val = $row[$h] ?? '';
                    if (is_string($val)) {
                        $val = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $val);
                    }
                    $values[] = $val;
                }
                fwrite($tmpHandle, json_encode($values, JSON_UNESCAPED_UNICODE) . "\n");
                $totalRows++;
            }

            unset($items, $data);
            $offset += $limit;

            $progress = min(70, intval($totalRows / $maxRows * 70));
            $this->updateStatus(self::STATUS_PROCESSING, null, [
                'progress' => $progress,
                'rows'     => $totalRows,
                'message'  => "Descargando datos... ({$totalRows} filas)",
            ]);

            $pageInfo = $response->json()['page_info'] ?? [];
            if (!($pageInfo['has_next'] ?? false)) break;
        }

        fclose($tmpHandle);

        if ($totalRows === 0) {
            @unlink($tmpFile);
            $this->updateStatus(self::STATUS_COMPLETED, 'No hay datos con los filtros aplicados.', [
                'rows' => 0, 'progress' => 100,
            ]);
            return;
        }

        // Paso 2: Generar xlsx leyendo del archivo temporal (no carga todo en RAM)
        $this->updateStatus(self::STATUS_PROCESSING, null, [
            'progress' => 75,
            'rows'     => $totalRows,
            'message'  => 'Generando archivo Excel...',
        ]);

        $this->writeXlsxFromTmpFile($tmpFile, $filePath, $headers, $totalRows);

        @unlink($tmpFile);

        // Si >20K filas, el archivo es CSV (no xlsx) — corregir extensión y formato
        $format = 'xlsx';
        if ($totalRows > 20000) {
            $csvFilePath = str_replace('.xlsx', '.csv', $filePath);
            if (file_exists($filePath)) {
                rename($filePath, $csvFilePath);
            }
            $filePath = $csvFilePath;
            $filename = str_replace('.xlsx', '.csv', $filename);
            $format = 'csv';
        }

        $fileSize    = filesize($filePath);
        $storagePath = "fabric_exports/{$this->jobId}/{$filename}";

        $this->updateStatus(self::STATUS_COMPLETED, null, [
            'progress'        => 100,
            'rows'            => $totalRows,
            'filename'        => $filename,
            'file_path'       => $storagePath,
            'file_size'       => $fileSize,
            'file_size_human' => $this->humanFileSize($fileSize),
            'format'          => $format,
        ]);

        Log::info('FabricStreamExportJob: Excel generado', [
            'job_id'   => $this->jobId,
            'rows'     => $totalRows,
            'size'     => $fileSize,
            'filename' => $filename,
        ]);
    }

    /**
     * Genera xlsx leyendo del archivo temporal línea por línea.
     * Para ≤20K filas usa PhpSpreadsheet (bonito).
     * Para >20K filas usa escritura CSV directo a xlsx (rápido, sin RAM).
     */
    private function writeXlsxFromTmpFile(string $tmpFile, string $xlsxPath, array $headers, int $totalRows): void
    {
        if ($totalRows <= 20000) {
            // PhpSpreadsheet para archivos pequeños (con formato bonito)
            $this->writeXlsxWithSpreadsheet($tmpFile, $xlsxPath, $headers, $totalRows);
        } else {
            // Para archivos grandes: CSV dentro de xlsx (rápido, 0 RAM extra)
            $this->writeXlsxLightweight($tmpFile, $xlsxPath, $headers, $totalRows);
        }
    }

    /**
     * PhpSpreadsheet — para ≤20K filas con formato corporativo JadeOne.
     */
    private function writeXlsxWithSpreadsheet(string $tmpFile, string $xlsxPath, array $headers, int $totalRows): void
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(substr($this->view, 0, 31));

        // Header corporativo
        $colCount = count($headers);
        $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colCount);

        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->setCellValue('A1', "JadeOne — {$this->schema}.{$this->view}");
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(12);

        $sheet->mergeCells("A2:{$lastCol}2");
        $sheet->setCellValue('A2', "Exportado: " . now()->format('d/m/Y H:i') . " | Registros: " . number_format($totalRows));
        $sheet->getStyle('A2')->getFont()->setItalic(true)->setSize(9);

        // Headers en fila 4
        foreach ($headers as $i => $h) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue("{$col}4", $h);
        }
        $sheet->getStyle("A4:{$lastCol}4")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFF']],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => '1B3A5C']],
        ]);
        $sheet->setAutoFilter("A4:{$lastCol}4");
        $sheet->freezePane('A5');

        // Leer datos del tmp file
        $handle = fopen($tmpFile, 'r');
        fgets($handle); // Skip header line (json de headers)
        $row = 5;

        // Detectar columnas que son fechas y columnas de texto (ceros iniciales)
        $dateColumns = [];
        $textColumns = $this->detectTextColumns($headers);

        // Leer primera línea de datos para detectar fechas y más columnas de texto
        $firstDataLine = fgets($handle);
        if ($firstDataLine) {
            $firstValues = json_decode(trim($firstDataLine), true);
            if ($firstValues) {
                foreach ($firstValues as $i => $val) {
                    if (is_string($val) && preg_match('/^\d{4}-\d{2}-\d{2}/', $val)) {
                        $dateColumns[$i] = true;
                    }
                }
                // Detección adicional por contenido de primera fila
                $textColumns = $textColumns + $this->detectTextColumns($headers, $firstValues);

                // Aplicar formato texto a las columnas enteras detectadas (para que Excel no las convierta)
                foreach ($textColumns as $i => $true) {
                    $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
                    $sheet->getStyle("{$col}5:{$col}" . ($totalRows + 4))
                        ->getNumberFormat()
                        ->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_TEXT);
                }

                // Escribir la primera fila
                $this->writeSpreadsheetRow($sheet, $row, $firstValues, $dateColumns, $textColumns);
                $row++;
            }
        }

        // Resto de filas
        while (($line = fgets($handle)) !== false) {
            $values = json_decode(trim($line), true);
            if ($values) {
                $this->writeSpreadsheetRow($sheet, $row, $values, $dateColumns, $textColumns);
                $row++;
            }
        }
        fclose($handle);

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);
        $writer->save($xlsxPath);
        $spreadsheet->disconnectWorksheets();
    }

    /**
     * Escritura ligera para >20K filas — genera CSV con extensión .xlsx
     * que Excel abre perfectamente. Usa 0 RAM extra.
     */
    private function writeXlsxLightweight(string $tmpFile, string $xlsxPath, array $headers, int $totalRows): void
    {
        // Detectar columnas de texto por nombre (respaldo)
        $textColumns = $this->detectTextColumns($headers);

        // Escribir directo al path recibido (se renombrará a .csv después)
        $out = fopen($xlsxPath, 'w');
        // BOM UTF-8
        fwrite($out, "\xEF\xBB\xBF");
        // Indica a Excel que use ; como separador
        fwrite($out, "sep=;\n");
        fputcsv($out, $headers, ';', '"', '\\');

        // Leer datos del tmp file línea por línea (0 RAM extra)
        $handle = fopen($tmpFile, 'r');
        fgets($handle); // Skip header line
        $isFirstRow = true;

        while (($line = fgets($handle)) !== false) {
            $values = json_decode(trim($line), true);
            if ($values) {
                // En la primera fila, detectar columnas adicionales por contenido
                if ($isFirstRow) {
                    $textColumns = $textColumns + $this->detectTextColumns($headers, $values);
                    $isFirstRow = false;
                }

                $values = array_map(function ($v, $i) use ($textColumns) {
                    // Columnas detectadas como texto → fórmula Excel para forzar texto
                    if (isset($textColumns[$i]) && $v !== null && $v !== '') {
                        $strVal = (string) $v;
                        if (is_numeric($strVal)) {
                            return '="' . $strVal . '"';
                        }
                        return $v;
                    }

                    // String que empieza con 0 y es numérico → proteger con fórmula
                    if (is_string($v) && preg_match('/^0\d+$/', $v)) {
                        return '="' . $v . '"';
                    }

                    // Limpiar decimales innecesarios para números normales
                    if (is_numeric($v) && is_string($v) && str_contains($v, '.')) {
                        return rtrim(rtrim($v, '0'), '.');
                    }
                    if (is_float($v)) {
                        return (floor($v) == $v) ? (int) $v : $v;
                    }
                    return $v;
                }, $values, array_keys($values));

                fputcsv($out, $values, ';', '"', '\\');
            }
        }
        fclose($handle);
        fclose($out);
    }

    // =========================================================================
    // STATUS / TRACKING
    // =========================================================================

    private function updateStatus(string $status, ?string $message = null, ?array $meta = null): void
    {
        $current = Cache::get("fabric_export:{$this->jobId}") ?? [];

        $data = array_merge($current, [
            'status'     => $status,
            'updated_at' => now()->toIso8601String(),
        ]);

        if ($message !== null) {
            $data['message'] = $message;
        }
        if ($meta !== null) {
            $data = array_merge($data, $meta);
        }

        Cache::put("fabric_export:{$this->jobId}", $data, 1800); // 30 min TTL
    }

    public function failed(\Throwable $e): void
    {
        $this->updateStatus(self::STATUS_FAILED, $e->getMessage());
    }

    /**
     * Despacha el job y retorna el job_id.
     */
    public static function dispatch_and_track(
        int    $userId,
        string $schema,
        string $view,
        array  $options
    ): string {
        $jobId = 'exp_stream_' . bin2hex(random_bytes(12));

        Cache::put("fabric_export:{$jobId}", [
            'status'     => self::STATUS_PENDING,
            'progress'   => 0,
            'rows'       => 0,
            'schema'     => $schema,
            'view'       => $view,
            'user_id'    => $userId,
            'format'     => 'xlsx',
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ], 1800);

        self::dispatch($jobId, $userId, $schema, $view, $options);

        return $jobId;
    }

    private function humanFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 1) . ' ' . $units[$i];
    }

    /**
     * Escribe una fila en la hoja de cálculo respetando el tipo original de Fabric.
     *
     * Lógica simple:
     *   - Si el valor vino como string desde Python/Fabric → se escribe como TEXTO (preserva ceros)
     *   - Si vino como int/float → se escribe como NÚMERO
     *   - Si es una fecha detectada → formato fecha Excel
     *   - Columnas en TEXT_COLUMN_PATTERNS → siempre texto (respaldo por si Python lo convirtió a int)
     */
    private function writeSpreadsheetRow(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
        int $row,
        array $values,
        array $dateColumns,
        array $textColumns
    ): void {
        foreach ($values as $i => $val) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            $cell = "{$col}{$row}";

            // Columna de fecha → formato fecha Excel
            if (isset($dateColumns[$i]) && is_string($val) && $val !== '') {
                $dateStr = str_replace('T', ' ', substr($val, 0, 19));
                try {
                    $timestamp = \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(new \DateTime($dateStr));
                    $sheet->setCellValue($cell, $timestamp);
                    $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('yyyy-mm-dd hh:mm');
                } catch (\Exception $e) {
                    $sheet->setCellValueExplicit($cell, $val, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                }
                continue;
            }

            // Columna forzada como texto por nombre (Nro_Cuenta, Placa, etc.)
            if (isset($textColumns[$i])) {
                $sheet->setCellValueExplicit($cell, (string) ($val ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                continue;
            }

            // Valor que Python/Fabric envió como STRING → preservar tal cual (protege ceros iniciales)
            if (is_string($val)) {
                $sheet->setCellValueExplicit($cell, $val, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                continue;
            }

            // Valor numérico (int o float) → dejarlo como número para que Excel opere con él
            if (is_int($val) || is_float($val)) {
                $sheet->setCellValue($cell, $val);
                continue;
            }

            // Null o cualquier otro → string vacío
            $sheet->setCellValueExplicit($cell, '', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        }
    }

    /**
     * Detecta qué columnas deben tratarse como texto para preservar ceros iniciales.
     *
     * Combina dos estrategias:
     *   1. Matching por nombre de columna (TEXT_COLUMN_PATTERNS)
     *   2. Detección de valores que empiezan con "0" seguido de más dígitos
     *
     * @param array $headers Nombres de las columnas
     * @param array|null $firstRow Primera fila de valores (para detección por contenido)
     * @return array<int, bool> Mapa de índice → true si es columna de texto
     */
    private function detectTextColumns(array $headers, ?array $firstRow = null): array
    {
        $textColumns = [];

        foreach ($headers as $i => $header) {
            $headerLower = strtolower($header);

            // Estrategia 1: nombre de columna coincide con patrones conocidos
            foreach (self::TEXT_COLUMN_PATTERNS as $pattern) {
                if (str_contains($headerLower, $pattern)) {
                    $textColumns[$i] = true;
                    break;
                }
            }

            // Estrategia 2: el valor en la primera fila empieza con 0 y es numérico
            if (!isset($textColumns[$i]) && $firstRow !== null && isset($firstRow[$i])) {
                $val = (string) ($firstRow[$i] ?? '');
                if (preg_match('/^0\d+$/', $val)) {
                    $textColumns[$i] = true;
                }
            }
        }

        return $textColumns;
    }
}
