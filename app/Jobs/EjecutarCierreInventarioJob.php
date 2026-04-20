<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use App\Services\CierreInventarioService;

/**
 * Job: EjecutarCierreInventarioJob
 *
 * Ejecuta el proceso de cierre de inventario de forma asíncrona (o síncrona
 * si QUEUE_CONNECTION=sync). Delega toda la lógica al CierreInventarioService.
 *
 * Uso:
 *   EjecutarCierreInventarioJob::dispatch($cierreId);
 */
class EjecutarCierreInventarioJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Número de reintentos si el Job falla.
     * Con sync driver esto no aplica, pero es buena práctica definirlo.
     */
    public int $tries = 1;

    /**
     * Timeout máximo en segundos.
     * Un cierre con miles de activos puede tardar varios minutos.
     */
    public int $timeout = 600; // 10 minutos

    public function __construct(
        public readonly int $cierreId
    ) {}

    /**
     * Ejecutar el Job.
     */
    public function handle(CierreInventarioService $service): void
    {
        Log::channel('glpi_sync')->info('Job EjecutarCierreInventario iniciado', [
            'cierre_id' => $this->cierreId,
        ]);

        $service->ejecutar($this->cierreId);

        Log::channel('glpi_sync')->info('Job EjecutarCierreInventario finalizado', [
            'cierre_id' => $this->cierreId,
        ]);
    }

    /**
     * Manejar fallo del Job (solo aplica con queue driver != sync).
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Job EjecutarCierreInventario falló', [
            'cierre_id' => $this->cierreId,
            'error'     => $exception->getMessage(),
            'trace'     => $exception->getTraceAsString(),
        ]);

        // Marcar el cierre como error si el Job falla fuera del service
        try {
            \App\Models\MatrizObsolescencia\MatzobsCierre::where('id', $this->cierreId)
                ->whereIn('estado', ['pendiente', 'procesando'])
                ->update([
                    'estado'          => 'error',
                    'mensaje_error'   => 'El Job falló inesperadamente: ' . $exception->getMessage(),
                    'fecha_fin_proceso' => now(),
                ]);
        } catch (\Throwable $e) {
            Log::error('No se pudo marcar el cierre como error', ['e' => $e->getMessage()]);
        }
    }
}
