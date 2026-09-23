<?php

namespace App\Http\Controllers\TalentoHumano\CuadroTurnos;

use App\Http\Controllers\Controller;
use App\Models\TalentoHumano\CuadroTurnos\ParametroJornada;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;

class ParametroJornadaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ParametroJornada::with('empresa')->orderByDesc('vigente_desde');

        // Filtro por empresa segun el nivel de acceso del usuario
        $user = auth()->user();
        if ($user) {
            $accessControl = new \App\Services\TalentoHumano\CuadroTurnos\AccessControlService($user);
            $accessLevel = $accessControl->getAccessLevel();

            if ($accessLevel === 'super_admin' || $accessLevel === 'transversal') {
                // Admin/transversal: puede filtrar por una empresa concreta o ver todas
                if ($request->filled('id_empresa')) {
                    $query->where('id_empresa', (int) $request->id_empresa);
                }
            } else {
                // Empresa admin / responsable: solo sus empresas permitidas
                $empresasPermitidas = $accessControl->getUnidades()
                    ->pluck('id_empresa')->unique()->filter()->values()->toArray();
                if (empty($empresasPermitidas)) {
                    $empresasPermitidas = config('cuadro_turnos.empresas_habilitadas', []);
                }
                if (!empty($empresasPermitidas)) {
                    $query->whereIn('id_empresa', $empresasPermitidas);
                }
                // Si ademas se pide una empresa concreta (dentro de las permitidas)
                if ($request->filled('id_empresa')) {
                    $query->where('id_empresa', (int) $request->id_empresa);
                }
            }
        }

        return response()->json(['success' => true, 'data' => $query->get()]);
    }

    public function vigente(Request $request): JsonResponse
    {
        $idEmpresa = $request->filled('id_empresa') ? (int) $request->id_empresa : null;
        $parametro = ParametroJornada::vigente($idEmpresa);
        return response()->json(['success' => true, 'data' => $parametro]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id_empresa'              => 'required|integer|exists:ent_empresas,id',
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

        // Cerrar vigencia del parametro actual DE LA MISMA EMPRESA (el que no tiene vigente_hasta)
        ParametroJornada::whereNull('vigente_hasta')
            ->where('activo', true)
            ->where('id_empresa', $data['id_empresa'])
            ->update(['vigente_hasta' => Carbon::parse($data['vigente_desde'])->subDay()->toDateString()]);

        $parametro = ParametroJornada::create($data);

        return response()->json(['success' => true, 'data' => $parametro, 'message' => 'Par+�metro de jornada creado.'], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $parametro = ParametroJornada::findOrFail($id);

        $data = $request->validate([
            'id_empresa'              => 'sometimes|integer|exists:ent_empresas,id',
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
        return response()->json(['success' => true, 'data' => $parametro->fresh(), 'message' => 'Par+�metro actualizado.']);
    }

    public function destroy(int $id): JsonResponse
    {
        $parametro = ParametroJornada::findOrFail($id);
        $parametro->update(['activo' => false]);
        return response()->json(['success' => true, 'message' => 'Par+�metro desactivado.']);
    }
}
