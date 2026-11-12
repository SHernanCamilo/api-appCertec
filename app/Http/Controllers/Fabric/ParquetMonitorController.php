<?php

declare(strict_types=1);

namespace App\Http\Controllers\Fabric;

use App\Http\Controllers\Controller;
use App\Models\BiParquetIncident;
use App\Services\Fabric\BiVistasSyncService;
use App\Services\Fabric\GraphFabricGatewayService;
use App\Services\Fabric\GraphFabricService;
use App\Services\Fabric\ParquetIncidentNotifier;
use App\Services\Fabric\ParquetScheduleSyncService;
use App\Services\PermissionCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Proxy interno del Monitor de Parquets hacia Graph-Fabric.
 *
 * El navegador llama SIEMPRE a estos endpoints; nunca a Graph-Fabric directo.
 * Así el token de servicio (admin) no sale del backend.
 *
 * Rutas (prefijo /api/fabric/viewer/parquet-monitor):
 *   POST   /force                → dispara regeneración (botón rayo), <200ms
 *   GET    /force/status         → polling del warm mientras status=generating
 *   GET    /schedule             → stats (tarjetas) + views (tabla)
 *   GET    /live                 → summary + generating_now (panel en vivo)
 *   POST   /schedule             → agregar/editar vista en el schedule
 *   DELETE /schedule             → desactivar vista del schedule
 *   POST   /schedule/run         → ejecutar cron manual (202, background)
 */
class ParquetMonitorController extends Controller
{
    public function __construct(
        private readonly GraphFabricService $graph,
        private readonly GraphFabricGatewayService $gateway,
        private readonly BiVistasSyncService $vistas,
        private readonly PermissionCacheService $permisos,
    ) {}

