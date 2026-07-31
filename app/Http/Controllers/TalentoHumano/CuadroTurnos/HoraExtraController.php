<?php

namespace App\Http\Controllers\TalentoHumano\CuadroTurnos;

use App\Http\Controllers\Controller;
use App\Models\TalentoHumano\CuadroTurnos\HoraExtra;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class HoraExtraController extends Controller
{
    /**
     * GET /api/turnos/horas-extras?id_empleado=X&fecha=Y o &mes=M&anio=A
     */
    public function index(Request $request): JsonResponse
    {
        $query = HoraExtra::query()->orderBy('fecha');

        if ($request->filled('id_empleado')) {
            $query->porEmpleado((int) $request->id_empleado);
        }
        if ($request->filled('fecha')) {
            $query->porFecha($request->fecha);
        }
        if ($request->filled('anio') && $request->filled('mes')) {
            $inicio = "{$request->anio}-" . str_pad($request->mes, 2, '0', STR_PAD_LEFT) . "-01";
            $fin = date('Y-m-t', strtotime($inicio));
            $query->whereBetween('fecha', [$inicio, $fin]);
        }

        return response()->json(['success' => true, 'data' => $query->get()]);
    }

    /**
     * POST /api/turnos/horas-extras
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'id_empleado' => 'required|integer',
            'fecha'       => 'required|date',
            'hora_inicio' => 'required',
            'hora_fin'    => 'required',
            'tipo'        => 'nullable|in:hora_extra,evento',
            'motivo'      => 'nullable|string|max:255',
            'id_cuadro'   => 'nullable|integer',
        ]);

        $data = $request->all();
        $data['registrado_por'] = auth()->id();
        $data['tipo'] = $data['tipo'] ?? 'hora_extra';

        $registro = HoraExtra::create($data);

        return response()->json([
            'success' => true,
            'data' => $registro,
            'message' => 'Hora extra registrada.',
        ], 201);
    }

    /**
     * DELETE /api/turnos/horas-extras/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        HoraExtra::findOrFail($id)->delete();
        return response()->json(['success' => true, 'message' => 'Registro eliminado.']);
    }
}
