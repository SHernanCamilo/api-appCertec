<?php

namespace App\Console\Commands;

use App\Models\BiParquetConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Rebalanceo inteligente del scheduler de parquets (estrategia Graph-Fabric).
 *
 * PROBLEMA: 113 vistas piden refresh cada 5 min y 93 cada 15 min, pero la infra
 * solo genera ~5-8 parquets/min. La demanda (~40/min) supera la capacidad → cola
 * infinita, Fabric saturado, 462 vistas "pendientes".
 *
 * ESTRATEGIA (v2 - todas via parquet):
 *   1. Vistas PEQUEÑAS (<10K filas)      → parquet cada 60 min (Graph tambien
 *      genera al vuelo si no existe; el export siempre sale por /api/data/export/r2).
 *   2. Vistas GRANDES (>10K filas)       → parquet, intervalo segun tamaño.
 *   3. Censos/Urgencias/Triage           → 5-15 min (críticos en vivo).
 *   4. Históricas (Ledger/Payroll/Fixed) → 120-480 min.
 *
 * NOTA v2: ya NO se desactivan vistas del scheduler. Todas quedan activas para
 * que la descarga sea rapida (parquet) y en tiempo real (ensure_fresh).
 *
 * FUENTE DE row_count: Graph-Fabric /api/r2/schedule (ya lo reporta por vista).
 *
 * USO:
 *   php artisan fabric:rebalance-scheduler            # aplica cambios
 *   php artisan fabric:rebalance-scheduler --dry-run  # solo muestra, no cambia
 *   php artisan fabric:rebalance-scheduler --sync     # aplica + sincroniza con Graph
 *
 * Tras aplicar, sincronizar con: php artisan fabric:sync-parquet-config
 */
class RebalanceParquetSchedulerCommand extends Command
{
    protected $signature = 'fabric:rebalance-scheduler
        {--dry-run : Solo mostrar el plan, no modificar nada}
        {--sync : Sincronizar con Graph-Fabric al terminar}
        {--small-threshold=10000 : Filas por debajo de las cuales se desactiva del scheduler}';

    protected $description = 'Rebalancea el scheduler de parquets: desactiva pequeñas, sube intervalos de históricas';

    /** Vistas que SIEMPRE se mantienen frecuentes (críticas en vivo) */
    private const CRITICAL_PATTERNS = [
        'VW_Censo'                 => ['interval' => 5,  'priority' => 'realtime'],
        'VW_HC_TableroUrgencias'   => ['interval' => 5,  'priority' => 'realtime'],
        'VW_HC_ClasificacionTriage'=> ['interval' => 15, 'priority' => 'realtime'],
        'VW_AD_Censo_Trazabilidad' => ['interval' => 10, 'priority' => 'realtime'],
        'VW_HC_CensoHistorico'     => ['interval' => 10, 'priority' => 'realtime'],
    ];

    /** Vistas históricas que cambian poco → intervalos largos */
    private const HISTORICAL_PATTERNS = [
        'VW_Ledger'      => ['interval' => 240, 'priority' => 'analitico'],
        'VW_Balance'     => ['interval' => 240, 'priority' => 'analitico'],
        'VW_Financiera'  => ['interval' => 240, 'priority' => 'analitico'],
        'VW_Payroll'     => ['interval' => 240, 'priority' => 'analitico'],
        'VW_Fixed'       => ['interval' => 480, 'priority' => 'analitico'],
        'VW_Portfolio'   => ['interval' => 120, 'priority' => 'analitico'],
        'VW_Billing'     => ['interval' => 120, 'priority' => 'analitico'],
        'VW_MedicalFees' => ['interval' => 120, 'priority' => 'analitico'],
    ];

