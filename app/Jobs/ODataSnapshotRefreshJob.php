<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Fabric\ODataSnapshotService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Refresca un snapshot de OData en segundo plano (patrón stale-while-revalidate).
 *
 * Se despacha cuando una petición encuentra el snapshot vencido:
 *   - La petición sirve el snapshot viejo al instante (nadie espera)
 *   - Este job regenera el snapshot en background
 *   - La siguiente petición ya recibe datos frescos
 *
 * ShouldBeUnique evita que varias peticiones concurrentes disparen
 * múltiples refrescos del mismo dataset.
 */
final class ODataSnapshotRefreshJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 1;
    public int $timeout = 600; // 10 min

    /** Segundos que el lock de unicidad permanece activo. */
    public int $uniqueFor = 900;

    public function __construct(
        private readonly string $linkCode,
        private readonly array  $context
    ) {
        // Cola dedicada: no compite con notificaciones ni exports
        $this->onQueue('snapshots');
    }

    /**
     * Clave de unicidad: mismo link + mismo contexto = un solo job en vuelo.
     */
    public function uniqueId(): string
    {
        return $this->linkCode . ':' . md5(json_encode($this->context));
    }

    public function handle(ODataSnapshotService $snapshots): void
    {
        $t0 = microtime(true);

        try {
            $result = $snapshots->refresh($this->linkCode, $this->context);

            Log::info('ODataSnapshotRefreshJob completado', [
                'link'       => $this->linkCode,
                'schema'     => $this->context['schema'] ?? null,
                'view'       => $this->context['view'] ?? null,
                'rows'       => $result['rows'] ?? 0,
                'source'     => $result['source'] ?? '?',
                'elapsed_ms' => (int) round((microtime(true) - $t0) * 1000),
            ]);
        } catch (\Throwable $e) {
            // Falla silenciosa: el snapshot viejo sigue sirviendo.
            Log::warning('ODataSnapshotRefreshJob falló', [
                'link'  => $this->linkCode,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
