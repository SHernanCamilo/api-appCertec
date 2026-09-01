<?php

declare(strict_types=1);

namespace App\Services\Fabric;

use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Proxy al export ASÍNCRONO de Graph-Fabric (3 pasos).
 *
 * Graph-Fabric ejecuta la vista en segundo plano y expone:
 *   1. POST /api/data/export/start          → job_id inmediato (<1s)
 *   2. GET  /api/data/export/status/{id}    → progress 0-100 + running_s
 *   3. GET  /api/data/export/download/{id}  → gzip NDJSON (vive 10 min)
 *
 * Laravel NO genera el archivo: solo autentica, hace relay y el frontend
 * descomprime el NDJSON y arma el .xlsx. Esto elimina el Job de Horizon
 * (que fallaba por timeouts) y hace el flujo mucho más rápido.
 *
 * Deduplicación: si dos usuarios piden la misma vista+filtros, Graph devuelve
 * el mismo job_id y ejecuta la vista una sola vez (automático, sin lógica aquí).
 */
final class GraphAsyncExportService
{
    /** TTL del mapeo job → contexto en cache (Graph guarda el archivo 10 min). */
    private const JOB_CACHE_TTL = 900; // 15 min, margen sobre los 10 de Graph

    /**
     * Cuántos 404 seguidos toleramos en /status antes de declarar el job fallido.
     * Graph-Fabric ya comparte el estado en Redis (antes vivía en memoria por
     * worker de gunicorn y ~5 de cada 6 consultas daban 404), así que 2 basta
     * como margen ante un hipo de red.
     */
    private const MISS_TOLERANCE = 2;

    public function __construct(
        private readonly GraphFabricGatewayService $gateway,
    ) {}

    /**
     * Paso 1: iniciar el job en Graph-Fabric.
     *
     * @param  array<string,mixed>  $options  filters, columns, sort_col, sort_dir, max_rows
     * @return array{success:bool, job_id?:string, status?:string, message?:string, code?:int}
     */
    public function start(User $user, string $schema, string $view, array $options = []): array
    {
        $payload = $this->buildStartPayload($user, $schema, $view, $options);

        try {
            $response = Http::timeout(30)
                ->connectTimeout(10)
                ->withHeaders(['X-API-Key' => $this->apiKey()])
                ->acceptJson()
                ->post($this->url('/api/data/export/start'), $payload);

            if (!$response->successful()) {
                Log::warning('[GraphAsyncExport] start fallo', [
                    'status' => $response->status(),
                    'body'   => substr($response->body(), 0, 300),
                    'view'   => "{$schema}.{$view}",
                ]);

                return [
                    'success' => false,
                    'message' => $this->errorMessage($response),
                    'code'    => $response->status(),
                ];
            }

            $jobId = (string) ($response->json('job_id') ?? '');
            if ($jobId === '') {
                return ['success' => false, 'message' => 'Graph-Fabric no devolvio job_id.', 'code' => 502];
            }

            // Graph avisa con `reused` cuando engancho un export identico en curso,
            // y trae `download_url` si ese export ya estaba listo (deduplicacion).
            $reused = (bool) ($response->json('reused') ?? false);
            $ready  = $response->json('download_url') !== null
                || (string) ($response->json('status') ?? '') === 'ready';

            // Guardar contexto del job para el status/download posteriores
            Cache::put($this->cacheKey($jobId), [
                'schema'     => $schema,
                'view'       => $view,
                'user_id'    => $user->id,
                'created_at' => now()->toIso8601String(),
            ], self::JOB_CACHE_TTL);

            Log::info('[GraphAsyncExport] job iniciado', [
                'job_id' => $jobId,
                'view'   => "{$schema}.{$view}",
                'status' => $response->json('status'),
                'reused' => $reused,
                'ready'  => $ready,
            ]);

            return [
                'success' => true,
                'job_id'  => $jobId,
                'status'  => (string) ($response->json('status') ?? 'processing'),
                'reused'  => $reused,
                'ready'   => $ready,
            ];
        } catch (\Throwable $e) {
            Log::error('[GraphAsyncExport] start excepcion', [
                'error' => $e->getMessage(),
                'view'  => "{$schema}.{$view}",
            ]);

            return ['success' => false, 'message' => 'No se pudo conectar con el servicio de datos.', 'code' => 503];
        }
    }