    public function handle(): int
    {
        $dryRun         = (bool) $this->option('dry-run');
        $smallThreshold = (int) $this->option('small-threshold');

        $this->info('=== Rebalanceo del scheduler de parquets ===');
        $this->line($dryRun ? '(DRY-RUN: no se modifica nada)' : '(aplicando cambios)');
        $this->newLine();

        // 1. Traer row_count de Graph-Fabric
        $rowCounts = $this->fetchRowCounts();
        if ($rowCounts === null) {
            $this->error('No se pudo obtener row_count de Graph-Fabric. Abortando.');
            return 1;
        }
        $this->info('Row counts obtenidos de Graph-Fabric: ' . count($rowCounts) . ' vistas.');
        $this->newLine();

        // 2. Procesar cada config
        $stats = [
            'critical'   => 0,
            'historical' => 0,
            'disabled'   => 0, // pequeñas desactivadas
            'kept'       => 0, // grandes que se mantienen
            'unknown'    => 0, // sin row_count (se dejan como están)
        ];

        $plan = [];

        BiParquetConfig::chunk(200, function ($configs) use (&$stats, &$plan, $rowCounts, $smallThreshold) {
            foreach ($configs as $config) {
                $decision = $this->decideForView($config, $rowCounts, $smallThreshold);
                $stats[$decision['bucket']]++;

                if ($decision['changed']) {
                    $plan[] = [
                        'view'     => $config->qualifiedName(),
                        'action'   => $decision['action'],
                        'interval' => $decision['interval'],
                        'priority' => $decision['priority'],
                        'enabled'  => $decision['enabled'],
                        'rows'     => $decision['rows'],
                        'config'   => $config,
                    ];
                }
            }
        });

        // 3. Mostrar resumen del plan
        $this->info('Plan de rebalanceo:');
        $this->table(
            ['Categoria', 'Vistas'],
            [
                ['Criticas (5-15 min)',      $stats['critical']],
                ['Historicas (120-480 min)', $stats['historical']],
                ['Pequenas (desactivar)',    $stats['disabled']],
                ['Grandes (mantener)',       $stats['kept']],
                ['Sin row_count (sin cambio)', $stats['unknown']],
            ]
        );
        $this->newLine();
        $this->info('Cambios a aplicar: ' . count($plan));

        // Mostrar primeros 15 cambios como muestra
        foreach (array_slice($plan, 0, 15) as $p) {
            $this->line(sprintf('  %-45s %s -> %dmin (%s) %s',
                $p['view'], $p['action'], $p['interval'], $p['priority'],
                $p['enabled'] ? '' : '[DESACTIVADA]'
            ));
        }
        if (count($plan) > 15) {
            $this->line('  ... y ' . (count($plan) - 15) . ' más');
        }
        $this->newLine();

        // 4. Aplicar
        if ($dryRun) {
            $this->warn('DRY-RUN: no se aplicaron cambios. Quite --dry-run para ejecutar.');
            return 0;
        }

        $applied = 0;
        foreach ($plan as $p) {
            $p['config']->update([
                'refresh_interval_min' => $p['interval'],
                'priority'             => $p['priority'],
                'enabled'              => $p['enabled'],
            ]);
            $applied++;
        }

        $this->info("Aplicados {$applied} cambios en bi_parquet_config.");

        // Distribución final
        $this->newLine();
        $this->info('Distribucion final:');
        $enabled  = BiParquetConfig::where('enabled', true)->count();
        $disabled = BiParquetConfig::where('enabled', false)->count();
        $this->line("  Activas en scheduler: {$enabled}");
        $this->line("  Desactivadas (export al vuelo): {$disabled}");

        Log::info('[RebalanceScheduler] Completado', array_merge($stats, [
            'applied'  => $applied,
            'enabled'  => $enabled,
            'disabled' => $disabled,
        ]));

        // 5. Sincronizar con Graph
        if ($this->option('sync')) {
            $this->newLine();
            $this->info('Sincronizando con Graph-Fabric...');
            $this->call('fabric:sync-parquet-config');
        } else {
            $this->newLine();
            $this->warn('Recuerde sincronizar: php artisan fabric:sync-parquet-config');
        }

        return 0;
    }

