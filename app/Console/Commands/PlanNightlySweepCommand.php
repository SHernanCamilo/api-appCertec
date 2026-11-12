<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Fabric\GraphFabricService;
use Illuminate\Console\Command;

/**
 * Calcula cuanto demoraria el barrido de las 04:00 con N consultas a la vez.
 *
 * Lee del scheduler de Graph-Fabric el tiempo REAL de generacion de cada vista
 * activa (`avg_gen_s`, `last_gen_s`) y simula el reparto entre N trabajadores.
 * Solo lee: no genera ni cambia nada.
 *
 * Uso (en el VPS, donde Graph-Fabric tiene los tiempos reales):
 *   php artisan fabric:plan-nightly
 *   php artisan fabric:plan-nightly --start=04:00 --default-s=90
 */
class PlanNightlySweepCommand extends Command
{
    protected $signature = 'fabric:plan-nightly
        {--start=04:00 : Hora de inicio del barrido (HH:MM)}
        {--default-s=60 : Segundos a suponer para vistas sin tiempo medido}
        {--cut=600 : Segundos tras los cuales Graph manda una vista al final (barrido nocturno)}';

    protected $description = 'Estima cuanto demora el barrido de las 04:00 con 1 a 6 consultas simultaneas (solo lectura)';

    public function handle(GraphFabricService $graph): int
    {
        $res = $graph->schedule();
        if (! $res['ok']) {
            $this->error($res['message'] ?? 'Graph-Fabric no respondio.');

            return 1;
        }

        $default = max(1, (int) $this->option('default-s'));
        $cut     = max(1, (int) $this->option('cut'));

        $items = [];
        $medidas = 0;
        foreach (($res['data']['views'] ?? []) as $v) {
            if (array_key_exists('is_active', $v) && ! $v['is_active']) {
                continue;
            }
            $s = $v['avg_gen_s'] ?? $v['last_gen_s'] ?? null;
            $medida = $s !== null && (float) $s > 0;
            $medidas += $medida ? 1 : 0;
            $items[] = [
                'name'   => ($v['schema'] ?? '?') . '.' . ($v['view'] ?? '?'),
                's'      => $medida ? (float) $s : (float) $default,
                'medida' => $medida,
                'rows'   => (int) ($v['row_count'] ?? 0),
            ];
        }

        $n = count($items);
        if ($n === 0) {
            $this->warn('El schedule de Graph-Fabric no tiene vistas activas.');

            return 0;
        }

        $this->info("=== Barrido de las {$this->option('start')} ===");
        $this->line(sprintf('Vistas activas: %d | con tiempo medido: %d | supuestas a %d s: %d', $n, $medidas, $default, $n - $medidas));
        if ($medidas < $n * 0.5) {
            $this->warn('Menos de la mitad tiene tiempo medido: el calculo es una aproximacion gruesa.');
        }

        $total = array_sum(array_column($items, 's'));
        $this->line(sprintf('Trabajo total (suma de tiempos): %.0f s = %.1f min = %.2f h', $total, $total / 60, $total / 3600));

        // Distribucion por carril (mismos cortes que usa Graph-Fabric)
        $b = ['<= 30 s (sprint)' => 0, '30-180 s (standard)' => 0, '3-15 min (heavy)' => 0, '> 15 min (marathon)' => 0];
        foreach ($items as $i) {
            $k = $i['s'] <= 30 ? '<= 30 s (sprint)' : ($i['s'] <= 180 ? '30-180 s (standard)' : ($i['s'] <= 900 ? '3-15 min (heavy)' : '> 15 min (marathon)'));
            $b[$k]++;
        }
        $this->newLine();
        $this->table(['Carril', 'Vistas'], collect($b)->map(fn ($v, $k): array => [$k, $v])->values()->all());

        $over = array_filter($items, fn (array $i): bool => $i['s'] > $cut);
        $this->line(sprintf('Pasan el corte de %d s (van a la segunda pasada, de una en una): %d', $cut, count($over)));

        usort($items, fn (array $a, array $b2): int => $b2['s'] <=> $a['s']);
        $this->newLine();
        $this->line('Las 12 mas lentas:');
        foreach (array_slice($items, 0, 12) as $i) {
            $this->line(sprintf('  %-64s %6.0f s%s', substr($i['name'], 0, 64), $i['s'], $i['medida'] ? '' : '  (supuesto)'));
        }

        // Simulacion: reparto en lista (cada vista va al trabajador que se libera primero)
        $asc  = array_column(array_reverse($items), 's');   // de menos a mas pesadas
        $desc = array_column($items, 's');                  // pesadas primero
        [$h, $m] = array_map('intval', explode(':', (string) $this->option('start')) + [0, 0]);
        $start = $h * 60 + $m;

        $filas = [];
        foreach ([1, 2, 3, 4, 5, 6] as $c) {
            $mkAsc  = $this->makespan($asc, $c);
            $mkDesc = $this->makespan($desc, $c);
            $filas[] = [
                $c,
                sprintf('%.0f min (%.1f h)', $mkAsc / 60, $mkAsc / 3600),
                $this->clock($start + (int) ceil($mkAsc / 60)),
                sprintf('%.0f min', $mkDesc / 60),
                $this->clock($start + (int) ceil($mkDesc / 60)),
            ];
        }

        $this->newLine();
        $this->line('Duracion estimada segun cuantas consultas van a la vez:');
        $this->table(['A la vez', 'De menos a mas pesadas', 'Termina', 'Pesadas primero', 'Termina'], $filas);
        $this->line('Es el tiempo de generacion de las vistas; no incluye subir a R2 ni esperas por cupo.');

        return 0;
    }

    /** Tiempo total (s) repartiendo la lista, en orden, entre $workers trabajadores. */
    private function makespan(array $segundos, int $workers): float
    {
        $libre = array_fill(0, $workers, 0.0);
        foreach ($segundos as $s) {
            $k = array_search(min($libre), $libre, true);
            $libre[$k] += $s;
        }

        return max($libre);
    }

    private function clock(int $minutes): string
    {
        $dia = intdiv($minutes, 1440);
        $m   = $minutes % 1440;

        return sprintf('%02d:%02d%s', intdiv($m, 60), $m % 60, $dia > 0 ? ' (+1 dia)' : '');
    }
}
