<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use App\Services\Fabric\GraphFabricGatewayService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Job para exportar vistas de Fabric a Excel en segundo plano.
 *
 * Flujo:
 *   1. Frontend llama POST /api/fabric/viewer/export/start → recibe job_id
 *   2. Este job se procesa en background (queue worker)
 *   3. Frontend consulta GET /api/fabric/viewer/export/status/{job_id}
 *   4. Cuando está listo → GET /api/fabric/viewer/export/download/{job_id}
 *
 * En Apache esto libera el worker HTTP inmediatamente.
 * El export real lo hace el queue worker contra la API Python.
 */
final class FabricExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $backoff = 30;
    public int $timeout = 300; // 5 min máximo

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
        $this->updateStatus(self::STATUS_PROCESSING);

        try {
            $user = User::findOrFail($this->userId);
            $gateway = app(GraphFabricGatewayService::class);

            $result = $gateway->exportViewExcel(
                $user,
                $this->schema,
                $this->view,
                $this->options
            );

            if (!$result['success']) {
                $this->updateStatus(self::STATUS_FAILED, $result['message'] ?? 'Error en export');
                return;
            }

            // Guardar archivo temporal (se elimina después de descarga o 30 min)
            $format   = $this->options['format'] ?? 'gzip';
            $ext      = $format === 'gzip' ? '.ndjson.gz' : '.xlsx';
            $filename = $result['filename'] ?? "{$this->schema}_{$this->view}_" . date('Ymd_His') . $ext;
            $path     = "fabric_exports/{$this->jobId}/{$filename}";

            Storage::disk('local')->put($path, $result['content']);

            $this->updateStatus(self::STATUS_COMPLETED, null, [
                'filename' => $filename,
                'path'     => $path,
                'size'     => strlen($result['content']),
                'format'   => $format,
            ]);

            Log::info('FabricExportJob completado', [
                'job_id'   => $this->jobId,
                'user_id'  => $this->userId,
                'schema'   => $this->schema,
                'view'     => $this->view,
                'filename' => $filename,
            ]);

        } catch (\Throwable $e) {
            $this->updateStatus(self::STATUS_FAILED, $e->getMessage());
            Log::error('FabricExportJob falló', [
                'job_id' => $this->jobId,
                'error'  => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        $this->updateStatus(self::STATUS_FAILED, $e->getMessage());
    }

    private function updateStatus(string $status, ?string $message = null, ?array $meta = null): void
    {
        $data = [
            'status'     => $status,
            'updated_at' => now()->toIso8601String(),
        ];

        if ($message !== null) {
            $data['message'] = $message;
        }
        if ($meta !== null) {
            $data = array_merge($data, $meta);
        }

        // Cache 30 minutos — suficiente para que el usuario descargue
        Cache::put("fabric_export:{$this->jobId}", $data, 1800);
    }

    /**
     * Crea un job y retorna el job_id para tracking.
     */
    public static function dispatch_and_track(
        int    $userId,
        string $schema,
        string $view,
        array  $options
    ): string {
        $jobId = 'exp_' . bin2hex(random_bytes(12));

        // Status inicial
        Cache::put("fabric_export:{$jobId}", [
            'status'     => self::STATUS_PENDING,
            'schema'     => $schema,
            'view'       => $view,
            'user_id'    => $userId,
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ], 1800);

        // Despachar al queue
        self::dispatch($jobId, $userId, $schema, $view, $options);

        return $jobId;
    }

    /**
     * Obtiene el status de un export por job_id.
     */
    public static function getStatus(string $jobId): ?array
    {
        return Cache::get("fabric_export:{$jobId}");
    }

    /**
     * Limpia el archivo temporal después de la descarga.
     */
    public static function cleanup(string $jobId): void
    {
        $status = self::getStatus($jobId);
        if ($status && isset($status['path'])) {
            Storage::disk('local')->deleteDirectory("fabric_exports/{$jobId}");
        }
        Cache::forget("fabric_export:{$jobId}");
    }
}
