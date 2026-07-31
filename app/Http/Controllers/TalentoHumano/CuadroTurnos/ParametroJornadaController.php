<?php

namespace App\Http\Controllers\TalentoHumano\CuadroTurnos;

use App\Http\Controllers\Controller;
use App\Models\TalentoHumano\CuadroTurnos\ParametroJornada;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;

class ParametroJornadaController extends Controller
{
    public function index(): JsonResponse
    {
        $parametros = ParametroJornada::orderByDesc('vigente_desde')->get();
        return response()->json(['success' => true, 'data' => $parametros]);
    }

    public function vigente(): JsonResponse
    {
        $parametro = ParametroJornada::vigente();
        return response()->json(['success' => true, 'data' => $parametro]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'horas_max_dia'           => 'required|numeric|min:1|max:24',
            'horas_max_semana'        => 'required|numeric|min:1|max:168',
            'horas_max_mes'           => 'nullable|numeric|min:1',
            'jornada_diurna_inicio'   => 'required',
            'jornada_diurna_fin'      => 'required',
            'jornada_nocturna_inicio' => 'required',
            'jornada_nocturna_fin'    => 'required',
            'vigente_desde'           => 'required|date',
            'vigente_hasta'           => 'nullable|date|after_or_equal:vigente_desde',
            'observacion'             => 'nullable|string|max:255',
        ]);

        // Calcular horas_max_mes si no viene
        if (empty($data['horas_max_mes'])) {
            $data['horas_max_mes'] = round($data['horas_max_semana'] * 4.33, 0);
        }

        // Cerrar vigencia del par+ímetro actual (el que no tiene vigente_hasta)
        ParametroJornada::whereNull('vigente_hasta')
            ->where('activo', true)
            ->update(['vigente_hasta' => Carbon::parse($data['vigente_desde'])->subDay()->toDateString()]);

        $parametro = ParametroJornada::create($data);

        return response()->json(['success' => true, 'data' => $parametro, 'message' => 'Par+ímetro de jornada creado.'], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $parametro = ParametroJornada::findOrFail($id);

        $data = $request->validate([
            'horas_max_dia'           => 'required|numeric|min:1|max:24',
            'horas_max_semana'        => 'required|numeric|min:1|max:168',
            'horas_max_mes'           => 'nullable|numeric|min:1',
            'jornada_diurna_inicio'   => 'required',
            'jornada_diurna_fin'      => 'required',
            'jornada_nocturna_inicio' => 'required',
            'jornada_nocturna_fin'    => 'required',
            'vigente_desde'           => 'required|date',
            'vigente_hasta'           => 'nullable|date',
            'observacion'             => 'nullable|string|max:255',
            'activo'                  => 'nullable|boolean',
        ]);

        if (empty($data['horas_max_mes']) && isset($data['horas_max_semana'])) {
            $data['horas_max_mes'] = round($data['horas_max_semana'] * 4.33, 0);
        }

        $parametro->update($data);
        return response()->json(['success' => true, 'data' => $parametro->fresh(), 'message' => 'Par+ímetro actualizado.']);
    }

    public function destroy(int $id): JsonResponse
    {
        $parametro = ParametroJornada::findOrFail($id);
        $parametro->update(['activo' => false]);
        return response()->json(['success' => true, 'message' => 'Par+ímetro desactivado.']);
    }
}
