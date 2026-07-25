<?php

declare(strict_types=1);

namespace App\Http\Controllers\Fabric;

use App\Http\Controllers\Controller;
use App\Models\BiVistaErrorLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRUD/Consulta de logs de errores de vistas BI.
 * Alimenta el tab "Logs" del dashboard de metricas.
 */
class BiVistaErrorLogController extends Controller
{
    /**
     * GET /api/fabric/metrics/error-logs
     * Lista errores con filtros opcionales (fecha, schema, view, tipo).
     */
    public function index(Request $request): JsonResponse
    {
        $query = BiVistaErrorLog::query()->orderByDesc('created_at');

        if ($request->filled('schema')) {
            $query->where('schema_name', $request->schema);
        }
        if ($request->filled('view')) {
            $query->where('view_name', 'like', "%{$request->view}%");
        }
        if ($request->filled('error_type')) {
            $query->where('error_type', $request->error_type);
        }
        if ($request->filled('from') && $request->filled('to')) {
            $query->entreFechas($request->from, $request->to);
        }
        if ($request->boolean('unresolved')) {
            $query->noResueltos();
        }

        $limit = min((int) $request->input('limit', 50), 200);
        $logs = $query->limit($limit)->get();

        // Resumen
        $summary = [
            'total'    => BiVistaErrorLog::count(),
            'today'    => BiVistaErrorLog::whereDate('created_at', today())->count(),
            'timeouts' => BiVistaErrorLog::where('error_type', 'timeout')->whereDate('created_at', today())->count(),
            'fabric_errors' => BiVistaErrorLog::where('error_type', 'fabric_error')->whereDate('created_at', today())->count(),
            'auto_maintenance' => BiVistaErrorLog::where('auto_maintenance_applied', true)->noResueltos()->count(),
        ];

        return response()->json([
            'success' => true,
            'summary' => $summary,
            'data'    => $logs,
        ]);
    }

    /**
     * GET /api/fabric/metrics/error-logs/by-view
     * Agrupa errores por vista (para saber cuales fallan mas).
     */
    public function byView(Request $request): JsonResponse
    {
        $days = min((int) $request->input('days', 7), 30);

        $views = BiVistaErrorLog::query()
            ->select('schema_name', 'view_name', 'error_type')
            ->selectRaw('COUNT(*) as error_count')
            ->selectRaw('MAX(created_at) as last_error')
            ->where('created_at', '>=', now()->subDays($days))
            ->groupBy('schema_name', 'view_name', 'error_type')
            ->orderByDesc('error_count')
            ->limit(50)
            ->get();

        return response()->json([
            'success' => true,
            'days'    => $days,
            'data'    => $views,
        ]);
    }

    /**
     * POST /api/fabric/metrics/error-logs/{id}/resolve
     * Marca un error como resuelto (el admin quita mantenimiento).
     */
    public function resolve(Request $request, int $id): JsonResponse
    {
        $log = BiVistaErrorLog::findOrFail($id);
        $user = auth()->user();

        $log->update([
            'resolved_by' => $user->email,
            'resolved_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Error marcado como resuelto.',
        ]);
    }

    /**
     * POST /api/fabric/metrics/error-logs/resolve-view
     * Marca todos los errores de una vista como resueltos y quita mantenimiento.
     */
    public function resolveView(Request $request): JsonResponse
    {
        $request->validate([
            'schema' => 'required|string|max:20',
            'view'   => 'required|string|max:150',
        ]);

        $user = auth()->user();

        BiVistaErrorLog::where('schema_name', $request->schema)
            ->where('view_name', $request->view)
            ->noResueltos()
            ->update([
                'resolved_by' => $user->email,
                'resolved_at' => now(),
            ]);

        // Quitar mantenimiento de la vista
        $biGrupo = \App\Models\BiGrupo::where('codigo', strtoupper($request->schema))->first();
        if ($biGrupo) {
            \App\Models\BiVista::where('id_bi_grupos', $biGrupo->id)
                ->where('nombre', $request->view)
                ->where('estado', 'mantenimiento')
                ->update(['estado' => 'activo']);
        }

        return response()->json([
            'success' => true,
            'message' => "Vista {$request->schema}.{$request->view} reactivada.",
        ]);
    }
}
