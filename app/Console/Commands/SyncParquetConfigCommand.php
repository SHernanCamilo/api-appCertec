<?php

namespace App\Console\Commands;

use App\Models\BiGrupo;
use App\Models\BiParquetConfig;
use App\Models\BiVista;
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
 * IMPORTANTE: Solo se sincronizan vistas que estén habilitadas en bi_parquet_config
 * Y cuyo estado en bi_vistas sea "activo" o "mantenimiento" (no "inactivo").
 * Si una vista está inactiva en bi_vistas, se deshabilita automáticamente en
 * bi_parquet_config y se elimina del schedule de Graph-Fabric.
 *
 * Usage:
 *   php artisan fabric:sync-parquet-config
 *
 * En el Kernel.php:
 *   $schedule->command('fabric:sync-parquet-config')->everyThirtyMinutes();
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

        // ── Paso 1: Deshabilitar en bi_parquet_config las vistas inactivas en bi_vistas ──
        // Construir índice: "schema.viewname" => estado (de bi_vistas)
        $estadoIndex = $this->buildEstadoIndex();
        $autoDisabled = $this->disableInactiveConfigs($baseUrl, $token, $estadoIndex);
        if ($autoDisabled > 0) {
            $this->warn("  {$autoDisabled} vistas desactivadas en bi_parquet_config (inactivas en bi_vistas).");
            Log::info('[SyncParquetConfig] Vistas inactivas deshabilitadas del scheduler', [
                'count' => $autoDisabled,
            ]);
        }

        // ── Paso 2: Sincronizar solo las que quedan habilitadas ──
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

    // =========================================================================
    // PRIVATE — Sincronización de estado bi_vistas → bi_parquet_config
    // =========================================================================

    /**
     * Construye un índice "schema.viewname" => estado desde bi_vistas + bi_grupos.
     * Solo registra vistas con estado "inactivo" (las que hay que bloquear).
     *
     * El schema se deriva del código del grupo:
     *   "GG-BD-IN" → "in"
     *   "IN"       → "in"
     *
     * @return array<string, string>  e.g. ["in.vw_medicalfees_proporcion_de" => "inactivo"]
     */
    private function buildEstadoIndex(): array
    {
        $index = [];

        BiVista::query()
            ->where('estado', BiVista::ESTADO_INACTIVO)
            ->with('grupo:id,codigo')
            ->get(['id', 'id_bi_grupos', 'nombre', 'estado'])
            ->each(function (BiVista $vista) use (&$index): void {
                $codigo = $vista->grupo?->codigo ?? '';
                $schema = $this->schemaFromCodigo($codigo);
                if ($schema === '') {
                    return;
                }
                $key         = $schema . '.' . strtolower(trim($vista->nombre));
                $index[$key] = $vista->estado;
            });

        return $index;
    }

    /**
     * Extrae el schema corto de un código de grupo.
     * "GG-BD-IN" → "in",  "IN" → "in",  "" → ""
     */
    private function schemaFromCodigo(string $codigo): string
    {
        $parts  = explode('-', strtoupper(trim($codigo)));
        // Si viene como GG-BD-XX → parts = [GG, BD, XX]
        $schema = count($parts) >= 3 ? $parts[2] : $parts[0];

        return strtolower($schema);
    }

    /**
     * Deshabilita en bi_parquet_config todas las entradas cuya vista
     * aparece como "inactivo" en bi_vistas, y las elimina del schedule
     * de Graph-Fabric vía DELETE /api/r2/schedule.
     *
     * @param  array<string, string>  $estadoIndex
     */
    private function disableInactiveConfigs(string $baseUrl, string $token, array $estadoIndex): int
    {
        if (empty($estadoIndex)) {
            return 0;
        }

        $disabled = 0;

        // Traer solo las configs que están habilitadas y cuya key (schema.view) aparece como inactiva
        BiParquetConfig::enabled()->get()->each(function (BiParquetConfig $config) use (
            $baseUrl, $token, $estadoIndex, &$disabled
        ): void {
            $key = $config->schema_name . '.' . strtolower($config->view_name);

            if (!isset($estadoIndex[$key])) {
                return; // La vista no está inactiva → dejar como está
            }

            // Desactivar en nuestra BD
            $config->update(['enabled' => false]);

            // Eliminar del scheduler de Graph-Fabric
            try {
                Http::timeout(10)->delete("{$baseUrl}/api/r2/schedule", [
                    'token'       => $token,
                    'schema_name' => $config->schema_name,
                    'view'        => $config->view_name,
                ]);
            } catch (\Exception $e) {
                Log::warning('[SyncParquetConfig] No se pudo remover de Graph-Fabric (vista inactiva)', [
                    'view'  => $config->qualifiedName(),
                    'error' => $e->getMessage(),
                ]);
            }

            $this->line("  DISABLED (bi_vistas inactivo)  {$config->qualifiedName()}");
            $disabled++;
        });

        return $disabled;
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
