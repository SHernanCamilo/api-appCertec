<?php

declare(strict_types=1);

namespace App\Http\Controllers\Notificaciones;

use App\Http\Controllers\Controller;
use App\Models\Notificaciones\NotifEmailLog;
use App\Models\Notificaciones\NotifEmailTrace;
use App\Services\Notificaciones\GraphBounceCheckerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Dashboard de Notificaciones de Interconsultas.
 *
 * Endpoints:
 *   GET /api/notificaciones/dashboard       → Estadísticas generales
 *   GET /api/notificaciones/emails          → Listado con filtros
 *   GET /api/notificaciones/emails/{id}     → Detalle + trazas
 *   POST /api/notificaciones/check-bounces  → Forzar verificación de rebotes
 */
class NotificacionDashboardController extends Controller
{
    /**
     * GET /api/notificaciones/dashboard
     *
     * Resumen general: total enviados, entregados, rebotados, pendientes, errores.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $fechaDesde = $request->input('fecha_desde', now()->startOfDay()->toDateString());
        $fechaHasta = $request->input('fecha_hasta', now()->endOfDay()->toDateString());

        $stats = NotifEmailLog::select(
            DB::raw("COUNT(*) as total"),
            DB::raw("SUM(CASE WHEN status = 'SENT' THEN 1 ELSE 0 END) as enviados"),
            DB::raw("SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END) as pendientes_envio"),
            DB::raw("SUM(CASE WHEN status = 'ERROR' THEN 1 ELSE 0 END) as errores"),
            DB::raw("SUM(CASE WHEN status = 'EXPIRED' THEN 1 ELSE 0 END) as expirados"),
            DB::raw("SUM(CASE WHEN delivery_status = 'DELIVERED' THEN 1 ELSE 0 END) as entregados"),
            DB::raw("SUM(CASE WHEN delivery_status = 'BOUNCED' THEN 1 ELSE 0 END) as rebotados"),
            DB::raw("SUM(CASE WHEN delivery_status = 'PENDING' AND status = 'SENT' THEN 1 ELSE 0 END) as pendientes_verificacion"),
            DB::raw("SUM(CASE WHEN tipo = 'INTERCONSULTA_SOLICITUD' THEN 1 ELSE 0 END) as solicitudes"),
            DB::raw("SUM(CASE WHEN tipo = 'INTERCONSULTA_ANULACION' THEN 1 ELSE 0 END) as anulaciones")
        )
        ->whereBetween('created_at', [
            Carbon::parse($fechaDesde)->startOfDay(),
            Carbon::parse($fechaHasta)->endOfDay(),
        ])
        ->first();

        $totalEnviados = (int) $stats->enviados;
        $totalEntregados = (int) $stats->entregados;
        $tasaEntrega = $totalEnviados > 0
            ? round(($totalEntregados / $totalEnviados) * 100, 1)
            : 0;

        return response()->json([
            'success' => true,
            'data'    => [
                'periodo' => [
                    'desde' => $fechaDesde,
                    'hasta' => $fechaHasta,
                ],
                'resumen' => [
                    'total'                  => (int) $stats->total,
                    'enviados'               => $totalEnviados,
                    'entregados'             => $totalEntregados,
                    'rebotados'              => (int) $stats->rebotados,
                    'pendientes_envio'       => (int) $stats->pendientes_envio,
                    'pendientes_verificacion' => (int) $stats->pendientes_verificacion,
                    'errores'                => (int) $stats->errores,
                    'expirados'              => (int) $stats->expirados,
                    'tasa_entrega'           => $tasaEntrega,
                ],
                'por_tipo' => [
                    'solicitudes' => (int) $stats->solicitudes,
                    'anulaciones' => (int) $stats->anulaciones,
                ],
            ],
        ]);
    }

    /**
     * GET /api/notificaciones/emails
     *
     * Listado con filtros: status, delivery_status, tipo, email_to, fecha
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status'          => 'nullable|in:PENDING,SENT,ERROR,EXPIRED',
            'delivery_status' => 'nullable|in:PENDING,DELIVERED,BOUNCED,FAILED',
            'tipo'            => 'nullable|in:INTERCONSULTA_SOLICITUD,INTERCONSULTA_ANULACION',
            'email_to'        => 'nullable|string|max:150',
            'identificacion'  => 'nullable|string|max:20',
            'fecha_desde'     => 'nullable|date',
            'fecha_hasta'     => 'nullable|date',
            'per_page'        => 'nullable|integer|min:1|max:100',
        ]);

        $query = NotifEmailLog::select([
            'id', 'tipo', 'identificacion_paciente', 'nombre_paciente',
            'profesional_nombre', 'email_to', 'subject', 'status',
            'delivery_status', 'error_message', 'bounce_reason',
            'intentos', 'fecha_envio', 'fecha_intento', 'delivered_at',
            'bounce_detected_at', 'especialidad', 'clinica', 'estado_orden',
            'created_at',
        ]);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('delivery_status')) {
            $query->where('delivery_status', $request->delivery_status);
        }
        if ($request->filled('tipo')) {
            $query->where('tipo', $request->tipo);
        }
        if ($request->filled('email_to')) {
            $query->where('email_to', 'LIKE', '%' . $request->email_to . '%');
        }
        if ($request->filled('identificacion')) {
            $query->where('identificacion_paciente', $request->identificacion);
        }
        if ($request->filled('fecha_desde')) {
            $query->whereDate('created_at', '>=', $request->fecha_desde);
        }
        if ($request->filled('fecha_hasta')) {
            $query->whereDate('created_at', '<=', $request->fecha_hasta);
        }

        $perPage = (int) $request->input('per_page', 20);
        $emails  = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => $emails->items(),
            'meta'    => [
                'total'        => $emails->total(),
                'per_page'     => $emails->perPage(),
                'current_page' => $emails->currentPage(),
                'last_page'    => $emails->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/notificaciones/emails/{id}
     *
     * Detalle de un email con toda su trazabilidad.
     */
    public function show(int $id): JsonResponse
    {
        $email = NotifEmailLog::find($id);

        if (!$email) {
            return response()->json(['success' => false, 'message' => 'Email no encontrado'], 404);
        }

        $traces = NotifEmailTrace::where('email_log_id', $id)
            ->orderBy('created_at')
            ->get(['id', 'event_type', 'event_status', 'event_message', 'event_details', 'created_at']);

        return response()->json([
            'success' => true,
            'data'    => [
                'email'  => $email,
                'traces' => $traces,
            ],
        ]);
    }

    /**
     * POST /api/notificaciones/check-bounces
     *
     * Forzar verificación de rebotes (manual desde el dashboard).
     */
    public function checkBounces(): JsonResponse
    {
        try {
            $checker = app(GraphBounceCheckerService::class);
            $result  = $checker->checkAllPending();

            return response()->json([
                'success' => true,
                'message' => "Verificación completada: {$result['checked']} revisados, {$result['bounced']} rebotados, {$result['delivered']} entregados.",
                'data'    => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error verificando rebotes: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/notificaciones/rebotados
     *
     * Lista de emails rebotados (para acción correctiva).
     */
    public function rebotados(Request $request): JsonResponse
    {
        $emails = NotifEmailLog::where('delivery_status', NotifEmailLog::DELIVERY_BOUNCED)
            ->select([
                'id', 'email_to', 'profesional_nombre', 'identificacion_paciente',
                'nombre_paciente', 'bounce_reason', 'bounce_detected_at',
                'especialidad', 'clinica', 'created_at',
            ])
            ->orderByDesc('bounce_detected_at')
            ->take(50)
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $emails,
        ]);
    }
}
