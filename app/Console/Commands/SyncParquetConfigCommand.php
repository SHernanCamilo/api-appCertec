<?php

namespace App\Console\Commands;

use App\Models\BiParquetConfig;
use App\Models\BiVista;
use App\Services\Fabric\ParquetScheduleSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Deja el scheduler de Graph-Fabric igual a `bi_parquet_config`.
 *
 * Se ejecuta cada 30 min para garantizar que Graph-Fabric tiene la misma
 * configuración que Laravel (por si se reinició o perdió su schedule.db).
 *
 * Paso 1: las vistas marcadas INACTIVAS en bi_vistas se deshabilitan en
 *         bi_parquet_config.
 * Paso 2: se envía a Graph-Fabric la lista COMPLETA de vistas habilitadas en una
 *         sola llamada (`/api/r2/schedule/sync`). Graph desactiva lo que no esté
 *         en la lista, así no quedan vistas ejecutándose sin estar configuradas.
 *
 * Usage:
 *   php artisan fabric:sync-parquet-config
 */
class SyncParquetConfigCommand extends Command
{
    protected $signature   = 'fabric:sync-parquet-config {--status : Solo mostrar estado, no sincronizar}';
    protected $description = 'Sincroniza configuraciones de parquet con Graph-Fabric /api/r2/schedule/sync';

    public function handle(ParquetScheduleSyncService $sync): int
    {
        $baseUrl = config('fabric.url', 'http://127.0.0.1:8001');
        $token   = config('fabric.token_admin', '');

        if ($this->option('status')) {
            return $this->showStatus($baseUrl, $token);
        }

        // ── Paso 1: deshabilitar las vistas inactivas en bi_vistas ───────────
        $disabled = $this->disableInactiveConfigs($sync);
        if ($disabled > 0) {
            $this->warn("  {$disabled} vistas deshabilitadas en bi_parquet_config (inactivas en bi_vistas).");
            Log::info('[SyncParquetConfig] Vistas inactivas deshabilitadas', ['count' => $disabled]);
        }

        // ── Paso 2: sync masivo contra Graph-Fabric ──────────────────────────
        $res = $sync->syncAll();

        if ($res['ok']) {
            $this->info("Enviadas {$res['sent']} configuraciones. {$res['message']}");

            return 0;
        }

        $this->error($res['message']);
        Log::warning('[SyncParquetConfig] Sync masivo con problemas', $res);

        return 1;
    }

    /**
     * Deshabilita en bi_parquet_config las entradas cuya vista figura como
     * "inactivo" en bi_vistas (asi la tabla refleja la realidad). La baja en
     * Graph-Fabric la hace el sync masivo, que ademas deja fuera las vistas en
     * mantenimiento o que no existen en bi_vistas.
     */
    private function disableInactiveConfigs(ParquetScheduleSyncService $sync): int
    {
        $index = $sync->estadoIndex(true);
        $count = 0;

        BiParquetConfig::enabled()->get()->each(function (BiParquetConfig $config) use ($sync, $index, &$count): void {
            if ($sync->estadoDe($config->schema_name, $config->view_name, $index) === BiVista::ESTADO_INACTIVO) {
                $config->update(['enabled' => false]);
                $this->line("  DISABLED (bi_vistas inactivo)  {$config->qualifiedName()}");
                $count++;
            }
        });

        return $count;
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
