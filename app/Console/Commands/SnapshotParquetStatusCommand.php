<?php

namespace App\Console\Commands;

use App\Models\BiParquetConfig;
use App\Models\BiParquetHistory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Captura un snapshot del estado de todos los parquets de Graph-Fabric
 * y lo guarda en bi_parquet_history para trazabilidad.
 *
 * Permite responder:
 *   - ¿Cuáles vistas nunca se regeneran? (siempre stale)
 *   - ¿Qué carril está represado?
 *   - ¿Cuánto tarda en promedio cada vista?
 *
 * Usage:
 *   php artisan fabric:snapshot-parquet-status
 *
 * En Kernel.php (recomendado cada 15 min):
 *   $schedule->command('fabric:snapshot-parquet-status')->everyFifteenMinutes();
 */
class SnapshotParquetStatusCommand extends Command
{
    protected $signature   = 'fabric:snapshot-parquet-status {--prune=7 : Dias de historial a conservar}';
    protected $description = 'Guarda snapshot del estado de parquets en bi_parquet_history';

    public function handle(): int
    {
        $baseUrl = config('fabric.url', 'http://127.0.0.1:8001');
        $token   = config('fabric.token_admin', '');

        $this->info('Capturando estado de Graph-Fabric...');

        try {
            // 1. Estado de los parquets (status)
            $statusResp = Http::timeout(30)->get("{$baseUrl}/api/r2/status", ['token' => $token]);
            if (!$statusResp->successful()) {
                $this->error("status HTTP {$statusResp->status()}");
                return 1;
            }

            // 2. Schedule (para avg_generation_s / carril)
            $scheduleResp = Http::timeout(30)->get("{$baseUrl}/api/r2/schedule", ['token' => $token]);
            $scheduleViews = [];
            if ($scheduleResp->successful()) {
                foreach (($scheduleResp->json()['views'] ?? []) as $v) {
                    $key = ($v['schema'] ?? '') . '.' . ($v['view'] ?? '');
                    $scheduleViews[$key] = $v;
                }
            }

            $statusData = $statusResp->json();
            $views      = $statusData['data']['views'] ?? $statusData['views'] ?? [];

            if (empty($views)) {
                $this->warn('Graph-Fabric no reporto vistas.');
                return 0;
            }

            // Config de Laravel para calcular is_stale_by_config
            $configs = BiParquetConfig::enabled()->get()
                ->keyBy(fn($c) => "{$c->schema_name}.{$c->view_name}");

            $now  = now();
            $rows = [];

            foreach ($views as $view) {
                $schema   = $view['schema'] ?? '';
                $viewName = $view['view'] ?? '';
                if (!$schema || !$viewName) continue;

                $key       = "{$schema}.{$viewName}";
                $sched     = $scheduleViews[$key] ?? [];
                $avgGen    = $sched['avg_generation_s'] ?? ($view['avg_generation_s'] ?? null);
                $ageHours  = $view['age_hours'] ?? null;

                // Calcular si esta stale segun config de Laravel
                $isStaleByConfig = false;
                $cfg = $configs->get($key);
                if ($cfg && $ageHours !== null) {
                    $isStaleByConfig = ($ageHours * 60) > $cfg->refresh_interval_min;
                }

                $rows[] = [
                    'schema_name'        => $schema,
                    'view_name'          => $viewName,
                    'status'             => $view['status'] ?? 'unknown',
                    'lane'               => $this->laneFromAvg($avgGen),
                    'age_hours'          => $ageHours,
                    'avg_generation_s'   => $avgGen,
                    'size_mb'            => $view['size_mb'] ?? null,
                    'row_count'          => $view['row_count'] ?? null,
                    'is_stale_by_config' => $isStaleByConfig,
                    'error_message'      => $view['error_message'] ?? null,
                    'captured_at'        => $now,
                ];
            }

            // Insertar en chunks para no saturar
            foreach (array_chunk($rows, 200) as $chunk) {
                BiParquetHistory::insert($chunk);
            }

            $this->info("Snapshot guardado: " . count($rows) . " vistas.");

            // Resumen por estado
            $byStatus = collect($rows)->groupBy('status')->map->count();
            foreach ($byStatus as $st => $cnt) {
                $this->line("  {$st}: {$cnt}");
            }

            // Prune historial viejo
            $pruneDays = (int) $this->option('prune');
            if ($pruneDays > 0) {
                $deleted = BiParquetHistory::where('captured_at', '<', now()->subDays($pruneDays))->delete();
                if ($deleted > 0) {
                    $this->line("Historial: {$deleted} registros antiguos eliminados (>{$pruneDays}d).");
                }
            }

            return 0;
        } catch (\Exception $e) {
            $this->error("Error: {$e->getMessage()}");
            Log::error('[SnapshotParquetStatus] Error', ['error' => $e->getMessage()]);
            return 1;
        }
    }

    private function laneFromAvg(?float $avg): ?string
    {
        if ($avg === null) return null;
        if ($avg <= 30)  return 'sprint';
        if ($avg <= 180) return 'standard';
        if ($avg <= 900) return 'heavy';
        return 'marathon';
    }
}
