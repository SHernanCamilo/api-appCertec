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
    public int $timeout = 600; // 10 min max

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
    ) {}

    public function handle(): void
    {
        // No necesitamos mucha RAM: escribimos directo a disco mientras leemos
        ini_set('memory_limit', '256M');

        $this->updateStatus(self::STATUS_PROCESSING, null, ['progress' => 0, 'rows' => 0]);

        try {
            $user = User::findOrFail($this->userId);

            // Estrategia: escribir CSV directo a disco mientras consumimos datos.
            // Nunca acumulamos todas las filas en RAM.
            $this->exportDirectToCsv($user);

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
     * Exporta datos directo a CSV sin acumular en RAM.
     * Lee página por página de la API Python (5K filas/page) y escribe al archivo.
     */
    private function exportDirectToCsv(User $user): void
    {
        $url     = rtrim(env('GRAPHQL_URL', 'http://127.0.0.1:8001'), '/');
        $token   = env('TOKEN_ADMIN', '');
        $gateway = app(\App\Services\Fabric\GraphFabricGatewayService::class);

        $maxRows = min((int)($this->options['max_rows'] ?? 500000), 1000000);
        $limit   = 5000; // Máximo que acepta la API por request
        $offset  = 0;
        $totalRows = 0;
        $headersWritten = false;

        // Preparar archivo CSV
        $filename = "{$this->schema}_{$this->view}_" . date('Ymd_His') . '.csv';
        $dir      = storage_path("app/fabric_exports/{$this->jobId}");
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $filePath = "{$dir}/{$filename}";
        $handle   = fopen($filePath, 'w');

        // BOM UTF-8 para Excel
        fwrite($handle, "\xEF\xBB\xBF");

        $payload = [
            'token'       => $token,
            'groups'      => $gateway->getGruposBd($user),
            'department'  => $gateway->getDepartamento($user),
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

        // Paginar: leer 5K filas por request, escribir al CSV, liberar memoria
        while ($offset < $maxRows) {
            $payload['limit']  = $limit;
            $payload['offset'] = $offset;

            $response = Http::timeout(130)
                ->connectTimeout(10)
                ->acceptJson()
                ->post($url . '/api/data/dynamic', $payload);

            if ($response->failed()) {
                // Si es 422 filters_required, reportar error amigable
                $body = $response->json();
                if ($response->status() === 422 && ($body['error'] ?? '') === 'filters_required') {
                    fclose($handle);
                    @unlink($filePath);
                    $this->updateStatus(self::STATUS_FAILED, $body['message'] ?? 'Vista requiere filtros.');
                    return;
                }
                throw new \RuntimeException(
                    "Graph-Fabric respondió HTTP {$response->status()}: " . substr($response->body(), 0, 200)
                );
            }

            $data  = $response->json();
            $items = $data['items'] ?? [];

            if (empty($items)) {
                break; // No hay más datos
            }

            // Escribir headers del CSV (solo la primera vez)
            if (!$headersWritten) {
                $headers = array_keys($items[0]);
                fputcsv($handle, $headers, ';');
                $headersWritten = true;
            }

            // Escribir filas directo al disco
            foreach ($items as $row) {
                fputcsv($handle, array_map(fn($h) => $row[$h] ?? '', $headers), ';');
                $totalRows++;
            }

            // Liberar memoria del batch actual
            unset($items, $data);

            $offset += $limit;

            // Actualizar progreso
            $progress = min(95, intval($totalRows / $maxRows * 95));
            $this->updateStatus(self::STATUS_PROCESSING, null, [
                'progress' => $progress,
                'rows'     => $totalRows,
                'message'  => "Exportando datos... ({$totalRows} filas)",
            ]);

            // Si el último batch tenía menos de $limit filas, ya no hay más
            $pageInfo = $response->json()['page_info'] ?? [];
            if (!($pageInfo['has_next'] ?? false)) {
                break;
            }
        }

        fclose($handle);

        // Si no hubo datos
        if ($totalRows === 0) {
            @unlink($filePath);
            $this->updateStatus(self::STATUS_COMPLETED, 'No hay datos con los filtros aplicados.', [
                'rows' => 0,
                'progress' => 100,
            ]);
            return;
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
            'format'          => 'csv',
        ]);

        Log::info('FabricStreamExportJob: export completado', [
            'job_id'   => $this->jobId,
            'rows'     => $totalRows,
            'size'     => $fileSize,
            'filename' => $filename,
        ]);
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
