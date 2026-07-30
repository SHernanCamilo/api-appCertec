<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use App\Services\Fabric\Export\ExportResult;
use App\Services\Fabric\Export\StreamingExportWriter;
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
 *   1. Frontend llama POST /api/fabric/viewer/export/start â†’ recibe job_id
 *   2. Este job consume el stream de Python chunk por chunk (50K filas/chunk)
 *   3. Cada chunk se descomprime (gzipâ†’NDJSON) y se acumula
 *   4. Al terminar, genera un CSV comprimido o Excel
 *   5. Frontend descarga con GET /export/download/{job_id}
 *
 * Ventaja: Graph-Fabric queda libre para atender otros usuarios.
 * El streaming es rÃ¡pido (solo envÃ­a datos crudos), Laravel arma el archivo.
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
        // Cola dedicada para exports â€” aislada de jobs rÃ¡pidos.
        $this->onQueue('exports');
    }

    public function handle(): void
    {
        // Excel con 150K+ filas necesita RAM para PhpSpreadsheet (genera XML interno)
        ini_set('memory_limit', '1G');
        set_time_limit(0); // Sin lÃ­mite de tiempo (el job tiene su propio timeout de 600s)

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
     * NO carga todas las filas en RAM â€” escribe cada batch y lo libera.
     * Funciona con 500K+ filas sin agotar memoria.
     */
    private function exportToExcel(User $user): void
    {
        // Intentar R2 cache primero (12x mÃ¡s rÃ¡pido para vistas grandes)
        if ($this->tryExportFromR2($user)) {
            return; // R2 resolviÃ³ el export completo
        }

        // Fallback: descargar de Fabric request por request
        $this->exportFromFabricDirect($user);
    }

    /**
     * Fast-path: Export desde R2 Parquet cache (~47s para 450K filas vs 9 min).
     * Retorna true si R2 resolviÃ³ el export, false para caer al fallback.
     */
    private function tryExportFromR2(User $user): bool
    {
        $url     = rtrim(env('GRAPHQL_URL', 'http://127.0.0.1:8001'), '/');
        $token   = env('TOKEN_ADMIN', '');
        $gateway = app(\App\Services\Fabric\GraphFabricGatewayService::class);

        // âš ï¸ CORRECTITUD DE DATOS: el parquet de R2 es un snapshot de la vista COMPLETA
        // sin filtros. Si el usuario filtrÃ³, R2 no puede garantizar el mismo resultado
        // que Fabric (el parquet puede estar desactualizado o el filtro no aplicarse).
        // En ese caso vamos directo a Fabric, que aplica los filtros en el SELECT.
        $filters = $this->options['filters'] ?? [];
        if (!empty($filters)) {
            Log::info('FabricStreamExportJob: export con filtros â†’ Fabric directo (R2 omitido)', [
                'job_id'  => $this->jobId,
                'filters' => array_keys($filters),
            ]);
            return false;
        }

        $this->updateStatus(self::STATUS_PROCESSING, null, [
            'progress' => 5,
            'rows'     => 0,
            'message'  => 'Descargando de R2 (puede tardar 30-60s)...',
        ]);

        try {
            // ensure_fresh=true: el usuario pidiÃ³ esta descarga explÃ­citamente, asÃ­ que
            // Graph-Fabric valida el COUNT contra Fabric y regenera el parquet si difiere.
            // Cuesta 30-120s extra pero garantiza que el Excel trae exactamente las filas
            // que el usuario ve en la grilla. (OData NO lo usa: allÃ­ prima la velocidad.)
            $response = Http::timeout(600)
                ->connectTimeout(10)
                ->post($url . '/api/data/export/r2', [
                    'token'        => $token,
                    'user_email'   => $user->email,
                    'user_name'    => $user->name ?? $user->email,
                    'department'   => $gateway->resolveDepartmentForGrantView($user, $this->view),
                    'groups'       => $gateway->getGruposBd($user),
                    'schema_name'  => $this->schema,
                    'view'         => $this->view,
                    'filters'      => $gateway->normalizeFiltersPublic($this->options['filters'] ?? []),
                    'columns'      => $this->options['columns'] ?? [],
                    'max_rows'     => min((int)($this->options['max_rows'] ?? 500000), 1000000),
                    'format'       => 'gzip',
                    'ensure_fresh' => true,
                ]);

            if ($response->status() !== 200) {
                // 404 no_cache  = vista sin parquet (p. ej. > 1M filas) â†’ Fabric directo
                // 202          = parquet generÃ¡ndose â†’ Fabric directo
                Log::info('FabricStreamExportJob: R2 no disponible, usando Fabric directo', [
                    'job_id'      => $this->jobId,
                    'status'      => $response->status(),
                    'error'       => $response->json('error'),
                    'retry_after' => $response->json('retry_after_seconds'),
                ]);
                return false;
            }

            // R2 respondiÃ³ con datos â€” escribir gzip a disco y decodificar por streaming
            $this->updateStatus(self::STATUS_PROCESSING, null, [
                'progress' => 20,
                'rows'     => 0,
                'message'  => 'Descargando desde cache R2...',
            ]);

            $totalRows = (int) ($response->header('X-Total-Rows') ?? 0);

            // Trazabilidad de frescura: permite auditar si el parquet se regenerÃ³ y
            // si el conteo coincidÃ­a con Fabric al momento de la descarga.
            Log::info('FabricStreamExportJob: R2 sirviÃ³ el export', [
                'job_id'       => $this->jobId,
                'view'         => "{$this->schema}.{$this->view}",
                'source'       => $response->header('X-Source'),
                'rows'         => $totalRows,
                'parquet_rows' => $response->header('X-Parquet-Rows'),
                'regenerated'  => $response->header('X-Parquet-Regenerated'),
                'fabric_count' => $response->header('X-Fabric-Count'),
                'elapsed_ms'   => $response->header('X-Elapsed-Ms'),
            ]);

            // Preparar directorio
            $dir = storage_path("app/fabric_exports/{$this->jobId}");
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }

            // Escribir gzip a disco (NO decodificar en RAM)
            $gzipFile = "{$dir}/r2_data.gz";
            file_put_contents($gzipFile, $response->body());
            unset($response); // Liberar RAM del response

            $this->updateStatus(self::STATUS_PROCESSING, null, [
                'progress' => 35,
                'rows'     => $totalRows,
                'message'  => "Procesando {$totalRows} filas desde R2...",
            ]);

            // UNA SOLA PASADA: gzip â†’ archivo final. Antes habÃ­a un data.tmp
            // intermedio que obligaba a recorrer el dataset tres veces.
            $gzStream = gzopen($gzipFile, 'rb');
            if ($gzStream === false) {
                @unlink($gzipFile);
                Log::warning('FabricStreamExportJob: No se pudo abrir stream gz', ['job_id' => $this->jobId]);
                return false;
            }

            $writer = $this->makeWriter($dir);
            $writer->onProgress(function (int $rows) use ($totalRows): void {
                $progress = min(90, 35 + (int) ($rows / max($totalRows, 1) * 55));
                $this->updateStatus(self::STATUS_PROCESSING, null, [
                    'progress' => $progress,
                    'rows'     => $rows,
                    'message'  => "Procesando... ({$rows} filas)",
                ]);
            });

            try {
                while (!gzeof($gzStream)) {
                    $line = gzgets($gzStream, 1048576); // 1 MB max por lÃ­nea
                    if ($line === false || trim($line) === '') {
                        continue;
                    }

                    $row = json_decode(trim($line), true);
                    if (!is_array($row) || $row === []) {
                        continue;
                    }

                    $writer->writeRow($row);
                }
            } catch (\Throwable $e) {
                $writer->abort();
                throw $e;
            } finally {
                gzclose($gzStream);
                @unlink($gzipFile);
            }

            $result = $writer->finish();

            if ($result->isEmpty()) {
                $this->updateStatus(self::STATUS_COMPLETED, 'No hay datos con los filtros aplicados.', [
                    'rows' => 0, 'progress' => 100,
                ]);
                return true;
            }

            $this->publishResult($result, 'r2');

            return true;

        } catch (\Throwable $e) {
            Log::warning('FabricStreamExportJob: R2 fallÃ³, usando fallback', [
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
        $limit   = (int) env('FABRIC_EXPORT_CHUNK', 10000); // filas por request a Python
        $offset  = 0;

        // Pausa entre chunks (ms) â€” libera el worker de Python para atender usuarios
        // interactivos entre lote y lote. Evita que un export monopolice un worker.
        $chunkPauseMs = (int) env('FABRIC_EXPORT_CHUNK_PAUSE_MS', 300);
        $totalRows = 0;

        // Preparar directorio
        $dir = storage_path("app/fabric_exports/{$this->jobId}");
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        // UNA SOLA PASADA: cada lote de Fabric se escribe directo al archivo final.
        $writer = $this->makeWriter($dir);

        $payload = [
            'token'       => $token,
            'groups'      => $gateway->getGruposBd($user),
            // Department alineado a la vista (ej: NvaGral â†’ NVA aunque el usuario tambiÃ©n tenga EAL)
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
                $writer->abort();
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

            foreach ($items as $row) {
                if (!is_array($row) || $row === []) {
                    continue;
                }
                $writer->writeRow($row);
                $totalRows++;
            }

            $pageInfo = $data['page_info'] ?? [];
            unset($items, $data);
            $offset += $limit;

            $progress = min(70, intval($totalRows / $maxRows * 70));
            $this->updateStatus(self::STATUS_PROCESSING, null, [
                'progress' => $progress,
                'rows'     => $totalRows,
                'message'  => "Descargando datos... ({$totalRows} filas)",
            ]);

            if (!($pageInfo['has_next'] ?? false)) break;

            // Ceder el worker de Python entre lotes: los usuarios interactivos
            // pueden colarse mientras el export descansa unos milisegundos.
            if ($chunkPauseMs > 0) {
                usleep($chunkPauseMs * 1000);
            }
        }

        $result = $writer->finish();

        if ($result->isEmpty()) {
            $this->updateStatus(self::STATUS_COMPLETED, 'No hay datos con los filtros aplicados.', [
                'rows' => 0, 'progress' => 100,
            ]);
            return;
        }

        $this->publishResult($result, 'fabric');
    }

    // =========================================================================
    // WRITER Y PUBLICACIÃ“N DEL RESULTADO
    // =========================================================================

    /**
     * Crea el escritor de una sola pasada para este job.
     */
    private function makeWriter(string $dir): StreamingExportWriter
    {
        $baseName = "{$this->schema}_{$this->view}_" . date('Ymd_His');

        return new StreamingExportWriter($dir, $baseName, $this->schema, $this->view);
    }

    /**
     * Marca el job como completado y publica los metadatos del archivo.
     */
    private function publishResult(ExportResult $result, string $source): void
    {
        $this->updateStatus(self::STATUS_COMPLETED, null, [
            'progress'        => 100,
            'rows'            => $result->rows,
            'filename'        => $result->filename,
            'file_path'       => "fabric_exports/{$this->jobId}/{$result->filename}",
            'file_size'       => $result->bytes,
            'file_size_human' => $result->humanSize(),
            'format'          => $result->format,
            'source'          => $source,
        ]);

        Log::info('FabricStreamExportJob: archivo generado', [
            'job_id'   => $this->jobId,
            'view'     => "{$this->schema}.{$this->view}",
            'rows'     => $result->rows,
            'size'     => $result->bytes,
            'format'   => $result->format,
            'source'   => $source,
            'filename' => $result->filename,
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
}
