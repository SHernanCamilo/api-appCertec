<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BiParquetConfig;
use App\Models\BiParquetHistory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Diagnostica por qué un parquet aparece "stale" aunque el monitor muestre
 * ejecuciones "Ok". Cruza TRES fuentes sin modificar nada:
 *
 *   1. La config de Laravel (bi_parquet_config): intervalo, enabled, prioridad.
 *   2. El estado que reporta Graph-Fabric (/api/r2/status): edad real, estado.
 *   3. El schedule que Graph tiene registrado (/api/r2/schedule): ¿conoce la
 *      vista?, ¿con qué intervalo?, ¿cuándo le toca?
 *   4. El historial local (bi_parquet_history): ¿la edad baja alguna vez o
 *      siempre ronda las mismas horas? (prueba de que NO se regenera).
 *
 * USO:
 *   php artisan fabric:diagnose-parquet df VW_HC_Resolucion_4331_Dic_2012_AutorizacionServicios_Nva
 */
final class DiagnoseParquetCommand extends Command
{
    protected $signature = 'fabric:diagnose-parquet
        {schema : Esquema, ej: df}
        {view : Nombre de la vista}';

    protected $description = 'Diagnostica por qué un parquet queda stale aunque el monitor muestre "Ok"';

    public function handle(): int
    {
        $schema = (string) $this->argument('schema');
        $view   = (string) $this->argument('view');
        $key    = "{$schema}.{$view}";

        $baseUrl = config('fabric.url', 'http://127.0.0.1:8001');
        $token   = config('fabric.token_admin', '');

        $this->line("Vista: {$key}");
        $this->line("Graph: {$baseUrl}");
        $this->newLine();

        // ── 1. Config en Laravel ─────────────────────────────────────────────
        $this->info('[1/4] Config en Laravel (bi_parquet_config)...');
        $cfg = BiParquetConfig::forView($schema, $view)->first();

        if (!$cfg) {
            $this->error('  >> NO existe config para esta vista en bi_parquet_config.');
            $this->line('  >> Sin config, Graph-Fabric no la tiene en su schedule y nunca la regenera.');
            $this->line('  >> Solucion: importar/crear la config y sincronizar con Graph.');
        } else {
            $this->line('  Intervalo    : ' . $cfg->refresh_interval_min . ' min');
            $this->line('  Prioridad    : ' . $cfg->priority);
            $this->line('  Grupo        : ' . $cfg->group_name);
            $this->line('  Habilitada   : ' . ($cfg->enabled ? 'SI' : 'NO'));
            $this->line('  Ult. sync    : ' . ($cfg->last_synced_at ?? 'nunca'));

            if (!$cfg->enabled) {
                $this->warn('  >> La vista esta DESHABILITADA: Graph no la regenera. Ahi esta la causa.');
            }
        }
        $this->newLine();

        // ── 2. Estado real en Graph-Fabric ───────────────────────────────────
        $this->info('[2/4] Estado real en Graph-Fabric (/api/r2/status)...');
        $ageHours = null;
        try {
            $resp = Http::timeout(20)->get("{$baseUrl}/api/r2/status", ['token' => $token]);
            if ($resp->failed()) {
                $this->error('  >> Graph respondio HTTP ' . $resp->status());
            } else {
                $views = $resp->json('data.views', $resp->json('views', []));
                $match = collect($views)->first(fn ($v) => ($v['schema'] ?? '') === $schema && ($v['view'] ?? '') === $view);

                if (!$match) {
                    $this->error('  >> Graph-Fabric NO reporta esta vista en su status.');
                    $this->line('  >> Si no aparece aqui, Graph no la conoce: por eso nunca la genera.');
                } else {
                    $ageHours = $match['age_hours'] ?? null;
                    $this->line('  Estado       : ' . ($match['status'] ?? '?'));
                    $this->line('  Edad parquet : ' . ($ageHours !== null ? round((float) $ageHours, 1) . ' h' : '?'));
                    $this->line('  Tamanio      : ' . ($match['size_mb'] ?? '?') . ' MB');
                    $this->line('  Filas        : ' . ($match['row_count'] ?? '?'));
                    $this->line('  Error        : ' . ($match['error_message'] ?? 'ninguno'));

                    if ($cfg && $ageHours !== null) {
                        $stale = ($ageHours * 60) > $cfg->refresh_interval_min;
                        $this->line('  stale/config : ' . ($stale ? 'SI' : 'NO')
                            . " (edad {$ageHours}h vs intervalo {$cfg->refresh_interval_min}min)");
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->error('  >> No se pudo consultar Graph: ' . $e->getMessage());
        }
        $this->newLine();

        // ── 3. Schedule de Graph: ¿la conoce y le toca? ──────────────────────
        $this->info('[3/4] Schedule en Graph-Fabric (/api/r2/schedule)...');
        try {
            $resp = Http::timeout(20)->get("{$baseUrl}/api/r2/schedule", ['token' => $token]);
            if ($resp->failed()) {
                $this->error('  >> Graph respondio HTTP ' . $resp->status());
            } else {
                $sched = collect($resp->json('views', []))
                    ->first(fn ($v) => ($v['schema'] ?? '') === $schema && ($v['view'] ?? '') === $view);

                if (!$sched) {
                    $this->error('  >> La vista NO esta en el schedule de Graph.');
                    $this->line('  >> CAUSA PROBABLE: Graph no tiene programada su regeneracion.');
                    $this->line('  >> Solucion: php artisan fabric:sync-parquet-config (reenvia la config).');
                } else {
                    $this->line('  En schedule  : SI');
                    $this->line('  Intervalo    : ' . ($sched['refresh_interval_min'] ?? $sched['interval_min'] ?? '?') . ' min');
                    $this->line('  Prioridad    : ' . ($sched['priority'] ?? '?'));
                    $this->line('  avg_gen_s    : ' . ($sched['avg_generation_s'] ?? 'sin medir (carril "nueva")'));
                    $this->line('  Proxima/due  : ' . ($sched['next_run'] ?? $sched['due'] ?? '?'));
                }
            }
        } catch (\Throwable $e) {
            $this->error('  >> No se pudo consultar el schedule: ' . $e->getMessage());
        }
        $this->newLine();

        // ── 4. Historial local: ¿la edad baja alguna vez? ────────────────────
        $this->info('[4/4] Historial local (bi_parquet_history, ultimas 20 fotos)...');
        $hist = BiParquetHistory::forView($schema, $view)
            ->orderByDesc('captured_at')
            ->limit(20)
            ->get(['captured_at', 'status', 'age_hours', 'is_stale_by_config', 'size_mb']);

        if ($hist->isEmpty()) {
            $this->warn('  >> Sin historial local todavia.');
        } else {
            foreach ($hist as $h) {
                $this->line(sprintf('  %s | %-8s | edad %5sh | %s',
                    (string) $h->captured_at,
                    (string) $h->status,
                    $h->age_hours !== null ? round((float) $h->age_hours, 1) : '?',
                    $h->is_stale_by_config ? 'stale/config' : 'fresca'
                ));
            }

            $edades = $hist->pluck('age_hours')->filter()->values();
            if ($edades->count() >= 2) {
                $min = round((float) $edades->min(), 1);
                $max = round((float) $edades->max(), 1);
                $this->newLine();
                if ($min > 1.0) {
                    $this->error("  >> La edad NUNCA baja de {$min}h en las ultimas fotos.");
                    $this->error('  >> Eso PRUEBA que el parquet NO se esta regenerando (si lo hiciera,');
                    $this->error('     la edad caeria a ~0 tras cada regeneracion). El problema esta en');
                    $this->error('     Graph-Fabric: recibe el schedule pero no ejecuta la generacion,');
                    $this->error('     o la ejecuta y falla en escribir/actualizar el parquet.');
                } else {
                    $this->info("  >> La edad baja hasta {$min}h: el parquet SI se regenera a veces.");
                    $this->line('  >> Si aun asi sale stale, el intervalo configurado es mas corto que');
                    $this->line('     el tiempo real de regeneracion. Subir refresh_interval_min.');
                }
            }
        }

        $this->newLine();
        $this->line('Tip: para forzar una regeneracion manual y ver si Graph responde, use el');
        $this->line('     boton "rayo" (⚡) de la vista en el Monitor, o el endpoint de refresh.');

        return self::SUCCESS;
    }
}