    /**
     * Decide el intervalo/prioridad/enabled para una vista.
     */
    private function decideForView(BiParquetConfig $config, array $rowCounts, int $smallThreshold): array
    {
        $view = $config->view_name;
        $key  = "{$config->schema_name}.{$view}";
        $rows = $rowCounts[$key] ?? null;

        // 1. CRÍTICAS: siempre frecuentes, sin importar tamaño
        foreach (self::CRITICAL_PATTERNS as $pattern => $rule) {
            if (str_contains($view, $pattern)) {
                return $this->buildDecision($config, 'critical', 'MANTENER-CRITICA',
                    $rule['interval'], $rule['priority'], true, $rows);
            }
        }

        // 2. HISTÓRICAS: intervalos largos
        foreach (self::HISTORICAL_PATTERNS as $pattern => $rule) {
            if (str_contains($view, $pattern)) {
                return $this->buildDecision($config, 'historical', 'SUBIR-INTERVALO',
                    $rule['interval'], $rule['priority'], true, $rows);
            }
        }

        // 3. PEQUEÑAS (<threshold): mantener parquet pero con intervalo largo.
        //    NOTA: ya NO se desactivan. Graph-Fabric garantiza el export via parquet
        //    (o Fabric al vuelo si no hay parquet). Todas quedan activas para que
        //    la descarga sea rapida y en tiempo real.
        if ($rows !== null && $rows < $smallThreshold) {
            return $this->buildDecision($config, 'kept', 'PEQUENA-PARQUET',
                60, 'operativo', true, $rows);
        }

        // 4. GRANDES: mantener parquet, intervalo segun tamaño
        if ($rows !== null && $rows >= $smallThreshold) {
            // Cuanto mas grande, mayor intervalo (menos presion)
            $interval = $rows > 500000 ? 120 : ($rows > 100000 ? 60 : 30);
            return $this->buildDecision($config, 'kept', 'MANTENER-GRANDE',
                $interval, 'operativo', true, $rows);
        }

        // 5. SIN row_count: dejar como está
        return [
            'bucket'   => 'unknown',
            'changed'  => false,
            'action'   => 'SIN-CAMBIO',
            'interval' => $config->refresh_interval_min,
            'priority' => $config->priority,
            'enabled'  => $config->enabled,
            'rows'     => $rows,
        ];
    }

    private function buildDecision(
        BiParquetConfig $config, string $bucket, string $action,
        int $interval, string $priority, bool $enabled, ?int $rows
    ): array {
        $changed = $config->refresh_interval_min !== $interval
            || $config->priority !== $priority
            || $config->enabled !== $enabled;

        return compact('bucket', 'changed', 'action', 'interval', 'priority', 'enabled', 'rows');
    }

    /**
     * Obtiene el row_count de cada vista desde Graph-Fabric /api/r2/schedule.
     * @return array<string,int>|null  ["schema.view" => row_count]
     */
    private function fetchRowCounts(): ?array
    {
        $baseUrl = config('fabric.url', 'http://127.0.0.1:8001');
        $token   = config('fabric.token_admin', '');

        try {
            $response = Http::timeout(30)->get("{$baseUrl}/api/r2/schedule", ['token' => $token]);
            if (!$response->successful()) {
                $this->error("Graph-Fabric HTTP {$response->status()}");
                return null;
            }

            $views  = $response->json('views') ?? [];
            $counts = [];
            foreach ($views as $v) {
                $key = ($v['schema'] ?? '') . '.' . ($v['view'] ?? '');
                if ($key !== '.' && isset($v['row_count'])) {
                    $counts[$key] = (int) $v['row_count'];
                }
            }

            // Fallback: si /schedule no trae row_count, intentar /api/r2/status
            if (empty($counts)) {
                $statusResp = Http::timeout(30)->get("{$baseUrl}/api/r2/status", ['token' => $token]);
                if ($statusResp->successful()) {
                    foreach (($statusResp->json('data.views') ?? $statusResp->json('views') ?? []) as $v) {
                        $key = ($v['schema'] ?? '') . '.' . ($v['view'] ?? '');
                        if ($key !== '.' && isset($v['row_count'])) {
                            $counts[$key] = (int) $v['row_count'];
                        }
                    }
                }
            }

            return $counts;
        } catch (\Exception $e) {
            $this->error("Error: {$e->getMessage()}");
            return null;
        }
    }
}
