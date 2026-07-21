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
        fgets($handle); // Skip header line
        $row = 5;
        while (($line = fgets($handle)) !== false) {
            $values = json_decode(trim($line), true);
            if ($values) {
                $sheet->fromArray([$values], null, "A{$row}");
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
        while (($line = fgets($handle)) !== false) {
            $values = json_decode(trim($line), true);
            if ($values) {
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
}
