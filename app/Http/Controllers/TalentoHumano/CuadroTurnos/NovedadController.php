<?php

namespace App\Http\Controllers\TalentoHumano\CuadroTurnos;

use App\Http\Controllers\Controller;
use App\Models\TalentoHumano\CuadroTurnos\CtFestivo;
use App\Models\TalentoHumano\CuadroTurnos\CtNovedad;
use App\Services\TalentoHumano\CuadroTurnos\CuadroTurnoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NovedadController extends Controller
{
    public function __construct(private CuadroTurnoService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = CtNovedad::with(['novedadTipo', 'empleado', 'asignacion']);

        if ($request->filled('id_cuadro')) {
            $query->where('id_cuadro', (int) $request->id_cuadro);
        }
        if ($request->filled('id_empleado')) {
            $query->where('id_empleado', (int) $request->id_empleado);
        }

        return response()->json([
            'success' => true,
            'data'    => $query->orderByDesc('created_at')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'id_cuadro'             => 'required|integer|exists:humtal_ct_cuadro,id',
            'id_empleado'           => 'required|integer|exists:config_person_tercero,id',
            'id_novedad_tipo'       => 'required|integer|exists:humtal_ct_novedad_tipo,id',
            'fecha_inicio'          => 'required|date',
            'fecha_fin'             => 'nullable|date|after_or_equal:fecha_inicio',
            'id_asignacion'         => 'nullable|integer|exists:humtal_ct_asignacion,id',
            'id_empleado_reemplaza' => 'nullable|integer|exists:config_person_tercero,id',
            'motivo'                => 'nullable|string|max:255',
            'observacion'           => 'nullable|string|max:500',
        ]);

        try {
            $data = $request->all();
            $data['solicitado_por'] = auth()->id();
            $data['estado'] = CtNovedad::ESTADO_PENDIENTE;
            $data['fecha_fin'] = $data['fecha_fin'] ?? $data['fecha_inicio'];

            $novedad = $this->service->crearNovedad($data);

            return response()->json([
                'success' => true,
                'message' => 'Novedad registrada.',
                'data'    => $novedad->load(['novedadTipo', 'empleado']),
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function show(int $id): JsonResponse
    {
        $novedad = CtNovedad::with(['novedadTipo', 'empleado', 'asignacion'])->findOrFail($id);
        return response()->json(['success' => true, 'data' => $novedad]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'motivo'      => 'nullable|string|max:255',
            'observacion' => 'nullable|string|max:500',
        ]);

        $novedad = CtNovedad::findOrFail($id);
        $novedad->update($request->only(['motivo', 'observacion']));

        return response()->json(['success' => true, 'message' => 'Novedad actualizada.', 'data' => $novedad->fresh()]);
    }

    public function destroy(int $id): JsonResponse
    {
        $novedad = CtNovedad::findOrFail($id);
        if ($novedad->estado !== CtNovedad::ESTADO_PENDIENTE) {
            return response()->json(['success' => false, 'message' => 'Solo se pueden eliminar novedades pendientes.'], 422);
        }
        $novedad->delete();
        return response()->json(['success' => true, 'message' => 'Novedad eliminada.']);
    }

    public function aprobar(Request $request, int $id): JsonResponse
    {
        try {
            $novedad = $this->service->aprobarNovedad($id, auth()->id(), $request->input('comentario'));
            return response()->json(['success' => true, 'message' => 'Novedad aprobada.', 'data' => $novedad]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function rechazar(Request $request, int $id): JsonResponse
    {
        $request->validate(['comentario' => 'required|string|max:500']);
        try {
            $novedad = $this->service->rechazarNovedad($id, auth()->id(), $request->comentario);
            return response()->json(['success' => true, 'message' => 'Novedad rechazada.', 'data' => $novedad]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }
}