    /**
     * Paso 2: consultar el progreso del job.
     *
     * Mapea la respuesta de Graph al contrato que ya consume el frontend:
     *   Graph queued|processing|ready|error  →  pending|processing|completed|failed
     *
     * @return array<string,mixed>
     */
    public function status(string $jobId): array
    {
        try {
            $response = Http::timeout(15)
                ->connectTimeout(5)
                ->withHeaders(['X-API-Key' => $this->apiKey()])
                ->acceptJson()
                ->get($this->url("/api/data/export/status/{$jobId}"));

            // 404: el job desaparecio de Graph. Puede ser transitorio (Graph
            // reasignando por deduplicacion) o definitivo (job expirado/borrado).
            // Toleramos hasta MISS_TOLERANCE 404 seguidos antes de rendirnos:
            // asi un 404 puntual no mata una descarga que va al 11%.
            if ($response->status() === 404) {
                $missKey  = "graph_async_miss:{$jobId}";
                $misses   = (int) Cache::increment($missKey);
                Cache::put($missKey, $misses, 120); // ventana de 2 min

                Log::info('[GraphAsyncExport] status 404', [
                    'job_id' => $jobId, 'misses' => $misses,
                ]);

                if ($misses < self::MISS_TOLERANCE) {
                    // Aun toleramos: seguir en processing para que el front reintente
                    return [
                        'success'  => true,
                        'status'   => 'processing',
                        'progress' => 0,
                        'message'  => 'Reconectando con el servidor de datos...',
                    ];
                }

                Cache::forget($missKey);
                return [
                    'success' => false,
                    'status'  => 'failed',
                    'message' => 'El export ya no esta disponible en el servidor (pudo expirar o fallar la vista). Vuelva a intentarlo.',
                ];
            }

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'status'  => 'failed',
                    'message' => $this->errorMessage($response),
                ];
            }

            // Respuesta OK: resetear el contador de 404 (el job existe)
            Cache::forget("graph_async_miss:{$jobId}");

