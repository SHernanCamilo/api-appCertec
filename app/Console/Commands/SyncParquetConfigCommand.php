<?php

namespace App\Console\Commands;

use App\Models\BiParquetConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sincroniza todas las configuraciones de parquet con Graph-Fabric.
 *
 * Se ejecuta cada 5 minutos via scheduler para garantizar que Graph-Fabric
 * siempre tiene la misma configuracion que Laravel (por si se reinicio,
 * perdio su schedule.db, o alguien cambio algo manualmente).
 *
 * Usage:
 *   php artisan fabric:sync-parquet-config
 *
 * En el Kernel.php:
 *   $schedule->command('fabric:sync-parquet-config')->everyFiveMinutes();
 */
class SyncParquetConfigCommand extends Command
{
    protected $signature   = 'fabric:sync-parquet-config {--status : Solo mostrar estado, no sincronizar}';
    protected $description = 'Sincroniza configuraciones de parquet con Graph-Fabric /api/r2/schedule';

    public function handle(): int
    {
        $baseUrl = config('fabric.url', 'http://127.0.0.1:8001');
        $token   = config('fabric.token_admin', '');

        if ($this->option('status')) {
            return $this->showStatus($baseUrl, $token);
        }

        $configs = BiParquetConfig::enabled()->get();

        if ($configs->isEmpty()) {
            $this->info('No hay configuraciones de parquet habilitadas.');
            return 0;
        }

        $this->info("Sincronizando {$configs->count()} configuraciones con Graph-Fabric...");

        $synced = 0;
        $failed = 0;

        foreach ($configs as $config) {
            try {
                $priorityMap = [
                    'realtime' => 'realtime', 'high' => 'operativo', 'medium' => 'operativo',
                    'low' => 'analitico', 'manual' => 'analitico',
                    'operativo' => 'operativo', 'analitico' => 'analitico',
                ];

                $response = Http::timeout(10)->post("{$baseUrl}/api/r2/schedule", [
                    'token'                => $token,
                    'schema_name'          => $config->schema_name,
                    'view'                 => $config->view_name,
                    'refresh_interval_min' => $config->refresh_interval_min,
                    'priority'             => $priorityMap[$config->priority] ?? 'operativo',
                    'group_name'           => $config->group_name,
                ]);

                if ($response->successful()) {
                    $config->update(['last_synced_at' => now()]);
                    $synced++;
                    $this->line("  OK  {$config->qualifiedName()} ({$config->priority}, cada {$config->intervalLabel()})");
                } else {
                    $failed++;
                    $this->error("  FAIL {$config->qualifiedName()} - HTTP {$response->status()}");
                }
            } catch (\Exception $e) {
                $failed++;
                $this->error("  ERR  {$config->qualifiedName()} - {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info("Resultado: {$synced} OK, {$failed} fallidas de {$configs->count()} total.");

        if ($failed > 0) {
            Log::warning('[SyncParquetConfig] Algunas configs fallaron al sincronizar', [
                'synced' => $synced, 'failed' => $failed,
            ]);
        }

        return $failed > 0 ? 1 : 0;
    }

    private function showStatus(string $baseUrl, string $token): int
    {
        $this->info('Consultando estado de parquets en Graph-Fabric...');

        try {
            $response = Http::timeout(15)->get("{$baseUrl}/api/r2/status", [
                'token' => $token,
            ]);

            if (!$response->successful()) {
                $this->error("Graph-Fabric no respondio (HTTP {$response->status()})");
                return 1;
            }

            $data    = $response->json();
            $summary = $data['summary'] ?? [];

            $this->newLine();
            $this->info("Parquets totales: " . ($summary['total'] ?? '?'));
            $this->info("  OK:      " . ($summary['ok'] ?? '?'));
            $this->info("  Stale:   " . ($summary['stale'] ?? '?'));
            $this->info("  Missing: " . ($summary['missing'] ?? '?'));

            // Mostrar urgentes
            $urgent = $data['urgent_priority_views'] ?? [];
            if (!empty($urgent)) {
                $this->newLine();
                $this->warn('Vistas urgentes:');
                foreach ($urgent as $v) {
                    $this->warn("  {$v['qualified']} -> {$v['status']} | age: {$v['age_hours']}h");
                }
            }

            return 0;
        } catch (\Exception $e) {
            $this->error("No se pudo conectar: {$e->getMessage()}");
            return 1;
        }
    }
}
