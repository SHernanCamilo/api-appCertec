<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Fabric\Export\StreamingExportWriter;
use App\Services\Fabric\GraphAsyncExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Convierte el NDJSON.gz de Graph-Fabric en un .xlsx listo para descargar.
 *
 * ¿Por qué en cola y no en la petición de descarga?
 * Convertir 567K filas tarda 1-2 min. Hacerlo dentro del request HTTP provocaba
 * timeouts (Apache/proxy), el navegador caía al fallback window.open, y el
 * usuario terminaba en /auth/login con un 405.
 *
 * Ahora: cuando Graph marca el export como listo, se encola esta conversión.
 * El polling que ya existe muestra "Generando Excel..." y cuando el archivo
 * está en disco la descarga es instantánea (solo sirve el archivo).
 *
 * Este job NO habla con Fabric — solo baja el .gz de Graph (localhost) y
 * escribe el xlsx con OpenSpout en streaming (~5 MB RAM fijos). Por eso es
 * rápido y confiable, a diferencia del export job anterior.
 */
final class ConvertGraphExportToXlsxJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 1;
    public int $timeout = 900; // 15 min: margen para vistas de ~1M filas

    public function __construct(
        private readonly string $jobId,
        private readonly string $schema,
        private readonly string $view,
    ) {
        $this->onQueue('exports');
    }

    public function handle(GraphAsyncExportService $exportService): void
    {
        ini_set('memory_limit', '512M');
        set_time_limit(0);

        $cacheKey = self::cacheKey($this->jobId);

        Cache::put($cacheKey, [
            'status'  => 'converting',
            'message' => 'Generando Excel...',
        ], 1800);

        try {
            // 1. Bajar el NDJSON.gz de Graph (localhost, rápido)
            $download = $exportService->download($this->jobId);

            if (($download['success'] ?? false) !== true) {
                $this->fail($cacheKey, $download['message'] ?? 'No se pudo obtener los datos del export.');
                return;
            }

            $gzPath = (string) $download['path'];
            $dir    = dirname($gzPath);
            $base   = "{$this->schema}_{$this->view}_" . now()->format('Ymd_His');

            // 2. Convertir a xlsx en streaming (línea por línea, sin cargar en RAM)
            $result = StreamingExportWriter::fromNdjsonGzFile($gzPath, $dir, $base, $this->schema, $this->view);

            @unlink($gzPath); // el .gz ya no se necesita

            if ($result->isEmpty()) {
                $this->fail($cacheKey, 'El export no devolvio datos.');
                return;
            }

            // 3. Publicar el archivo listo
            Cache::put($cacheKey, [
                'status'          => 'ready',
                'path'            => $result->path,
                'filename'        => $result->filename,
                'rows'            => $result->rows,
                'bytes'           => $result->bytes,
                'format'          => $result->format,
                'file_size_human' => $result->humanSize(),
                'message'         => number_format($result->rows) . ' filas listas para descargar.',
            ], 1800);

            Log::info('[ConvertGraphExport] xlsx generado', [
                'job_id'   => $this->jobId,
                'view'     => "{$this->schema}.{$this->view}",
                'rows'     => $result->rows,
                'bytes'    => $result->bytes,
                'format'   => $result->format,
                'filename' => $result->filename,
            ]);
        } catch (\Throwable $e) {
            Log::error('[ConvertGraphExport] error', [
                'job_id' => $this->jobId,
                'view'   => "{$this->schema}.{$this->view}",
                'error'  => $e->getMessage(),
            ]);

            $this->fail(
                $cacheKey,
                str_contains(strtolower($e->getMessage()), 'memory')
                    ? 'La vista excede la memoria disponible. Aplique filtros para reducir los datos.'
                    : 'No se pudo generar el Excel. Intente de nuevo.'
            );
        }
    }

    /** Marca la conversión como fallida en cache. */
    private function fail(string $cacheKey, string $message): void
    {
        Cache::put($cacheKey, ['status' => 'failed', 'message' => $message], 1800);
    }

    public function failed(\Throwable $e): void
    {
        $this->fail(self::cacheKey($this->jobId), 'La generacion del Excel fallo o excedio el tiempo maximo.');

        Log::error('[ConvertGraphExport] job fallido', [
            'job_id' => $this->jobId,
            'error'  => $e->getMessage(),
        ]);
    }

    public static function cacheKey(string $jobId): string
    {
        return "graph_xlsx:{$jobId}";
    }
}
