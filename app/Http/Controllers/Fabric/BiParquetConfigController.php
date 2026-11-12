<?php

namespace App\Http\Controllers\Fabric;

use App\Http\Controllers\Controller;
use App\Models\BiGrupo;
use App\Models\BiParquetConfig;
use App\Models\BiVista;
use App\Services\Fabric\GraphFabricService;
use App\Services\Fabric\ParquetScheduleSyncService;
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
     * Enriquece cada entrada con el campo `bi_vista_estado` (activo/mantenimiento/inactivo)
     * tomado de bi_vistas, para que el frontend pueda advertir cuando un parquet
     * habilitado corresponde a una vista inactiva.
     */
    public function index(): JsonResponse
    {
        $configs = BiParquetConfig::orderBy('priority')
            ->orderBy('schema_name')
            ->orderBy('view_name')
            ->get();

        // Construir índice de estados: "schema.viewname" => estado
        $estadoIndex = $this->buildVistaEstadoIndex();

        $data = $configs->map(function (BiParquetConfig $config) use ($estadoIndex) {
            $key   = $config->schema_name . '.' . strtolower($config->view_name);
            $arr   = $config->toArray();
            $arr['bi_vista_estado'] = $estadoIndex[$key] ?? null; // null = no registrada en bi_vistas
            return $arr;
        });

        return response()->json([
            'success' => true,
            'data'    => $data,
            'total'   => $configs->count(),
        ]);
    }

    /**
     * Construye un índice "schema.viewname" => estado desde bi_vistas + bi_grupos.
     * Solo carga vistas con estado != 'activo' para mantener la consulta liviana.
     *
     * @return array<string, string>
     */
    private function buildVistaEstadoIndex(): array
    {
        $index = [];

        BiVista::query()
            ->with('grupo:id,codigo')
            ->get(['id', 'id_bi_grupos', 'nombre', 'estado'])
            ->each(function (BiVista $vista) use (&$index): void {
                $codigo = $vista->grupo?->codigo ?? '';
                $parts  = explode('-', strtoupper(trim($codigo)));
                $schema = count($parts) >= 3 ? strtolower($parts[2]) : strtolower($parts[0] ?? '');
                if ($schema === '') {
                    return;
                }
                $key         = $schema . '.' . strtolower(trim($vista->nombre));
                $index[$key] = $vista->estado;
            });

        return $index;
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
            'priority'             => 'required|in:realtime,high,medium,low,manual,operativo,analitico',
            'group_name'           => 'nullable|string|max:50',
            'enabled'              => 'sometimes|boolean',
        ]);

        // El schema siempre en minusculas (evita duplicados tipo CO / co en Graph)
        $request->merge(['schema_name' => strtolower((string) $request->input('schema_name'))]);

        // Solo se programan vistas ACTIVAS en la plataforma. Antes se podia dar de
        // alta cualquier nombre: asi entraron dos con "lnventory" (L minuscula) en
        // vez de "Inventory", que no existen y nunca se generan.
        if ($request->boolean('enabled', true)) {
            $schemaIn = (string) $request->input('schema_name');
            $viewIn   = (string) $request->input('view_name');
            $estado   = app(ParquetScheduleSyncService::class)->estadoDe($schemaIn, $viewIn);

            if ($estado !== BiVista::ESTADO_ACTIVO) {
                return response()->json([
                    'success' => false,
                    'message' => $estado === null
                        ? "La vista {$schemaIn}.{$viewIn} no existe en la plataforma. Revise el nombre."
                        : "La vista {$schemaIn}.{$viewIn} esta en estado '{$estado}'; solo se programan vistas activas.",
                ], 422);
            }
        }
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
        $config = BiParquetConfig::find($id);

        if (!$config) {
            return response()->json(['success' => false, 'message' => 'No encontrada.']);
        }

        // Baja inmediata en Graph-Fabric: antes solo se borraba la fila y la vista
        // seguía en el scheduler hasta el siguiente sync. Si Graph no responde,
        // el sync masivo (cada 30 min) la reconcilia igual.
        app(GraphFabricService::class)->scheduleDelete(strtolower($config->schema_name), $config->view_name);

        $config->delete();

        return response()->json([
            'success' => true,
            'message' => 'Configuracion eliminada y retirada del scheduler.',
        ]);
    }

    /**
     * Forzar sincronizacion de TODAS las configs habilitadas con Graph-Fabric.
     *
     * Util para: despues de restaurar backup, primer deploy, o si Graph-Fabric
     * se reinicio y perdio su schedule.db.
     */
    public function syncAll(Request $request, ParquetScheduleSyncService $sync): JsonResponse
    {
        // Un solo envío con la lista completa: Graph-Fabric queda IGUAL a la tabla
        // y desactiva lo que no esté configurado. Ya no hay lotes ni "pendientes".
        $res = $sync->syncAll();

        return response()->json([
            'success' => $res['ok'],
            'synced'  => $res['activated'],
            'failed'  => $res['errors'],
            'total'   => $res['sent'],
            'pending' => 0,
            'errors'  => [],
            'message' => $res['message'],
        ], $res['ok'] ? 200 : 502);
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
            $views = $data['views'] ?? [];

            // La hora real de generación vive en el schedule (disco local), no en R2.
            // /api/r2/status solo ve archivos de R2; si R2 no responde, la fila queda
            // "missing" y sin edad aunque el parquet ya exista en parquet_cache.
            $schedule = Http::timeout(15)->get("{$baseUrl}/api/r2/schedule", [
                'token' => $token,
            ]);
            if ($schedule->successful()) {
                $views = $this->mergeScheduleGeneration($views, $schedule->json('views') ?? []);
            }

            // Enriquecer con la config de Laravel (para saber si un parquet esta stale segun SU intervalo)
            $configs = BiParquetConfig::enabled()->get()
                ->keyBy(fn ($c) => strtolower("{$c->schema_name}.{$c->view_name}"));

            // Solo se muestran las vistas CONFIGURADAS. Lo demás que exista en
            // R2 o en el schedule de Graph (huérfanas, deshabilitadas) no cuenta
            // en el monitor: antes inflaba las "desactualizadas" a 633.
            $totalGraph = count($views);
            $views = array_values(array_filter(
                $views,
                fn ($v) => $configs->has(strtolower(($v['schema'] ?? '') . '.' . ($v['view'] ?? '')))
            ));
            $excluded = $totalGraph - count($views);

            foreach ($views as &$view) {
                $key = strtolower(($view['schema'] ?? '') . '.' . ($view['view'] ?? ''));
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

                // Pasar avg_generation_s para que el frontend muestre el carril
                // (viene del schedule de Graph-Fabric, no del status)
            }

            return response()->json([
                'success'        => true,
                'data'           => $data,
                'views'          => $views,
                // Cuántas vistas de Graph se dejaron fuera por no estar configuradas
                'excluded_count' => $excluded,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'No se pudo conectar con Graph-Fabric: ' . $e->getMessage(),
            ], 503);
        }
    }

    /**
     * DESACTIVADO. La importacion masiva traia TODAS las vistas de Graph-Fabric y las
     * dejaba habilitadas, que es lo que inflaba el scheduler a 976 vistas. Ahora solo
     * se programan las vistas que se configuran a mano ("Nueva").
     */
    public function importFromGraph(Request $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'La importacion masiva esta desactivada: solo se programan las vistas configuradas manualmente.',
        ], 410);
    }

    // =========================================================================
    // PRIVATE
    // =========================================================================

    /**
     * Completa cada vista con la hora en que el scheduler escribió el parquet.
     * Las que solo existen en el schedule (no en R2) se agregan para que la
     * tabla pueda mostrar "hace cuánto" y la cuenta regresiva.
     *
     * @param  array<int, array<string, mixed>>  $views
     * @param  array<int, array<string, mixed>>  $scheduleViews
     * @return array<int, array<string, mixed>>
     */
    private function mergeScheduleGeneration(array $views, array $scheduleViews): array
    {
        $index = [];
        foreach ($views as $i => $view) {
            $key = strtolower(($view['schema'] ?? '') . '.' . ($view['view'] ?? ''));
            if ($key !== '.') {
                $index[$key] = $i;
            }
        }

        foreach ($scheduleViews as $sv) {
            $schema = (string) ($sv['schema'] ?? '');
            $name   = (string) ($sv['view'] ?? '');
            if ($schema === '' || $name === '') {
                continue;
            }

            $patch = [
                // Hora del archivo en parquet_cache. No es la de R2 ni la del scheduler.
                // Se lee el .meta.json directo: el proceso de Graph que ya está
                // corriendo no conoce este campo hasta que se reinicie.
                'local_generated' => $sv['local_generated'] ?? $this->localParquetGeneratedAt($schema, $name),
            ];
            if (isset($sv['avg_gen_s'])) {
                $patch['avg_generation_s'] = $sv['avg_gen_s'];
            }

            // Motivo del ultimo fallo, tal como lo guarda Graph-Fabric. Solo se
            // envia si la vista esta en error AHORA: Graph borra last_error cuando
            // vuelve a generar bien, pero asi no se marca como fallida una vista
            // que ya se recupero.
            if (($sv['status'] ?? '') === 'error' && !empty($sv['last_error'])) {
                $patch['status']        = 'error';
                $patch['error_message'] = (string) $sv['last_error'];
                $patch['error_count']   = (int) ($sv['error_count'] ?? 0);
            }

            $key = strtolower($schema . '.' . $name);
            if (isset($index[$key])) {
                $views[$index[$key]] = array_merge($views[$index[$key]], $patch);
                continue;
            }

            $views[] = array_merge([
                'schema'    => $schema,
                'view'      => $name,
                'status'    => $sv['status'] ?? 'pending',
                'row_count' => $sv['row_count'] ?? 0,
                'size_mb'   => $sv['size_mb'] ?? 0,
            ], $patch);
        }

        return $views;
    }

    /** generated_at del parquet en Graph-Fabric/parquet_cache, o null si no existe. */
    private function localParquetGeneratedAt(string $schema, string $view): ?string
    {
        $dir  = dirname(base_path()) . DIRECTORY_SEPARATOR . 'Graph-Fabric' . DIRECTORY_SEPARATOR . 'parquet_cache'
            . DIRECTORY_SEPARATOR . $schema;
        $meta = $dir . DIRECTORY_SEPARATOR . $view . '.meta.json';
        if (is_file($meta)) {
            $json = json_decode((string) file_get_contents($meta), true);
            if (is_array($json)) {
                $at = $json['generated_at'] ?? $json['last_modified'] ?? null;
                if (is_string($at) && $at !== '') {
                    return $at;
                }
            }
        }

        $parquet = $dir . DIRECTORY_SEPARATOR . $view . '.parquet';
        if (!is_file($parquet)) {
            return null;
        }

        return date('c', (int) filemtime($parquet));
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
                // Desactivada → baja en Graph-Fabric. schema y view van en la QUERY
                // STRING; mandarlos en el body (como se hacía) devuelve 422 y la
                // vista seguía ejecutándose.
                $res = app(GraphFabricService::class)
                    ->scheduleDelete(strtolower($config->schema_name), $config->view_name);

                if ($res['ok']) {
                    $config->update(['last_synced_at' => now()]);

                    return true;
                }

                Log::warning('[ParquetConfig] No se pudo dar de baja en Graph: ' . $config->qualifiedName(), [
                    'message' => $res['message'] ?? null,
                ]);

                return false;
            }

            // Activa → registrar/actualizar en el schedule
            $response = Http::timeout(10)->post("{$baseUrl}/api/r2/schedule", [
                'token'                => $token,
                'schema_name'          => strtolower($config->schema_name),
                'view'                 => $config->view_name,
                'refresh_interval_min' => $config->refresh_interval_min,
                'priority'             => $this->mapPriorityForGraph($config->priority),
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

    /**
     * GET /api/fabric/viewer/parquet-config/dashboard
     * Resumen del estado de generación: por carril, por estado, represamiento.
     * Combina el schedule de Graph-Fabric con el historial de Laravel.
     */
    public function dashboard(): JsonResponse
    {
        $baseUrl = config('fabric.url', 'http://127.0.0.1:8001');
        $token   = config('fabric.token_admin', '');

        try {
            $response = Http::timeout(15)->get("{$baseUrl}/api/r2/schedule", [
                'token' => $token,
            ]);

            if (!$response->successful()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Graph-Fabric no respondio: ' . $response->status(),
                ], 502);
            }

            $data     = $response->json();
            $allViews = $data['views'] ?? [];

            // Solo las vistas CONFIGURADAS (habilitadas en bi_parquet_config). El
            // schedule de Graph puede traer cientos de filas inactivas que no
            // deben contar en KPIs, carriles ni atrasos.
            $configured = BiParquetConfig::enabled()->get()
                ->mapWithKeys(fn ($c) => [strtolower("{$c->schema_name}.{$c->view_name}") => true]);

            $views = array_values(array_filter(
                $allViews,
                fn ($v) => $configured->has(strtolower(($v['schema'] ?? '') . '.' . ($v['view'] ?? '')))
            ));

            $byStatus = [];
            foreach ($views as $v) {
                $st = (string) ($v['status'] ?? 'unknown');
                $byStatus[$st] = ($byStatus[$st] ?? 0) + 1;
            }

            // Los totales se recalculan sobre las configuradas; el resto de los
            // campos de Graph (eficiencia, prioridades) se conserva.
            $stats = array_merge($data['stats'] ?? [], [
                'total_active' => count($views),
                'by_status'    => $byStatus,
            ]);

            // Agrupar por carril
            $lanes = ['sprint' => 0, 'standard' => 0, 'heavy' => 0, 'marathon' => 0, 'nueva' => 0];
            $laneStale = ['sprint' => 0, 'standard' => 0, 'heavy' => 0, 'marathon' => 0, 'nueva' => 0];

            foreach ($views as $v) {
                $avg  = $v['avg_gen_s'] ?? $v['avg_generation_s'] ?? null;
                $lane = $this->laneFromAvg($avg);
                $lanes[$lane] = ($lanes[$lane] ?? 0) + 1;

                if (($v['status'] ?? '') === 'stale' || ($v['status'] ?? '') === 'pending') {
                    $laneStale[$lane] = ($laneStale[$lane] ?? 0) + 1;
                }
            }

            // Ultimas 5 capturas del historial para tendencia
            $history = \App\Models\BiParquetHistory::selectRaw('captured_at, status, count(*) as total')
                ->where('captured_at', '>=', now()->subHours(6))
                ->groupBy('captured_at', 'status')
                ->orderBy('captured_at', 'desc')
                ->limit(60)
                ->get();

            return response()->json([
                'success'    => true,
                'stats'      => $stats,
                'excluded_count' => count($allViews) - count($views),
                'due_count'  => $data['due_count'] ?? 0,
                'lanes'      => $lanes,
                'lane_stale' => $laneStale,
                'history'    => $history,
                'generated_at' => $data['generated_at'] ?? now()->toIso8601String(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ], 503);
        }
    }

    /**
     * GET /api/fabric/viewer/parquet-config/{schema}/{view}/history
     * Historial de un parquet especifico (trazabilidad).
     */
    public function history(string $schema, string $view): JsonResponse
    {
        $history = \App\Models\BiParquetHistory::forView($schema, $view)
            ->orderBy('captured_at', 'desc')
            ->limit(50)
            ->get();

        return response()->json([
            'success' => true,
            'schema'  => $schema,
            'view'    => $view,
            'history' => $history,
        ]);
    }

    private function laneFromAvg(?float $avg): string
    {
        if ($avg === null) return 'nueva';
        if ($avg <= 30)  return 'sprint';
        if ($avg <= 180) return 'standard';
        if ($avg <= 900) return 'heavy';
        return 'marathon';
    }

    /**
     * Mapea la prioridad de Laravel a la que acepta Graph-Fabric.
     * Graph solo acepta: realtime, operativo, analitico.
     */
    private function mapPriorityForGraph(string $priority): string
    {
        return match ($priority) {
            'realtime'          => 'realtime',
            'high', 'operativo' => 'operativo',
            'medium'            => 'operativo',
            'low', 'analitico'  => 'analitico',
            'manual'            => 'analitico',
            default             => 'operativo',
        };
    }

    /**
     * POST /api/fabric/viewer/parquet-config/rebalance
     * Ejecuta el rebalanceo inteligente del scheduler (desactiva pequeñas,
     * sube intervalos de históricas) y sincroniza con Graph-Fabric.
     */
    public function rebalance(Request $request): JsonResponse
    {
        $dryRun = $request->boolean('dry_run', false);

        try {
            $exitCode = \Illuminate\Support\Facades\Artisan::call('fabric:rebalance-scheduler', array_filter([
                '--dry-run' => $dryRun,
                '--sync'    => !$dryRun,
            ]));

            $output = \Illuminate\Support\Facades\Artisan::output();

            return response()->json([
                'success' => $exitCode === 0,
                'dry_run' => $dryRun,
                'output'  => $output,
                'message' => $dryRun
                    ? 'Simulacion de rebalanceo completada (no se aplicaron cambios).'
                    : 'Rebalanceo aplicado y sincronizado con Graph-Fabric.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al rebalancear: ' . $e->getMessage(),
            ], 500);
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
