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
        $this->updateStatus(self::STATUS_PROCESSING, null, ['progress' => 0, 'rows' => 0]);

        try {
            $user = User::findOrFail($this->userId);

            // 1. Consumir stream de Graph-Fabric
            $allRows = $this->streamFromGraphFabric($user);

            if (empty($allRows)) {
                $this->updateStatus(self::STATUS_COMPLETED, 'No hay datos con los filtros aplicados.', [
                    'rows' => 0,
                    'progress' => 100,
                ]);
                return;
            }

            // 2. Generar archivo Excel (.xlsx) con plantilla JadeOne
            $this->updateStatus(self::STATUS_PROCESSING, null, [
                'progress' => 92,
                'rows'     => count($allRows),
                'message'  => 'Generando Excel...',
            ]);

            $this->generateExcel($allRows);

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
     * Consume el stream binario de Graph-Fabric chunk por chunk.
     * Cada chunk: [4 bytes tamaño big-endian] + [N bytes gzip(NDJSON)]
     */
    private function streamFromGraphFabric(User $user): array
    {
        $url   = rtrim(env('GRAPHQL_URL', 'http://127.0.0.1:8001'), '/');
        $token = env('TOKEN_ADMIN', '');

        $gateway = app(\App\Services\Fabric\GraphFabricGatewayService::class);

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
            'max_rows'    => min((int)($this->options['max_rows'] ?? 500000), 1000000),
            'format'      => 'gzip',
        ];

        // Intentar endpoint streaming primero, fallback al export normal
        $endpoint = '/api/data/export/stream';

        $response = Http::withOptions([
            'stream'          => true,
            'timeout'         => 300,
            'connect_timeout' => 30,
        ])->acceptJson()
          ->post($url . $endpoint, $payload);

        // Si streaming no existe (404), usar el endpoint normal
        if ($response->status() === 404) {
            Log::info('FabricStreamExportJob: endpoint /stream no disponible, usando /excel', [
                'job_id' => $this->jobId,
            ]);
            return $this->fallbackExportNormal($user);
        }

        if ($response->failed()) {
            throw new \RuntimeException(
                "Graph-Fabric stream respondió HTTP {$response->status()}: " .
                substr($response->body(), 0, 300)
            );
        }

        // Consumir el stream binario
        $body    = $response->toPsrResponse()->getBody();
        $allRows = [];
        $chunk   = 0;
        $maxRows = $payload['max_rows'];

        while (!$body->eof()) {
            // Leer 4 bytes header (tamaño del chunk, big-endian uint32)
            $sizeBytes = $body->read(4);
            if (strlen($sizeBytes) < 4) {
                break; // EOF
            }

            $chunkSize = unpack('N', $sizeBytes)[1];

            // Size 0 = error del servidor
            if ($chunkSize === 0) {
                $errorData = $body->read(4096);
                throw new \RuntimeException("Stream error: {$errorData}");
            }

            // Leer chunk gzip completo
            $chunkGzip = '';
            $remaining = $chunkSize;
            while ($remaining > 0 && !$body->eof()) {
                $read = $body->read(min($remaining, 65536));
                $chunkGzip .= $read;
                $remaining -= strlen($read);
            }

            // Decodificar gzip → NDJSON
            $ndjson = @gzdecode($chunkGzip);
            if ($ndjson === false) {
                Log::warning("FabricStreamExportJob: chunk {$chunk} gzdecode failed", [
                    'job_id' => $this->jobId,
                ]);
                continue;
            }

            // Parsear NDJSON
            $lines = explode("\n", trim($ndjson));
            foreach ($lines as $line) {
                if ($line !== '') {
                    $row = json_decode($line, true);
                    if ($row) {
                        $allRows[] = $row;
                    }
                }
            }

            $chunk++;

            // Actualizar progreso
            $progress = min(90, intval(count($allRows) / $maxRows * 90));
            $this->updateStatus(self::STATUS_PROCESSING, null, [
                'progress' => $progress,
                'rows'     => count($allRows),
                'chunks'   => $chunk,
            ]);
        }

        Log::info('FabricStreamExportJob: stream completado', [
            'job_id' => $this->jobId,
            'rows'   => count($allRows),
            'chunks' => $chunk,
        ]);

        return $allRows;
    }

    /**
     * Fallback: si /stream no existe, usar el export normal existente.
     */
    private function fallbackExportNormal(User $user): array
    {
        $gateway = app(\App\Services\Fabric\GraphFabricGatewayService::class);

        $result = $gateway->exportViewExcel($user, $this->schema, $this->view, $this->options);

        if (!$result['success']) {
            throw new \RuntimeException($result['message'] ?? 'Error en export normal');
        }

        // El export normal devuelve NDJSON.gz → decodificar a array
        $content = $result['content'] ?? '';
        $format  = $result['format'] ?? 'gzip';

        if ($format === 'gzip') {
            $ndjson = @gzdecode($content);
            if ($ndjson === false) {
                throw new \RuntimeException('No se pudo decodificar el export gzip');
            }

            $rows = [];
            foreach (explode("\n", trim($ndjson)) as $line) {
                if ($line !== '') {
                    $row = json_decode($line, true);
                    if ($row) {
                        $rows[] = $row;
                    }
                }
            }
            return $rows;
        }

        // Si es xlsx directo, guardar tal cual
        $filename = $result['filename'] ?? "{$this->schema}_{$this->view}_" . date('Ymd_His') . '.xlsx';
        $path     = "fabric_exports/{$this->jobId}/{$filename}";
        Storage::disk('local')->put($path, $content);

        $this->updateStatus(self::STATUS_COMPLETED, null, [
            'progress'  => 100,
            'rows'      => -1,
            'filename'  => $filename,
            'file_path' => $path,
            'file_size' => strlen($content),
            'format'    => 'xlsx',
        ]);

        return []; // Retornar vacío porque ya guardamos el archivo
    }

    /**
     * Genera Excel (.xlsx) con la plantilla corporativa JadeOne.
     */
    private function generateExcel(array $rows): void
    {
        $filename = "{$this->schema}_{$this->view}_" . date('Ymd_His') . '.xlsx';
        $dir      = storage_path("app/fabric_exports/{$this->jobId}");
        $filePath = "{$dir}/{$filename}";

        // Crear directorio si no existe
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $generator = new \App\Services\Fabric\FabricExcelGenerator(
            $this->schema,
            $this->view,
            $this->options['filters'] ?? []
        );

        $result = $generator->generate($rows, $filePath);

        $storagePath = "fabric_exports/{$this->jobId}/{$filename}";

        $this->updateStatus(self::STATUS_COMPLETED, null, [
            'progress'        => 100,
            'rows'            => $result['rows'],
            'columns'         => $result['columns'],
            'filename'        => $filename,
            'file_path'       => $storagePath,
            'file_size'       => $result['file_size'],
            'file_size_human' => $this->humanFileSize($result['file_size']),
            'format'          => 'xlsx',
        ]);

        Log::info('FabricStreamExportJob: Excel generado', [
            'job_id'   => $this->jobId,
            'rows'     => $result['rows'],
            'size'     => $result['file_size'],
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