    /**
     * El boton "Actualizar" del visor usa force() y forceStatus(), asi que ya no
     * son solo del monitor: los llama cualquier usuario. Esto evita que alguien
     * dispare la generacion de una vista que no puede ver.
     *
     * - Siempre: la vista debe estar ACTIVA en la plataforma.
     * - Quien administra el monitor (permiso del modulo BI-CRON-PARQUET o admin)
     *   puede forzar cualquier vista activa.
     * - El resto, solo vistas de esquemas y sedes a las que tiene acceso.
     *
     * @return JsonResponse|null  respuesta de rechazo, o null si puede continuar
     */
    private function rechazarSiNoPuede(Request $request, string $schema, string $view): ?JsonResponse
    {
        $estado = $this->vistas->checkVistaEstado($schema, $view);
        if (! $estado['activa']) {
            return response()->json([
                'success' => false,
                'estado'  => $estado['estado'],
                'message' => $estado['mensaje'] ?? 'La vista no esta disponible.',
            ], $estado['estado'] === 'mantenimiento' ? 503 : 403);
        }

        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'No autenticado.'], 401);
        }

        $esMonitor = false;
        foreach (['BI-CRON-PARQUET-leer', 'BI-CRON-PARQUET-editar', 'BI-CRON-PARQUET'] as $codigo) {
            if ($this->permisos->hasPermission($user, $codigo)) {
                $esMonitor = true;
                break;
            }
        }

        if (! $esMonitor && (
            ! $this->gateway->tieneAccesoEsquema($user, $schema)
            || ! $this->gateway->tieneAccesoVistaPorSede($user, $view, $schema)
        )) {
            return response()->json(['success' => false, 'message' => "Sin acceso a la vista '{$view}'."], 403);
        }

        return null;
    }

    /** Reglas comunes de schema/vista. */
    private const REGLAS_VISTA = [
        'schema_name' => 'required|string|max:20|alpha_dash',
        'view'        => 'required|string|max:150|regex:/^[A-Za-z0-9_]+$/',
    ];

    // =========================================================================
    // A. FORZAR GENERACIÓN (botón rayo) — warm + polling
    // =========================================================================

    /**
     * Paso 1: dispara la regeneración priorizando la vista. Responde de
     * inmediato para no bloquear el navegador.
     *
     * POST /parquet-monitor/force
     */
    public function force(Request $request): JsonResponse
    {
        $datos = $request->validate(self::REGLAS_VISTA);

        if ($rechazo = $this->rechazarSiNoPuede($request, strtolower($datos['schema_name']), $datos['view'])) {
            return $rechazo;
        }

        $res = $this->graph->warmView(
            strtolower($datos['schema_name']),
            $datos['view'],
            true // force: regenera aunque parezca fresco
        );

        if (!$res['ok']) {
            return response()->json([
                'success' => false,
                'message' => $res['message'] ?? 'No se pudo iniciar la regeneración.',
            ], $res['code'] ?? 502);
        }

        return response()->json([
            'success'         => true,
            'status'          => $res['status'],
            'poll_interval_s' => $res['poll_interval_s'],
            'estimated_s'     => $res['estimated_s'],
            'message'         => $this->mensajeSegunEstado($res['status'], $res['message'] ?? null),
        ]);
    }

    /**
     * Paso 2: estado del warm. El front llama esto cada `poll_interval_s`
     * mientras el estado sea "generating".
     *
     * GET /parquet-monitor/force/status?schema=dc&view=VW_X
     */
    public function forceStatus(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'schema' => 'required|string|max:20|alpha_dash',
            'view'   => 'required|string|max:150|regex:/^[A-Za-z0-9_]+$/',
        ]);

        if ($rechazo = $this->rechazarSiNoPuede($request, strtolower($datos['schema']), $datos['view'])) {
            return $rechazo;
        }

        $res = $this->graph->warmStatus(strtolower($datos['schema']), $datos['view']);

        if (!$res['ok']) {
            return response()->json([
                'success' => false,
                'message' => $res['message'] ?? 'No se pudo consultar el estado.',
            ], $res['code'] ?? 502);
        }

        return response()->json([
            'success'         => true,
            'status'          => $res['status'],
            'source'          => $res['source'],
            'age_hours'       => $res['age_hours'],
            'size_mb'         => $res['size_mb'],
            'row_count'       => $res['row_count'],
            'generating'      => $res['generating'],
            'estimated_s'     => $res['estimated_s'],
            'poll_interval_s' => $res['poll_interval_s'],
            'generated_at'    => $res['generated_at'] ?? null,
            'last_result'     => $res['last_result'] ?? null,
            'last_error'      => $res['last_error'] ?? null,
            'message'         => $this->mensajeSegunEstado($res['status'], $res['message'] ?? null),
        ]);
    }

    /**
     * El visor reporta que "Actualizar" no se pudo completar: superó el tope de
     * espera (5 min) o la generación FALLÓ. Guarda el motivo y avisa a TI.
     *
     * - reason = timeout: la generación NO se cancela, sigue en Graph-Fabric.
     * - reason = error:   `detail` lleva el motivo que devolvió el servidor
     *                     (por ejemplo el error de la consulta a Fabric).
     *
     * Se reutiliza el incidente de la misma vista y motivo para no inundar de
     * correos si el usuario reintenta varias veces.
     *
     * POST /parquet-monitor/refresh-failure
     */
    public function reportRefreshFailure(Request $request, ParquetIncidentNotifier $notifier): JsonResponse
    {
        $datos = $request->validate(self::REGLAS_VISTA + [
            'elapsed_s' => 'required|integer|min:0|max:86400',
            'reason'    => 'required|in:timeout,error',
            'detail'    => 'nullable|string|max:2000',
        ]);

        $schema = strtolower($datos['schema_name']);
        $reason = $datos['reason'];
        $detail = isset($datos['detail']) ? mb_substr(trim($datos['detail']), 0, 500) : null;
        $user   = $request->user();

        $existente = BiParquetIncident::forView($schema, $datos['view'])
            ->where('reason', $reason)
            ->where(function ($q) use ($reason) {
                // timeout: mientras siga abierto. error: el mismo fallo en los ultimos 10 min.
                $reason === BiParquetIncident::REASON_TIMEOUT
                    ? $q->where('status', BiParquetIncident::STATUS_OPEN)
                    : $q->where('created_at', '>=', now()->subMinutes(10));
            })
            ->first();

        if ($existente) {
            $existente->update([
                'elapsed_s' => max($existente->elapsed_s, (int) $datos['elapsed_s']),
                'detail'    => $detail ?: $existente->detail,
            ]);

            return response()->json(['success' => true, 'incident_id' => $existente->id, 'notified' => false]);
        }

        $incidente = BiParquetIncident::create([
            'schema_name' => $schema,
            'view_name'   => $datos['view'],
            'source'      => BiParquetIncident::SOURCE_USER,
            'reason'      => $reason,
            'user_id'     => $user?->id,
            'user_name'   => $user->name ?? null,
            'elapsed_s'   => (int) $datos['elapsed_s'],
            'status'      => BiParquetIncident::STATUS_OPEN,
            'detail'      => $detail ?? ($reason === BiParquetIncident::REASON_TIMEOUT
                ? 'El usuario pidio actualizar y la generacion supero el tope de espera.'
                : 'La generacion fallo (sin detalle del servidor).'),
        ]);

        $notifier->notify($incidente);

        return response()->json(['success' => true, 'incident_id' => $incidente->id, 'notified' => true]);
    }

    /**
     * Trazabilidad del barrido de las 04:00.
     *
     * Cruza las vistas que deben ejecutarse (habilitadas y activas) con lo que
     * reporta el scheduler de Graph-Fabric y clasifica cada una:
     *
     *   ok       generada dentro de la ventana del barrido (desde --start hasta --until)
     *   error    la ultima generacion fallo (con su motivo)
     *   despues  generada despues de la ventana (normalmente un clic en Actualizar)
     *   pendiente sin generar desde el inicio de la ventana
     *
     * Limitacion: el scheduler guarda solo la ULTIMA generacion. Si una vista se
     * genero a las 04:30 y alguien la actualizo a las 11:00, aparece en "despues".
     *
     * GET /parquet-monitor/nightly?date=2026-11-12&start=04:00&until=10:00
     */
    public function nightly(Request $request, ParquetScheduleSyncService $sync): JsonResponse
    {
        $datos = $request->validate([
            'date'  => 'nullable|date_format:Y-m-d',
            'start' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'until' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
        ]);

        $tz    = (string) config('app.timezone', 'America/Bogota');
        $dia   = $datos['date'] ?? now($tz)->toDateString();
        $desde = \Carbon\CarbonImmutable::parse("{$dia} " . ($datos['start'] ?? '04:00'), $tz);
        $hasta = \Carbon\CarbonImmutable::parse("{$dia} " . ($datos['until'] ?? '10:00'), $tz);

        $res = $this->graph->schedule();
        if (! $res['ok']) {
            return response()->json([
                'success' => false,
                'message' => $res['message'] ?? 'No se pudo consultar Graph-Fabric.',
            ], $res['code'] ?? 502);
        }

        $graph = [];
        foreach (($res['data']['views'] ?? []) as $v) {
            $graph[strtolower(($v['schema'] ?? '') . '.' . ($v['view'] ?? ''))] = $v;
        }

        $buckets = ['ok' => 0, 'error' => 0, 'despues' => 0, 'pendiente' => 0];
        $filas = [];
        $inicio = null;
        $fin = null;
        $segundos = 0.0;

        foreach ($sync->eligibleConfigs() as $c) {
            $g    = $graph[strtolower("{$c->schema_name}.{$c->view_name}")] ?? null;
            $at   = $g['last_generated'] ?? null;
            $gen  = $at ? \Carbon\CarbonImmutable::parse($at)->setTimezone($tz) : null;
            $dur  = isset($g['last_gen_s']) ? (float) $g['last_gen_s'] : null;
            $err  = ($g['status'] ?? null) === 'error' ? ($g['last_error'] ?? 'Error sin detalle') : null;

            $estado = match (true) {
                $err !== null                              => 'error',
                $gen !== null && $gen->betweenIncluded($desde, $hasta) => 'ok',
                $gen !== null && $gen->greaterThan($hasta) => 'despues',
                default                                    => 'pendiente',
            };
            $buckets[$estado]++;

            if ($estado === 'ok') {
                $segundos += $dur ?? 0;
                $empezo = $dur !== null ? $gen->subSeconds((int) round($dur)) : $gen;
                $inicio = $inicio === null || $empezo->lessThan($inicio) ? $empezo : $inicio;
                $fin    = $fin === null || $gen->greaterThan($fin) ? $gen : $fin;
            }

            $filas[] = [
                'schema'       => $c->schema_name,
                'view'         => $c->view_name,
                'priority'     => $c->priority,
                'estado'       => $estado,
                'generated_at' => $gen?->toIso8601String(),
                'duration_s'   => $dur,
                'rows'         => isset($g['row_count']) ? (int) $g['row_count'] : null,
                'error'        => $err,
                'en_graph'     => $g !== null,
            ];
        }

        // Orden: primero lo que hay que atender, luego las mas lentas
        $peso = ['error' => 0, 'pendiente' => 1, 'despues' => 2, 'ok' => 3];
        usort($filas, fn (array $a, array $b): int => [$peso[$a['estado']], -($a['duration_s'] ?? 0)]
            <=> [$peso[$b['estado']], -($b['duration_s'] ?? 0)]);

        return response()->json([
            'success' => true,
            'date'    => $dia,
            'window'  => ['start' => $desde->toIso8601String(), 'until' => $hasta->toIso8601String()],
            'summary' => $buckets + [
                'total'            => count($filas),
                'first_start'      => $inicio?->toIso8601String(),
                'last_end'         => $fin?->toIso8601String(),
                'elapsed_min'      => $inicio && $fin ? round($inicio->diffInSeconds($fin) / 60, 1) : null,
                'generation_min'   => round($segundos / 60, 1),
            ],
            'views'   => $filas,
        ]);
    }

    /**
     * Incidentes recientes (demoras y fallos de "Actualizar") para el monitor.
     *
     * GET /parquet-monitor/incidents?hours=24
     */
    public function incidents(Request $request): JsonResponse
    {
        $horas = max(1, min(168, (int) $request->query('hours', 24)));

        $lista = BiParquetIncident::where('created_at', '>=', now()->subHours($horas))
            ->orderByDesc('created_at')
            ->limit(100)
            ->get(['id', 'schema_name', 'view_name', 'source', 'reason', 'user_name', 'elapsed_s', 'stage', 'status', 'detail', 'created_at']);

        return response()->json(['success' => true, 'hours' => $horas, 'data' => $lista]);
    }

    // =========================================================================
    // B. MONITOREO
    // =========================================================================

    /**
     * Tarjetas de estado + tabla de vistas.
     *
     * GET /parquet-monitor/schedule
     */
    public function schedule(): JsonResponse
    {
        $res = $this->graph->schedule();

        if (!$res['ok']) {
            return response()->json([
                'success' => false,
                'message' => $res['message'] ?? 'No se pudo obtener el schedule.',
                'stats'   => new \stdClass(),
                'views'   => [],
            ], $res['code'] ?? 502);
        }

        $d = $res['data'];

        return response()->json([
            'success'    => true,
            'stats'      => $d['stats']  ?? new \stdClass(),
            'views'      => $d['views']  ?? [],
            'due_count'  => (int) ($d['due_count'] ?? 0),
        ]);
    }

    /**
     * Panel "Generando ahora" + tarjeta "En riesgo de SLA" (summary.overdue).
     * El front refresca esto cada 15s con Auto ON.
     *
     * GET /parquet-monitor/live
     */
    public function live(): JsonResponse
    {
        $res = $this->graph->statusLive();

        if (!$res['ok']) {
            return response()->json([
                'success'        => false,
                'message'        => $res['message'] ?? 'No se pudo obtener el estado en vivo.',
                'summary'        => new \stdClass(),
                'generating_now' => [],
            ], $res['code'] ?? 502);
        }

        $d = $res['data'];

        return response()->json([
            'success'        => true,
            'summary'        => $d['summary'] ?? new \stdClass(),
            'generating_now' => $d['generating_now'] ?? [],
        ]);
    }

    // =========================================================================
    // C. GESTIÓN DEL SCHEDULE
    // =========================================================================

    /**
     * Agregar o editar una vista en el schedule.
     *
     * POST /parquet-monitor/schedule
     */
    public function upsert(Request $request): JsonResponse
    {
        $datos = $request->validate(self::REGLAS_VISTA + [
            'refresh_interval_min' => 'required|integer|min:1|max:1440',
            'priority'             => 'nullable|string|max:20',
            'group_name'           => 'nullable|string|max:60',
        ]);

        $res = $this->graph->scheduleUpsert(
            strtolower($datos['schema_name']),
            $datos['view'],
            (int) $datos['refresh_interval_min'],
            $datos['priority']   ?? 'medium',
            $datos['group_name'] ?? 'General'
        );

        if (!$res['ok']) {
            return response()->json([
                'success' => false,
                'message' => $res['message'] ?? 'No se pudo guardar en el schedule.',
            ], $res['code'] ?? 502);
        }

        return response()->json([
            'success' => true,
            'message' => 'Vista programada correctamente en Graph-Fabric.',
            'data'    => $res['data'] ?? new \stdClass(),
        ]);
    }

    /**
     * Desactivar una vista del schedule (botón basura).
     *
     * DELETE /parquet-monitor/schedule?schema=dc&view=VW_X
     */
    public function remove(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'schema' => 'required|string|max:20|alpha_dash',
            'view'   => 'required|string|max:150|regex:/^[A-Za-z0-9_]+$/',
        ]);

        $res = $this->graph->scheduleDelete(strtolower($datos['schema']), $datos['view']);

        if (!$res['ok']) {
            return response()->json([
                'success' => false,
                'message' => $res['message'] ?? 'No se pudo desactivar la vista.',
            ], $res['code'] ?? 502);
        }

        return response()->json([
            'success' => true,
            'message' => 'Vista desactivada del schedule.',
        ]);
    }

    /**
     * Ejecutar el cron manualmente. Graph-Fabric responde 202 y corre en
     * background; aquí solo se confirma el inicio.
     *
     * POST /parquet-monitor/schedule/run
     */
    public function run(): JsonResponse
    {
        $res = $this->graph->scheduleRun();

        if (!$res['ok']) {
            return response()->json([
                'success' => false,
                'message' => $res['message'] ?? 'No se pudo iniciar la corrida.',
            ], $res['code'] ?? 502);
        }

        return response()->json([
            'success' => true,
            'message' => 'Corrida del cron iniciada en Graph-Fabric. Se ejecuta en segundo plano.',
            'data'    => $res['data'] ?? new \stdClass(),
        ]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** Traduce el estado de Graph-Fabric a un mensaje claro en español. */
    private function mensajeSegunEstado(string $estado, ?string $mensajeApi): string
    {
        if (is_string($mensajeApi) && trim($mensajeApi) !== '') {
            return $mensajeApi;
        }

        return match ($estado) {
            'ready'       => 'El parquet está actualizado.',
            'ready_stale' => 'Se sirve el parquet actual y se refresca en segundo plano.',
            'generating'  => 'Priorizando y generando el parquet...',
            'too_big'     => 'La vista supera el límite de filas permitido para generar el parquet.',
            default       => 'Estado reportado por Graph-Fabric: ' . $estado,
        };
    }
}
