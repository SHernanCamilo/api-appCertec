<?php

declare(strict_types=1);

namespace App\Http\Controllers\Fabric;

use App\Http\Controllers\Controller;
use App\Services\Fabric\GraphFabricService;
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
    public function __construct(private readonly GraphFabricService $graph) {}

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
            'message'         => $this->mensajeSegunEstado($res['status'], $res['message'] ?? null),
        ]);
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