            return $this->mapStatus($response->json() ?? []);
        } catch (\Throwable $e) {
            Log::warning('[GraphAsyncExport] status error', ['job_id' => $jobId, 'error' => $e->getMessage()]);

            // Error de red puntual: reportar como "procesando" para que el
            // frontend siga haciendo polling en vez de abortar la descarga.
            return ['success' => true, 'status' => 'processing', 'progress' => 0, 'message' => 'Consultando estado...'];
        }
    }

    /**
     * Paso 3: descargar el archivo a disco local (sink, sin cargar en RAM).
     *
     * IMPORTANTE — descarga UNA SOLA VEZ desde Graph:
     *   Graph-Fabric entrega cada job una unica vez; el segundo GET al mismo
     *   /export/download/{jobId} responde 410/404 porque el archivo ya se
     *   consumio. Pero hay DOS consumidores del mismo job:
     *     1. serveGraphDataForGrid  → pinta la grilla
     *     2. ConvertGraphExportToXlsxJob → genera el .xlsx del boton "Excel"
     *   El que llegaba segundo recibia "Error al descargar el archivo".
     *
     *   Solucion: el .gz se baja una vez a disco y se conserva. Si ya existe,
     *   se reutiliza sin volver a pedirlo a Graph. Ambos consumidores leen de
     *   esa copia local. El archivo lo limpia exports:cleanup por TTL.
     *
     * @return array{success:bool, path?:string, filename?:string, rows?:int, format?:string, message?:string, code?:int}
     */
    public function download(string $jobId): array
    {
        $dir = storage_path("app/fabric_exports/{$jobId}");
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['success' => false, 'message' => 'No se pudo preparar el directorio temporal.', 'code' => 500];
        }

        $tmpFile = "{$dir}/download.gz";

        // ── Reutilizar el .gz ya descargado ──────────────────────────────────
        // Si un consumidor previo (grilla o conversion xlsx) ya lo bajo, se
        // sirve esa copia sin volver a pedir a Graph, que ya lo habria expirado.
        if (is_file($tmpFile) && filesize($tmpFile) >= 20) {
            $ctx    = Cache::get($this->cacheKey($jobId), []);
            $format = 'ndjson-gzip';

            Log::info('[GraphAsyncExport] reutilizando .gz en disco', [
                'job_id' => $jobId,
                'bytes'  => filesize($tmpFile),
            ]);

            return [
                'success'  => true,
                'path'     => $tmpFile,
                'filename' => $this->buildFilename($ctx, $jobId, $format),
                'rows'     => (int) ($ctx['rows'] ?? 0),
                'format'   => $format,
            ];
        }

        try {
            $response = Http::timeout(300)
                ->connectTimeout(10)
                ->withHeaders(['X-API-Key' => $this->apiKey()])
                ->sink($tmpFile)
                ->get($this->url("/api/data/export/download/{$jobId}"));

            if (!$response->successful()) {
                @unlink($tmpFile);

                // 410 Gone = Graph ya limpio el archivo (status "expired").
                $message = match ($response->status()) {
                    410     => 'El archivo ya expiro en el servidor. Vuelva a exportar.',
                    404     => 'El export no existe. Vuelva a intentarlo.',
                    default => $this->errorMessage($response),
                };

                Log::warning('[GraphAsyncExport] download no exitoso', [
                    'job_id' => $jobId,
                    'status' => $response->status(),
                ]);

                return ['success' => false, 'message' => $message, 'code' => $response->status()];
            }

            // Guarda: nunca entregar un archivo vacio (evita el Excel en blanco)
            $bytes = is_file($tmpFile) ? (int) filesize($tmpFile) : 0;
            if ($bytes < 20) {
                @unlink($tmpFile);
                Log::warning('[GraphAsyncExport] archivo vacio', ['job_id' => $jobId, 'bytes' => $bytes]);
                return ['success' => false, 'message' => 'El export no devolvio datos.', 'code' => 422];
            }

            $ctx      = Cache::get($this->cacheKey($jobId), []);
            $rows     = (int) ($response->header('X-Total-Rows') ?? $ctx['rows'] ?? 0);
            $format   = (string) ($response->header('X-Format') ?? 'ndjson-gzip');
            $filename = $this->buildFilename($ctx, $jobId, $format);

            // Guardar rows en el contexto para que la reutilizacion del .gz los
            // conserve (los headers solo llegan en esta primera descarga).
            if ($rows > 0) {
                Cache::put($this->cacheKey($jobId), array_merge($ctx, ['rows' => $rows]), self::JOB_CACHE_TTL);
            }

            Log::info('[GraphAsyncExport] descarga OK', [
                'job_id' => $jobId,
                'rows'   => $rows,
                'bytes'  => $bytes,
                'format' => $format,
                'source' => $response->header('X-Source'),
            ]);

            return [
                'success'  => true,
                'path'     => $tmpFile,
                'filename' => $filename,
                'rows'     => $rows,
                'format'   => $format,
            ];
        } catch (\Throwable $e) {
            @unlink($tmpFile);
            // Log detallado: sin la clase, el archivo y la linea no se puede
            // saber si fallo la red hacia Graph, el disco, o un timeout.
            Log::error('[GraphAsyncExport] download excepcion', [
                'job_id'    => $jobId,
                'exception' => $e::class,
                'error'     => $e->getMessage(),
                'file'      => $e->getFile() . ':' . $e->getLine(),
                'url'       => $this->url("/api/data/export/download/{$jobId}"),
            ]);
            return [
                'success' => false,
                'message' => 'Error al descargar el archivo: ' . $e->getMessage(),
                'code'    => 503,
            ];
        }
    }

    // =========================================================================
    // PRIVATE
    // =========================================================================

    /**
     * Arma el payload de /start. `grupos` y `department` son OBLIGATORIOS:
     * sin ellos Graph responde {"detail": "Token invalido."}.
     *
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function buildStartPayload(User $user, string $schema, string $view, array $options): array
    {
        $grupos     = $this->gateway->getGruposBd($user);
        $department = $this->gateway->resolveDepartmentForGrantView($user, $view);
        $filters    = $options['filters'] ?? [];
        $columns    = $options['columns'] ?? [];

        return [
            'token'       => $this->token(),
            'schema_name' => $schema,
            'view'        => $view,
            'format'      => 'gzip', // siempre gzip: 10x mas rapido que xlsx server-side
            'max_rows'    => min((int) ($options['max_rows'] ?? 1_000_000), 1_000_000),
            'filters'     => empty($filters) ? new \stdClass() : $this->gateway->normalizeFiltersPublic($filters),
            'columns'     => empty($columns) ? null : $columns,
            'sort_col'    => (string) ($options['sort_col'] ?? ''),
            'sort_dir'    => strtoupper((string) ($options['sort_dir'] ?? 'ASC')),
            // OBLIGATORIOS
            'grupos'      => $grupos,
            'department'  => $department,
            'user_email'  => $user->email,
            // Alias por compatibilidad con otros endpoints de Graph
            'groups'      => $grupos,
            'user_name'   => $user->name ?? $user->email,
        ];
    }

    /**
     * Traduce el status de Graph al contrato del frontend.
     *
     * @param  array<string,mixed>  $g
     * @return array<string,mixed>
     */
    private function mapStatus(array $g): array
    {
        $graphStatus = (string) ($g['status'] ?? 'processing');

        // Graph-Fabric ahora expone booleanos explicitos (estado compartido en Redis,
        // no en memoria por worker). Se usan en vez de comparar strings.
        $ready = (bool) ($g['ready'] ?? false);
        $done  = (bool) ($g['done'] ?? false);

        // rows/file_size vienen como alias; se aceptan ambos nombres
        $rows     = (int) ($g['rows'] ?? $g['total_rows'] ?? $g['fetched_rows'] ?? 0);
        $progress = (int) ($g['progress'] ?? 0);
        $runningS = (float) ($g['running_s'] ?? 0);
        $filename = (string) ($g['filename'] ?? '');
        $bytes    = (int) ($g['file_size'] ?? round(((float) ($g['file_size_kb'] ?? 0)) * 1024));

        // Un export que termino CON datos (archivo + tamanio) esta listo, aunque
        // Graph ya no exponga el flag `ready` (expira en los exports "stale").
        // Antes, `$done => 'failed'` marcaba como fallido un export perfecto:
        // la respuesta traia progress:100, filename, 116 MB y stage "Listo",
        // pero el usuario veia "El export fallo en el servidor de datos".
        $terminoConDatos = ($filename !== '' && $bytes > 0)
            || ($progress >= 100 && $rows > 0);

        $status = match (true) {
            $ready                     => 'completed',
            $done && $terminoConDatos  => 'completed',
            $graphStatus === 'expired' => 'failed',
            $graphStatus === 'error'   => 'failed',
            $done                      => 'failed',  // done SIN datos = error/expirado real
            $graphStatus === 'queued'  => 'pending',
            default                    => 'processing',
        };

        // Campos nuevos de Graph para la barra de progreso
        $stage         = (string) ($g['stage'] ?? '');
        $source        = (string) ($g['source'] ?? '');
        $parquetAgeMin = $g['parquet_age_min'] ?? null;

        return [
            'success'         => $status !== 'failed',
            'status'          => $status,
            'progress'        => $status === 'completed' ? 100 : max(0, min(100, $progress)),
            'rows'            => $rows,
            'running_s'       => $runningS,
            'filename'        => $filename !== '' ? $filename : null,
            'file_size'       => $bytes,
            'file_size_human' => $bytes > 0 ? $this->humanSize($bytes / 1024) : null,
            'format'          => 'ndjson-gzip',
            // Pasarela de los campos nuevos al frontend
            'stage'           => $stage !== '' ? $stage : null,
            'source'          => $source !== '' ? $source : null,
            'parquet_age_min' => $parquetAgeMin,
            'message'         => $this->statusMessage($status, $stage, $rows, $runningS, (string) ($g['error_msg'] ?? '')),
        ];
    }

    /**
     * Mensaje para la UI. Graph-Fabric ya envia `stage` listo para mostrar
     * ("Leyendo cache de datos", "Consultando Fabric (34s)"), asi que se
     * prefiere ese texto y solo se compone uno propio si no viene.
     */
    private function statusMessage(string $status, string $stage, int $rows, float $runningS, string $errorMsg): string
    {
        if ($status === 'failed') {
            return $errorMsg !== '' ? $errorMsg : 'El export fallo en el servidor de datos.';
        }

        if ($status === 'completed') {
            return number_format($rows) . ' filas listas para descargar.';
        }

        if ($stage !== '') {
            return $stage;
        }

        $secs = $runningS > 0 ? ' (' . (int) round($runningS) . 's)' : '';

        return "Preparando la descarga{$secs}...";
    }

    private function humanSize(float $kb): string
    {
        return $kb >= 1024
            ? number_format($kb / 1024, 1) . ' MB'
            : number_format($kb, 1) . ' KB';
    }

    /** @param array<string,mixed> $ctx */
    private function buildFilename(array $ctx, string $jobId, string $format): string
    {
        $schema = (string) ($ctx['schema'] ?? 'export');
        $view   = (string) ($ctx['view'] ?? $jobId);
        $ext    = str_contains($format, 'ndjson') ? 'ndjson.gz' : 'csv.gz';

        return "{$schema}_{$view}_" . now()->format('Ymd_His') . ".{$ext}";
    }

    private function errorMessage(Response $response): string
    {
        $detail = $response->json('detail') ?? $response->json('error') ?? $response->json('message');

        return is_string($detail) && $detail !== ''
            ? $detail
            : "El servicio de datos respondio HTTP {$response->status()}.";
    }

    private function url(string $path): string
    {
        return rtrim((string) config('fabric.url', 'http://127.0.0.1:8001'), '/') . $path;
    }

    private function token(): string
    {
        return (string) (config('fabric.token_admin') ?: config('fabric.api_key', ''));
    }

    private function apiKey(): string
    {
        return (string) (config('fabric.api_key') ?: config('fabric.token_admin', ''));
    }

    private function cacheKey(string $jobId): string
    {
        return "graph_async_export:{$jobId}";
    }
}
