<?php

namespace App\Http\Controllers\TalentoHumano\CuadroTurnos;

use App\Http\Controllers\Controller;
use App\Models\TalentoHumano\CuadroTurnos\TipoRecargo;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class TipoRecargoController extends Controller
{
    public function index(): JsonResponse
    {
        $tipos = TipoRecargo::orderBy('codigo')->get();
        return response()->json(['success' => true, 'data' => $tipos]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'codigo'                   => 'required|string|max:10|unique:humtal_tipos_recargo,codigo',
            'nombre'                   => 'required|string|max:100',
            'porcentaje'               => 'required|numeric|min:0|max:500',
            'es_hora_extra'            => 'nullable|boolean',
            'aplica_dominical_festivo' => 'nullable|boolean',
            'hora_inicio'              => 'nullable',
            'hora_fin'                 => 'nullable',
        ]);

        $tipo = TipoRecargo::create($data);
        return response()->json(['success' => true, 'data' => $tipo, 'message' => 'Tipo de recargo creado.'], 201);
    }

    public function show(int $id): JsonResponse
    {
        $tipo = TipoRecargo::findOrFail($id);
        return response()->json(['success' => true, 'data' => $tipo]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $tipo = TipoRecargo::findOrFail($id);

        $data = $request->validate([
            'codigo'                   => "required|string|max:10|unique:humtal_tipos_recargo,codigo,{$id}",
            'nombre'                   => 'required|string|max:100',
            'porcentaje'               => 'required|numeric|min:0|max:500',
            'es_hora_extra'            => 'nullable|boolean',
            'aplica_dominical_festivo' => 'nullable|boolean',
            'hora_inicio'              => 'nullable',
            'hora_fin'                 => 'nullable',
            'activo'                   => 'nullable|boolean',
        ]);

        $tipo->update($data);
        return response()->json(['success' => true, 'data' => $tipo->fresh(), 'message' => 'Tipo de recargo actualizado.']);
    }

    public function destroy(int $id): JsonResponse
    {
        $tipo = TipoRecargo::findOrFail($id);
        $tipo->update(['activo' => false]);
        return response()->json(['success' => true, 'message' => 'Tipo de recargo desactivado.']);
    }
}
