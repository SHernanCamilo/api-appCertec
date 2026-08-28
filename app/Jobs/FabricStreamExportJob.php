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

    /**
     * Sin reintentos automáticos. Si falla, falla limpiamente con mensaje al usuario.
     * Los reintentos agresivos causaron acumulación de 27 jobs que saturaron Python.
     */
    public int $tries   = 1;

    /**
     * 15 min. Las vistas grandes (CarteraXEdades: 460K filas,
     * EvolucionesEspecialistas: 280K) tardan 1-6 min en Fabric + ~35s generando
     * el CSV en DuckDB. Con 300s el job moría antes de que Python respondiera.
     *
     * El worker de Horizon usa 960s para dejar margen y que el job falle con
     * mensaje propio en vez de que Horizon lo mate en seco.
     */
    public int $timeout = 900;

    /** Por encima de este total no se genera archivo: hay que filtrar antes. */
    private const MAX_EXPORTABLE_ROWS = 1_000_000;

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
        // Los exports con CSV directo usan poca RAM (~100 MB).
        // Solo los xlsx de ≤50K filas necesitan más para PhpSpreadsheet.
        ini_set('memory_limit', '512M');
        set_time_limit(0);

        // Circuit breaker: si Python/Fabric está caído, no intentar
        // (evita acumular jobs que saturan la cola cuando el servicio se recupere)
        if (\Illuminate\Support\Facades\Cache::get('fabric_export_circuit_open')) {
            $this->updateStatus(self::STATUS_FAILED, 'Servicio de datos temporalmente no disponible. Intente en unos minutos.');
            Log::warning('FabricStreamExportJob: circuit breaker abierto, job rechazado', [
                'job_id' => $this->jobId,
            ]);
            return;
        }

        $this->updateStatus(self::STATUS_PROCESSING, null, ['progress' => 0, 'rows' => 0]);

        try {
            $user = User::findOrFail($this->userId);
            $this->exportToExcel($user);
        } catch (\Throwable $e) {
            $this->updateStatus(self::STATUS_FAILED, $this->mensajeParaUsuario($e));
            Log::error('FabricStreamExportJob [ERROR]', [
                'job_id' => $this->jobId,
                'schema' => $this->schema,
                'view'   => $this->view,
                'error'  => $e->getMessage(),
            ]);

            // Si Python no respondió (timeout/connection refused), incrementar contador
            if ($this->isPythonConnectionError($e)) {
                $failures = (int) \Illuminate\Support\Facades\Cache::get('fabric_export_failures', 0);
                \Illuminate\Support\Facades\Cache::put('fabric_export_failures', $failures + 1, 300);

                // 3 fallos consecutivos → abrir circuit breaker por 2 minutos
                if ($failures + 1 >= 3) {
                    \Illuminate\Support\Facades\Cache::put('fabric_export_circuit_open', true, 120);
                    \Illuminate\Support\Facades\Cache::forget('fabric_export_failures');
                    Log::critical('FabricStreamExportJob: CIRCUIT BREAKER ABIERTO — Python no responde', [
                        'failures' => $failures + 1,
                        'cooldown' => '2 min',
                    ]);
                }
            }
        }
    }

    /**
     * Detecta si el error es por falta de conexión con Python (timeout, refused, etc.)
     */
    private function isPythonConnectionError(\Throwable $e): bool
    {
        $msg = strtolower($e->getMessage());
        return str_contains($msg, 'timeout')
            || str_contains($msg, 'connection refused')
            || str_contains($msg, 'connection reset')
            || str_contains($msg, 'could not resolve')
            || str_contains($msg, 'curl error');
    }

    /**
     * Genera Excel escribiendo directo a disco con XMLWriter.
     * NO carga todas las filas en RAM â€” escribe cada batch y lo libera.
     * Funciona con 500K+ filas sin agotar memoria.
     */
    private function exportToExcel(User $user): void
    {
        // 1. Intentar parquet R2 (rapido: <5s para vistas con parquet, 3-10s al vuelo)
        if ($this->tryExportFromR2($user)) {
            return; // R2 resolvio el export completo
        }

        // 2. Fallback: /api/data/export/stream (carriles de export dedicados,
        //    no compiten con las grillas). Recomendado por Graph-Fabric para
        //    vistas grandes sin parquet (404 no_cache).
        if ($this->tryExportFromStream($user)) {
            return;
        }

        // 3. Ultimo recurso: /api/data/dynamic paginado (probado, mas lento).
        $this->exportFromFabricDirect($user);
    }

    /**
     * Fallback recomendado por Graph-Fabric: /api/data/export/stream.
     *
     * Usa los carriles de export dedicados (fast/medium/heavy) que NO compiten
     * con las consultas de las grillas. Devuelve datos comprimidos igual que
     * /export/r2. Retorna false si el endpoint no esta disponible → cae a dynamic.
     */
    private function tryExportFromStream(User $user): bool
    {
        $url     = rtrim((string) config('fabric.url', 'http://127.0.0.1:8001'), '/');
        $token   = (string) config('fabric.token_admin', '');
        $gateway = app(\App\Services\Fabric\GraphFabricGatewayService::class);
        $filters = $this->options['filters'] ?? [];

        $this->updateStatus(self::STATUS_PROCESSING, null, [
            'progress' => 8, 'rows' => 0, 'message' => 'Exportando desde Fabric (stream)...',
        ]);

        try {
            $maxRows  = min((int)($this->options['max_rows'] ?? 500000), self::MAX_EXPORTABLE_ROWS);
            $dir      = storage_path("app/fabric_exports/{$this->jobId}");
            if (!is_dir($dir)) { mkdir($dir, 0775, true); }
            $baseName = "{$this->schema}_{$this->view}_" . date('Ymd_His');
            $gzFile   = "{$dir}/{$baseName}_raw.gz";

            $response = Http::timeout(300)
                ->connectTimeout(10)
                ->withHeaders(['X-API-Key' => (string) config('fabric.api_key', '')])
                ->sink($gzFile)
                ->post($url . '/api/data/export/stream', [
                    'token'        => $token,
                    'user_email'   => $user->email,
                    'user_name'    => $user->name ?? $user->email,
                    'department'   => $gateway->resolveDepartmentForGrantView($user, $this->view),
                    'groups'       => $gateway->getGruposBd($user),
                    'schema_name'  => $this->schema,
                    'view'         => $this->view,
                    'filters'      => empty($filters) ? new \stdClass() : $gateway->normalizeFiltersPublic($filters),
                    'columns'      => $this->options['columns'] ?? [],
                    'max_rows'     => $maxRows,
                    'format'       => 'csv',
                ]);

            // 404/405 → el endpoint no existe en esta version de Graph → dynamic
            if (in_array($response->status(), [404, 405], true)) {
                @unlink($gzFile);
                Log::info('FabricStreamExportJob: /export/stream no disponible, usando dynamic', [
                    'job_id' => $this->jobId, 'status' => $response->status(),
                ]);
                return false;
            }

            if ($response->status() !== 200) {
                @unlink($gzFile);
                return false;
            }

            $totalRows = (int) ($response->header('X-Total-Rows') ?? 0);

            if (!is_file($gzFile) || filesize($gzFile) < 20 || $totalRows === 0) {
                @unlink($gzFile);
                return false;
            }

            if ($totalRows > self::MAX_EXPORTABLE_ROWS) {
                @unlink($gzFile);
                $this->updateStatus(self::STATUS_FAILED, sprintf(
                    'La vista tiene %s registros y excede el máximo exportable (%s). Aplique filtros.',
                    number_format($totalRows), number_format(self::MAX_EXPORTABLE_ROWS)
                ));
                return true;
            }

            // Descomprimir gz → csv y armar el archivo (mismo flujo que R2)
            $csvFile = "{$dir}/{$baseName}_raw.csv";
            $gz  = gzopen($gzFile, 'rb');
            $csv = fopen($csvFile, 'w');
            if ($gz === false || $csv === false) {
                @unlink($gzFile);
                return false;
            }
            while (!gzeof($gz)) {
                $chunk = gzread($gz, 65536);
                if ($chunk !== false && $chunk !== '') {
                    fwrite($csv, $chunk);
                }
            }
            gzclose($gz);
            fclose($csv);
            @unlink($gzFile);

            $this->updateStatus(self::STATUS_PROCESSING, null, [
                'progress' => 60, 'rows' => $totalRows,
                'message' => "Generando archivo ({$totalRows} filas)...",
            ]);

            $result = StreamingExportWriter::fromCsvFile($csvFile, $dir, $baseName, $this->schema, $this->view);
            if ($result->isEmpty()) {
                $this->updateStatus(self::STATUS_COMPLETED, 'No hay datos.', ['rows' => 0, 'progress' => 100]);
                return true;
            }

            $this->publishResult($result, 'stream');
            return true;
        } catch (\Throwable $e) {
            Log::info('FabricStreamExportJob: /export/stream fallo, usando dynamic', [
                'job_id' => $this->jobId, 'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Fast-path: Export desde R2 Parquet cache (format=csv).
     *
     * DuckDB genera un CSV de alta calidad: fechas sin T, decimales OK, BOM UTF-8.
     * Laravel guarda directo a disco. fromCsvFile() convierte a xlsx si <=50K filas.
     */
    private function tryExportFromR2(User $user): bool
    {
        $url     = rtrim(config('fabric.url', 'http://127.0.0.1:8001'), '/');
        $token   = config('fabric.token_admin', '');
        $gateway = app(\App\Services\Fabric\GraphFabricGatewayService::class);

        // v2: el endpoint /api/data/export/r2 ahora soporta filtros (Graph aplica
        // el WHERE sobre el parquet o al generar al vuelo). Ya no saltamos a Fabric
        // directo por tener filtros.
        $filters = $this->options['filters'] ?? [];

        $this->updateStatus(self::STATUS_PROCESSING, null, [
            'progress' => 5, 'rows' => 0, 'message' => 'Descargando datos...',
        ]);

        try {
            $maxRows = min((int)($this->options['max_rows'] ?? 500000), self::MAX_EXPORTABLE_ROWS);

            // Preparar directorio temporal para sink()
            $dir = storage_path("app/fabric_exports/{$this->jobId}");
            if (!is_dir($dir)) { mkdir($dir, 0775, true); }
            $baseName = "{$this->schema}_{$this->view}_" . date('Ymd_His');

            // R2 responde con gzip. Descargar directamente a disco con sink()
            // para no cargar todo el body en RAM (puede ser >80 MB).
            $gzFile = "{$dir}/{$baseName}_raw.gz";

            // Timeout 120s: con ensure_fresh Graph puede tardar hasta 90s regenerando.
            $response = Http::timeout(120)
                ->connectTimeout(10)
                ->withHeaders(['X-API-Key' => config('fabric.api_key', '')])
                ->sink($gzFile)
                ->post($url . '/api/data/export/r2', [
                    'token'        => $token,
                    'user_email'   => $user->email,
                    'user_name'    => $user->name ?? $user->email,
                    'department'   => $gateway->resolveDepartmentForGrantView($user, $this->view),
                    'groups'       => $gateway->getGruposBd($user),
                    'schema_name'  => $this->schema,
                    'view'         => $this->view,
                    'filters'      => empty($filters) ? new \stdClass() : $gateway->normalizeFiltersPublic($filters),
                    'columns'      => $this->options['columns'] ?? [],
                    'max_rows'     => $maxRows,
                    'format'       => 'csv',
                    'ensure_fresh' => true,
                ]);

            // 404 "no_cache" → vista grande sin parquet: usar el stream clasico.
            if ($response->status() === 404) {
                @unlink($gzFile);
                Log::info('FabricStreamExportJob: 404 no_cache, usando Fabric stream', [
                    'job_id' => $this->jobId, 'view' => "{$this->schema}.{$this->view}",
                ]);
                return false; // handle() cae a exportFromFabricDirect
            }

            if ($response->status() !== 200) {
                @unlink($gzFile);
                Log::info('FabricStreamExportJob: R2 no exitoso (sink)', [
                    'job_id' => $this->jobId, 'status' => $response->status(),
                ]);
                return false;
            }

            $totalRows = (int) ($response->header('X-Total-Rows') ?? 0);
            $xFormat   = strtolower((string) ($response->header('X-Format') ?? 'csv-gzip'));

            // Validar que el archivo descargado no venga vacio (evita Excel 0 KB)
            if (!is_file($gzFile) || filesize($gzFile) < 20 || $totalRows === 0) {
                @unlink($gzFile);
                Log::warning('FabricStreamExportJob: R2 body vacio, fallback stream', [
                    'job_id' => $this->jobId, 'rows' => $totalRows,
                    'bytes'  => is_file($gzFile) ? filesize($gzFile) : 0,
                ]);
                return false;
            }

            // Guarda de tamaño: por encima de 1M filas el archivo es inmanejable
            if ($totalRows > self::MAX_EXPORTABLE_ROWS) {
                @unlink($gzFile);
                unset($response);
                $this->updateStatus(self::STATUS_FAILED, sprintf(
                    'La vista tiene %s registros y excede el máximo exportable (%s). Aplique filtros para reducir los datos.',
                    number_format($totalRows),
                    number_format(self::MAX_EXPORTABLE_ROWS)
                ));

                Log::warning('FabricStreamExportJob: export rechazado por tamaño', [
                    'job_id' => $this->jobId,
                    'view'   => "{$this->schema}.{$this->view}",
                    'rows'   => $totalRows,
                ]);

                return true; // Resuelto: no reintentar por Fabric directo
            }

            Log::info('FabricStreamExportJob: R2 OK', [
                'job_id'   => $this->jobId,
                'view'     => "{$this->schema}.{$this->view}",
                'rows'     => $totalRows,
                'source'   => $response->header('X-Source'),   // local | r2-cache | fabric-inline
                'x_format' => $xFormat,                        // csv-gzip (confirmado por Graph)
            ]);

            $csvFile = "{$dir}/{$baseName}_raw.csv";

            // El archivo .gz ya está en disco gracias a sink() (0 RAM usada)
            unset($response); // Liberar el objeto Http response

            $gz = gzopen($gzFile, 'rb');
            $csv = fopen($csvFile, 'w');

            if ($gz === false || $csv === false) {
                Log::error('FabricStreamExportJob: no se pudo abrir gz/csv', ['job_id' => $this->jobId]);
                @unlink($gzFile);
                return false;
            }

            while (!gzeof($gz)) {
                $chunk = gzread($gz, 65536); // 64 KB chunks
                if ($chunk !== false && $chunk !== '') {
                    fwrite($csv, $chunk);
                }
            }

            gzclose($gz);
            fclose($csv);
            @unlink($gzFile); // Ya no necesitamos el .gz

            $this->updateStatus(self::STATUS_PROCESSING, null, [
                'progress' => 60, 'rows' => $totalRows,
                'message' => "Generando archivo ({$totalRows} filas)...",
            ]);

            $result = StreamingExportWriter::fromCsvFile($csvFile, $dir, $baseName, $this->schema, $this->view);

            if ($result->isEmpty()) {
                $this->updateStatus(self::STATUS_COMPLETED, 'No hay datos.', ['rows' => 0, 'progress' => 100]);
                return true;
            }

            $this->publishResult($result, 'r2');
            return true;

        } catch (\Throwable $e) {
            Log::warning('FabricStreamExportJob: R2 fallo', [
                'job_id' => $this->jobId, 'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Fallback: Export descargando de Fabric request por request (lento pero confiable).
     */
    private function exportFromFabricDirect(User $user): void
    {
        $url     = rtrim(config('fabric.url', 'http://127.0.0.1:8001'), '/');
        $token   = config('fabric.token_admin', '');
        $gateway = app(\App\Services\Fabric\GraphFabricGatewayService::class);

        $maxRows = min((int)($this->options['max_rows'] ?? 500000), 1000000);
        $limit   = (int) config('fabric.export_chunk', 50000); // filas por request a Python (50K reduce de 45 a 9 requests)
        $offset  = 0;

        // Pausa entre chunks (ms) â€” libera el worker de Python para atender usuarios
        // interactivos entre lote y lote. Evita que un export monopolice un worker.
        $chunkPauseMs = (int) config('fabric.export_chunk_pause_ms', 100);
        $totalRows = 0;

        // Informar inmediatamente al usuario que estamos trabajando (no dejar en 0%)
        $this->updateStatus(self::STATUS_PROCESSING, null, [
            'progress' => 5,
            'rows'     => 0,
            'message'  => 'Conectando con Microsoft Fabric...',
        ]);

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
        // Reset circuit breaker: si llegamos aquí, Python respondió correctamente
        \Illuminate\Support\Facades\Cache::forget('fabric_export_failures');
        \Illuminate\Support\Facades\Cache::forget('fabric_export_circuit_open');

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

    /**
     * Se invoca cuando Horizon mata el job (timeout duro) o agota los intentos.
     *
     * El mensaje técnico ("FabricStreamExportJob has timed out") no le dice nada
     * al usuario. Se traduce a una acción concreta.
     */
    public function failed(\Throwable $e): void
    {
        $this->updateStatus(self::STATUS_FAILED, $this->mensajeParaUsuario($e));

        Log::error('FabricStreamExportJob: job fallido', [
            'job_id'  => $this->jobId,
            'view'    => "{$this->schema}.{$this->view}",
            'error'   => $e->getMessage(),
        ]);
    }

    /**
     * Traduce excepciones técnicas a instrucciones accionables.
     */
    private function mensajeParaUsuario(\Throwable $e): string
    {
        $msg = strtolower($e->getMessage());

        if (str_contains($msg, 'timed out') || str_contains($msg, 'timeout')) {
            return 'La vista es demasiado pesada y superó el tiempo máximo de exportación. '
                 . 'Aplique filtros (fechas, sede, estado) para reducir los datos e intente de nuevo.';
        }

        if (str_contains($msg, 'connection refused') || str_contains($msg, 'could not resolve')) {
            return 'El servicio de datos no está disponible en este momento. Intente en unos minutos.';
        }

        if (str_contains($msg, 'memory')) {
            return 'La vista excede la memoria disponible para exportar. Aplique filtros o exporte por rangos de fecha.';
        }

        return $e->getMessage();
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
