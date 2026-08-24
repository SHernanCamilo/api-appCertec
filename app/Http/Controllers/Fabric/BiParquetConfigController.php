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
                if ($cfg) {
                    $view['config'] = [
                        'refresh_interval_min' => $cfg->refresh_interval_min,
                        'priority'             => $cfg->priority,
                        'group_name'           => $cfg->group_name,
                        'is_stale'             => $cfg->isStale($view['age_minutes'] ?? null),
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

    // =========================================================================
    // PRIVATE
    // =========================================================================

    /**
     * Sincroniza UNA config con Graph-Fabric POST /api/r2/schedule.
     */
    private function syncSingle(BiParquetConfig $config): bool
    {
        if (!$config->enabled) return true; // No sincronizar deshabilitadas

        $baseUrl = config('fabric.url', 'http://127.0.0.1:8001');
        $token   = config('fabric.token_admin', '');

        try {
            $response = Http::timeout(10)->post("{$baseUrl}/api/r2/schedule", [
                'token'                => $token,
                'schema_name'          => $config->schema_name,
                'view'                 => $config->view_name,
                'refresh_interval_min' => $config->refresh_interval_min,
                'priority'             => $config->priority,
                'group_name'           => $config->group_name,
            ]);

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
}
