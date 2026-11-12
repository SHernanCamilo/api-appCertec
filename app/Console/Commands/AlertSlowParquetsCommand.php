<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BiParquetIncident;
use App\Services\Fabric\GraphFabricService;
use App\Services\Fabric\ParquetIncidentNotifier;
use Illuminate\Console\Command;

/**
 * Vigila las generaciones de parquet que superan el umbral (5 min por defecto).
 *
 * Corre cada minuto. Lee `generating_now` de Graph-Fabric y:
 *   - abre UN incidente por vista lenta y avisa una sola vez,
 *   - actualiza el tiempo mientras siga corriendo,
 *   - cierra el incidente cuando la vista deja de estar generándose.
 *
 * Si Graph-Fabric no responde, no es un fallo del cron: se omite este ciclo.
 */
class AlertSlowParquetsCommand extends Command
{
    protected $signature   = 'fabric:alert-slow-parquets';
    protected $description = 'Detecta parquets que llevan mas de 5 minutos generandose y notifica una sola vez';

    public function handle(GraphFabricService $graph, ParquetIncidentNotifier $notifier): int
    {
        $res = $graph->statusLive();
        if (! $res['ok']) {
            $this->warn('Graph-Fabric no respondio; se omite este ciclo.');

            return 0;
        }

        $limite = (int) config('fabric.slow_threshold_s', 300);
        $lentas = [];

        foreach (($res['data']['generating_now'] ?? []) as $g) {
            $partes = explode('.', (string) ($g['qualified_name'] ?? ''), 2);
            if (count($partes) !== 2 || $partes[0] === '' || $partes[1] === '') {
                continue;
            }

            $corriendo = (int) round((float) ($g['running_s'] ?? 0));
            if ($corriendo >= $limite || ! empty($g['is_stuck'])) {
                $lentas[strtolower($partes[0] . '.' . $partes[1])] = [
                    'schema'    => strtolower($partes[0]),
                    'view'      => $partes[1],
                    'elapsed_s' => $corriendo,
                    'stage'     => $g['stage'] ?? null,
                ];
            }
        }

        foreach ($lentas as $s) {
            $incidente = BiParquetIncident::open()->forView($s['schema'], $s['view'])
                ->where('reason', BiParquetIncident::REASON_TIMEOUT)->first();

            if ($incidente) {
                // Ya estaba abierto (quizas lo reporto un usuario): solo se actualiza el tiempo.
                $incidente->update(['elapsed_s' => max($incidente->elapsed_s, $s['elapsed_s']), 'stage' => $s['stage']]);
                continue;
            }

            $incidente = BiParquetIncident::create([
                'schema_name' => $s['schema'],
                'view_name'   => $s['view'],
                'source'      => BiParquetIncident::SOURCE_SCHEDULER,
                'reason'      => BiParquetIncident::REASON_TIMEOUT,
                'elapsed_s'   => $s['elapsed_s'],
                'stage'       => $s['stage'],
                'status'      => BiParquetIncident::STATUS_OPEN,
            ]);
            $notifier->notify($incidente);
            $this->line("  NUEVO  {$s['schema']}.{$s['view']} ({$s['elapsed_s']}s)");
        }

        // Cerrar los que ya no estan generandose. Se espera 2 min desde su
        // apertura para no cerrar uno recien reportado por un usuario antes de
        // que Graph-Fabric lo muestre como lento.
        $resueltos = 0;
        BiParquetIncident::open()->where('reason', BiParquetIncident::REASON_TIMEOUT)
            ->where('created_at', '<=', now()->subMinutes(2))->get()
            ->each(function (BiParquetIncident $i) use ($lentas, &$resueltos): void {
                if (! isset($lentas[strtolower("{$i->schema_name}.{$i->view_name}")])) {
                    $i->update(['status' => BiParquetIncident::STATUS_RESOLVED, 'resolved_at' => now()]);
                    $resueltos++;
                }
            });

        $this->info(sprintf('Lentas: %d | Cerradas: %d', count($lentas), $resueltos));

        return 0;
    }
}
