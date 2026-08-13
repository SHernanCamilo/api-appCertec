<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Muestra el estado de los carriles de export de Graph-Fabric.
 *
 * Graph-Fabric aplica el patrón bulkhead: separa los exports en tres carriles
 * con semáforos independientes según el tamaño de la vista, para que una vista
 * de 460K filas no bloquee a una de 5K.
 *
 *   fast   (<50K filas)     → 8 slots
 *   medium (50K-200K filas)  → 4 slots
 *   heavy  (>200K filas)     → 3 slots
 *
 * Si un carril queda con free=0 hay saturación en ese rango de tamaño.
 *
 * Uso:
 *   php artisan fabric:lanes
 *   php artisan fabric:lanes --watch
 */
final class FabricLanesStatus extends Command
{
    protected $signature = 'fabric:lanes
        {--watch : Refresca cada 3 segundos hasta Ctrl+C}';

    protected $description = 'Estado de los carriles de export de Graph-Fabric y de la cola de Horizon';

    public function handle(): int
    {
        if (!$this->option('watch')) {
            return $this->mostrarEstado();
        }

        while (true) {
            $this->output->write("\033[2J\033[H"); // limpiar pantalla
            $this->line('<comment>Actualizado: ' . now()->format('H:i:s') . '</comment> (Ctrl+C para salir)');
            $this->newLine();
            $this->mostrarEstado();
            sleep(3);
        }
    }

    private function mostrarEstado(): int
    {
        $metricas = $this->consultarMetricas();

        if ($metricas === null) {
            $this->error('No se pudo consultar Graph-Fabric en ' . config('fabric.url'));
            $this->line('Verifique que el servicio Python esté activo:');
            $this->line('  curl -s -o /dev/null -w "%{http_code}" ' . config('fabric.url') . '/healthz');
            return self::FAILURE;
        }

        $this->mostrarCarriles($metricas);
        $this->mostrarColaHorizon();

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function consultarMetricas(): ?array
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders(['X-API-Key' => config('fabric.api_key', '')])
                ->get(rtrim(config('fabric.url'), '/') . '/api/metrics/service', [
                    'token' => config('fabric.token_admin', ''),
                ]);

            return $response->successful() ? $response->json() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $metricas
     */
    private function mostrarCarriles(array $metricas): void
    {
        $lanes = $metricas['export_lanes']['lanes'] ?? null;

        if (!is_array($lanes)) {
            $this->warn('Graph-Fabric respondió pero sin datos de export_lanes.');
            $this->line('Puede ser una versión anterior a los carriles de export.');
            return;
        }

        $this->info('Carriles de export (Graph-Fabric)');

        $filas = [];
        foreach ($lanes as $nombre => $lane) {
            $capacidad = (int) ($lane['capacity'] ?? 0);
            $libres    = (int) ($lane['free'] ?? 0);
            $enUso     = max(0, $capacidad - $libres);

            $filas[] = [
                $nombre,
                $this->rangoDeCarril($nombre, $metricas),
                "{$enUso}/{$capacidad}",
                $this->barra($enUso, $capacidad),
                $libres === 0 ? '<fg=red>SATURADO</>' : '<fg=green>OK</>',
            ];
        }

        $this->table(['Carril', 'Rango de filas', 'En uso', 'Ocupación', 'Estado'], $filas);
    }

    /**
     * @param array<string, mixed> $metricas
     */
    private function rangoDeCarril(string $nombre, array $metricas): string
    {
        $umbrales = $metricas['export_lanes']['thresholds'] ?? [];
        $rapido   = (int) ($umbrales['fast_below_rows'] ?? 50000);
        $pesado   = (int) ($umbrales['heavy_above_rows'] ?? 200000);

        return match ($nombre) {
            'fast'   => '< ' . number_format($rapido),
            'medium' => number_format($rapido) . ' - ' . number_format($pesado),
            'heavy'  => '> ' . number_format($pesado),
            default  => '—',
        };
    }

    private function barra(int $enUso, int $capacidad): string
    {
        if ($capacidad <= 0) {
            return '—';
        }

        $ancho  = 20;
        $llenos = (int) round(($enUso / $capacidad) * $ancho);
        $color  = $enUso >= $capacidad ? 'red' : ($enUso > $capacidad / 2 ? 'yellow' : 'green');

        return "<fg={$color}>" . str_repeat('#', $llenos) . '</>' . str_repeat('.', $ancho - $llenos);
    }

    private function mostrarColaHorizon(): void
    {
        $this->newLine();
        $this->info('Cola de exports (Horizon)');

        try {
            $redis    = app('redis');
            $pendientes = (int) $redis->llen('queues:exports');

            $workers = (int) (config('horizon.environments.' . app()->environment() . '.export-workers.maxProcesses')
                ?? config('horizon.environments.production.export-workers.maxProcesses')
                ?? 1);

            $this->table(
                ['Jobs en espera', 'Workers PHP', 'Timeout job', 'Máx. filas'],
                [[
                    $pendientes,
                    $workers,
                    config('fabric.export_timeout', 600) . 's',
                    number_format((int) config('fabric.max_export_rows', 1000000)),
                ]]
            );

            if ($pendientes > $workers * 3) {
                $this->warn("Hay {$pendientes} exports en espera con {$workers} workers: la cola se está acumulando.");
            }
        } catch (\Throwable $e) {
            $this->warn('No se pudo leer la cola de Redis: ' . $e->getMessage());
        }
    }
}
