<?php

namespace App\Http\Controllers\Fabric;

use App\Http\Controllers\Controller;
use App\Models\BiParquetConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * CRUD de la configuracion de parquets + sincronizacion con Graph-Fabric.
 *
 * Endpoints:
 *   GET    /api/fabric/viewer/parquet-config          → Lista todas las configs
 *   POST   /api/fabric/viewer/parquet-config          → Crear/actualizar una config
 *   DELETE /api/fabric/viewer/parquet-config/{id}     → Eliminar
 *   POST   /api/fabric/viewer/parquet-config/sync     → Forzar sync con Graph-Fabric
 *   GET    /api/fabric/viewer/parquet-config/status   → Estado de todos los parquets en Graph-Fabric
 */
class BiParquetConfigController extends Controller
{
    /**
     * Lista todas las configuraciones de parquet.
     */
    public function index(): JsonResponse
    {
        $configs = BiParquetConfig::orderBy('priority')
            ->orderBy('schema_name')
            ->orderBy('view_name')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $configs,
            'total'   => $configs->count(),
        ]);
    }

    /**
     * Crear o actualizar la configuracion de una vista.
     *
     * Body:
     * {
     *   "schema_name": "dc",
     *   "view_name": "VW_Censo_Cmi",
     *   "refresh_interval_min": 5,
     *   "priority": "realtime",
     *   "group_name": "censos",
     *   "enabled": true
     * }
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'schema_name'          => 'required|string|max:20',
            'view_name'            => 'required|string|max:150',
            'refresh_interval_min' => 'required|integer|min:1|max:1440',
            'priority'             => 'required|in:realtime,high,medium,low,manual',
            'group_name'           => 'nullable|string|max:50',
            'enabled'              => 'sometimes|boolean',
        ]);

        $config = BiParquetConfig::updateOrCreate(
            [
                'schema_name' => $request->input('schema_name'),
                'view_name'   => $request->input('view_name'),
            ],
            [
                'refresh_interval_min' => $request->input('refresh_interval_min'),
                'priority'             => $request->input('priority'),
                'group_name'           => $request->input('group_name', 'general'),
                'enabled'              => $request->input('enabled', true),
            ]
        );

        // Sincronizar inmediatamente con Graph-Fabric
        $syncResult = $this->syncSingle($config);

        return response()->json([
            'success'     => true,
            'data'        => $config->fresh(),
            'synced'      => $syncResult,
            'message'     => 'Configuracion guardada' . ($syncResult ? ' y sincronizada.' : ' (sync pendiente).'),
        ]);
    }

    /**
     * Eliminar una configuracion (la vista deja de regenerarse por cron).
     */
    public function destroy(int $id): JsonResponse
    {
        $deleted = BiParquetConfig::where('id', $id)->delete();

        return response()->json([
            'success' => $deleted > 0,
            'message' => $deleted > 0 ? 'Configuracion eliminada.' : 'No encontrada.',
        ]);
    }

    /**
     * Forzar sincronizacion de TODAS las configs habilitadas con Graph-Fabric.
     *
     * Util para: despues de restaurar backup, primer deploy, o si Graph-Fabric
     * se reinicio y perdio su schedule.db.
     */
    public function syncAll(): JsonResponse
    {
        $configs = BiParquetConfig::enabled()->get();
        $synced  = 0;
        $failed  = 0;

        foreach ($configs as $config) {
            if ($this->syncSingle($config)) {
                $synced++;
            } else {
                $failed++;
            }
        }

        return response()->json([
            'success' => true,
            'synced'  => $synced,
            'failed'  => $failed,
            'total'   => $configs->count(),
            'message' => "Sincronizadas {$synced}/{$configs->count()} configuraciones.",
        ]);
    }

    /**
     * Consulta el estado de todos los parquets en Graph-Fabric.
     * Devuelve info de cada parquet: edad, tamano, estado, etc.
     */
    public function status(): JsonResponse
    {
        $baseUrl = config('fabric.url', 'http://127.0.0.1:8001');
        $token   = config('fabric.token_admin', '');

        try {
            $response = Http::timeout(15)->get("{$baseUrl}/api/r2/status", [
                'token' => $token,
            ]);

            if (!$response->successful()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Graph-Fabric no respondio correctamente.',
                ], 502);
            }

            $data = $response->json();

            // Enriquecer con la config de Laravel (para saber si un parquet esta stale segun SU intervalo)
            $configs = BiParquetConfig::enabled()->get()->keyBy(fn($c) => "{$c->schema_name}.{$c->view_name}");

            $views = $data['views'] ?? [];
            foreach ($views as &$view) {
                $key = ($view['schema'] ?? '') . '.' . ($view['view'] ?? '');
                $cfg = $configs->get($key);

                // Graph-Fabric envía age_hours, convertir a minutos para isStale()
                $ageMinutes = null;
                if (isset($view['age_hours'])) {
                    $ageMinutes = (int) round($view['age_hours'] * 60);
                } elseif (isset($view['age_minutes'])) {
                    $ageMinutes = (int) $view['age_minutes'];
                }

                if ($cfg) {
                    $view['config'] = [
                        'refresh_interval_min' => $cfg->refresh_interval_min,
                        'priority'             => $cfg->priority,
                        'group_name'           => $cfg->group_name,
                        'is_stale'             => $cfg->isStale($ageMinutes),
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'data'    => $data,
                'views'   => $views,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'No se pudo conectar con Graph-Fabric: ' . $e->getMessage(),
            ], 503);
        }
    }

    /**
     * Importar todas las vistas desde Graph-Fabric /api/r2/status hacia bi_parquet_config.
     *
     * Asigna intervalos por defecto segun el schema:
     *   hg (hospitalizacion/censos) → 10 min, priority high
     *   dc (censos)                 → 5 min, priority realtime
     *   aa (agendas)                → 30 min, priority medium
     *   ca (cartera)                → 60 min, priority medium
     *   co (contabilidad)           → 120 min, priority low
     *   default                     → 60 min, priority medium
     */
    public function importFromGraph(Request $request): JsonResponse
    {
        $baseUrl = config('fabric.url', 'http://127.0.0.1:8001');
        $token   = config('fabric.token_admin', '');

        try {
            $response = Http::timeout(30)->get("{$baseUrl}/api/r2/status", [
                'token' => $token,
            ]);

            if (!$response->successful()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se pudo conectar con Graph-Fabric: status ' . $response->status(),
                ], 502);
            }

            $data  = $response->json();
            $views = $data['views'] ?? [];

            if (empty($views)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Graph-Fabric no reporto vistas.',
                ], 404);
            }

            $imported = 0;
            $skipped  = 0;
            $overwrite = $request->boolean('overwrite', false);

            foreach ($views as $view) {
                $schema = $view['schema'] ?? '';
                $viewName = $view['view'] ?? '';

                if (!$schema || !$viewName) continue;

                // Si ya existe y no queremos sobreescribir, saltar
                if (!$overwrite && BiParquetConfig::forView($schema, $viewName)->exists()) {
                    $skipped++;
                    continue;
                }

                // Determinar intervalo y prioridad por patron de schema
                $defaults = $this->getDefaultsForSchema($schema, $viewName);

                BiParquetConfig::updateOrCreate(
                    ['schema_name' => $schema, 'view_name' => $viewName],
                    [
                        'refresh_interval_min' => $defaults['interval'],
                        'priority'             => $defaults['priority'],
                        'group_name'           => $defaults['group'],
                        'enabled'              => true,
                    ]
                );
                $imported++;
            }

            return response()->json([
                'success'  => true,
                'imported' => $imported,
                'skipped'  => $skipped,
                'total'    => count($views),
                'message'  => "Importadas {$imported} vistas, {$skipped} ya existian (de " . count($views) . " totales en Graph-Fabric).",
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al importar: ' . $e->getMessage(),
            ], 503);
        }
    }

    // =========================================================================
    // PRIVATE
    // =========================================================================

    /**
     * Determina intervalos por defecto segun el schema y nombre de vista.
     * Default global: 30 min para todo lo que no tenga regla especifica.
     */
    private function getDefaultsForSchema(string $schema, string $viewName): array
    {
        // HojaQx / Censo → alta prioridad, 10 min
        if (str_contains($viewName, 'HojaQx') || str_contains($viewName, 'Censo')) {
            return ['interval' => 10, 'priority' => 'high', 'group' => 'censos'];
        }

        // Treasury (Tesorería) → 15 min
        if (str_contains($viewName, 'Treasury') || str_contains($viewName, 'Recibo') || str_contains($viewName, 'EgresoTesoreria')) {
            return ['interval' => 15, 'priority' => 'high', 'group' => 'financiero'];
        }

        // Cartera/Portfolio → 30 min
        if (str_contains($viewName, 'Portfolio') || str_contains($viewName, 'Cartera') || str_contains($viewName, 'Recaudo')) {
            return ['interval' => 30, 'priority' => 'medium', 'group' => 'financiero'];
        }

        // Ledger (contabilidad) → 60 min
        if (str_contains($viewName, 'Ledger') || str_contains($viewName, 'Balance') || str_contains($viewName, 'Comprobante')) {
            return ['interval' => 60, 'priority' => 'medium', 'group' => 'financiero'];
        }

        // Billing → 30 min
        if (str_contains($viewName, 'Billing') || str_contains($viewName, 'Factur')) {
            return ['interval' => 30, 'priority' => 'medium', 'group' => 'financiero'];
        }

        return match ($schema) {
            'dc'    => ['interval' => 5,   'priority' => 'realtime', 'group' => 'censos'],
            'hg'    => ['interval' => 15,  'priority' => 'high',     'group' => 'operativo'],
            'pt'    => ['interval' => 15,  'priority' => 'high',     'group' => 'financiero'],
            'ca'    => ['interval' => 30,  'priority' => 'medium',   'group' => 'financiero'],
            'co'    => ['interval' => 60,  'priority' => 'medium',   'group' => 'financiero'],
            'fr'    => ['interval' => 30,  'priority' => 'medium',   'group' => 'financiero'],
            'if'    => ['interval' => 60,  'priority' => 'low',      'group' => 'financiero'],
            default => ['interval' => 30,  'priority' => 'medium',   'group' => 'general'],
        };
    }

    /**
     * Sincroniza UNA config con Graph-Fabric.
     * - Si enabled: POST /api/r2/schedule (registrar/actualizar)
     * - Si disabled: DELETE /api/r2/schedule (remover del cron)
     */
    private function syncSingle(BiParquetConfig $config): bool
    {
        $baseUrl = config('fabric.url', 'http://127.0.0.1:8001');
        $token   = config('fabric.token_admin', '');

        try {
            if (!$config->enabled) {
                // Desactivada → remover del schedule de Graph-Fabric
                $response = Http::timeout(10)->delete("{$baseUrl}/api/r2/schedule", [
                    'token'       => $token,
                    'schema_name' => $config->schema_name,
                    'view'        => $config->view_name,
                ]);
            } else {
                // Activa → registrar/actualizar en el schedule
                $response = Http::timeout(10)->post("{$baseUrl}/api/r2/schedule", [
                    'token'                => $token,
                    'schema_name'          => $config->schema_name,
                    'view'                 => $config->view_name,
                    'refresh_interval_min' => $config->refresh_interval_min,
                    'priority'             => $config->priority,
                    'group_name'           => $config->group_name,
                ]);
            }

            if ($response->successful()) {
                $config->update(['last_synced_at' => now()]);
                return true;
            }

            Log::warning('[ParquetConfig] Sync fallo para ' . $config->qualifiedName(), [
                'status' => $response->status(),
                'body'   => substr($response->body(), 0, 200),
            ]);
            return false;
        } catch (\Exception $e) {
            Log::error('[ParquetConfig] Error sync ' . $config->qualifiedName(), [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * POST /api/fabric/viewer/parquet-config/run-cron
     * Ejecuta manualmente el cron de Graph-Fabric (regenera parquets pendientes).
     */
    public function runCron(): JsonResponse
    {
        $baseUrl = config('fabric.url', 'http://127.0.0.1:8001');
        $token   = config('fabric.token_admin', '');

        try {
            $response = Http::timeout(15)->post("{$baseUrl}/api/r2/schedule/run", [
                'token' => $token,
            ]);

            if (!$response->successful()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Graph-Fabric no respondio: ' . $response->status(),
                ], 502);
            }

            $data = $response->json();

            return response()->json([
                'success'   => true,
                'status'    => $data['status'] ?? 'started',
                'due_count' => $data['due_count'] ?? 0,
                'due_views' => $data['due_views'] ?? [],
                'message'   => 'Cron ejecutado: ' . ($data['due_count'] ?? 0) . ' vistas pendientes de regeneracion.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al ejecutar cron: ' . $e->getMessage(),
            ], 503);
        }
    }
}
