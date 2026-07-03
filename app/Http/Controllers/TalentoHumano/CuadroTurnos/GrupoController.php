<?php

namespace App\Http\Controllers\TalentoHumano\CuadroTurnos;

use App\Http\Controllers\Controller;
use App\Services\TalentoHumano\CuadroTurnos\CuadroTurnoService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * Controlador de Grupos de Cuadro de Turnos.
 * TODO: Implementar lógica completa.
 */
class GrupoController extends Controller
{
    public function __construct(
        private CuadroTurnoService $service
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $query = \App\Models\TalentoHumano\CuadroTurnos\CtGrupo::with(['empresa', 'sede']);

            if ($request->filled('id_empresa')) {
                $query->where('id_empresa', (int) $request->id_empresa);
            }
            if ($request->filled('id_sede')) {
                $query->where('id_sede', (int) $request->id_sede);
            }

            $grupos = $query->where('estado', true)->orderBy('nombre')->get();

            return response()->json(['success' => true, 'data' => $grupos]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'codigo'     => 'required|string|max:50|unique:humtal_ct_grupos,codigo',
            'nombre'     => 'required|string|max:150',
            'id_empresa' => 'required|integer|exists:ent_empresas,id',
            'id_sede'    => 'nullable|integer|exists:config_ubi_sede,id',
        ]);

        try {
            $grupo = $this->service->crearGrupo($request->all());
            return response()->json(['success' => true, 'data' => $grupo], 201);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function show(int $id): JsonResponse
    {
        try {
            $grupo = \App\Models\TalentoHumano\CuadroTurnos\CtGrupo::with([
                'empresa', 'sede', 'encargadoActual.user',
                'empleados' => fn($q) => $q->activos()->with('empleado'),
            ])->findOrFail($id);

            return response()->json(['success' => true, 'data' => $grupo]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Grupo no encontrado.'], 404);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $grupo = $this->service->actualizarGrupo($id, $request->all());
            return response()->json(['success' => true, 'data' => $grupo]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            $grupo = \App\Models\TalentoHumano\CuadroTurnos\CtGrupo::findOrFail($id);
            $grupo->update(['estado' => false]);
            return response()->json(['success' => true, 'message' => 'Grupo desactivado.']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function asignarEncargado(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'id_user'      => 'required|integer|exists:users,id',
            'fecha_inicio' => 'required|date',
            'motivo'       => 'nullable|string|max:255',
        ]);

        try {
            $encargado = $this->service->asignarEncargado($id, $request->id_user, $request->fecha_inicio, $request->motivo, auth()->id());
            return response()->json(['success' => true, 'data' => $encargado->load('user')], 201);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function historialEncargados(int $id): JsonResponse
    {
        try {
            $grupo = \App\Models\TalentoHumano\CuadroTurnos\CtGrupo::findOrFail($id);
            $historial = $grupo->encargados()->with(['user', 'registradoPor'])->orderByDesc('fecha_inicio')->get();
            return response()->json(['success' => true, 'data' => $historial]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function listarEmpleados(Request $request, int $id): JsonResponse
    {
        try {
            $grupo = \App\Models\TalentoHumano\CuadroTurnos\CtGrupo::findOrFail($id);
            $query = $grupo->empleados()->with('empleado');
            if (!$request->boolean('incluir_historico', false)) {
                $query->activos();
            }
            return response()->json(['success' => true, 'data' => $query->get()]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function agregarEmpleado(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'id_empleado'   => 'required|integer|exists:config_person_tercero,id',
            'fecha_ingreso' => 'required|date',
        ]);

        try {
            $registro = $this->service->agregarEmpleado($id, $request->id_empleado, $request->fecha_ingreso);
            return response()->json(['success' => true, 'data' => $registro->load('empleado')], 201);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function retirarEmpleado(Request $request, int $id, int $idEmpleado): JsonResponse
    {
        $request->validate(['fecha_salida' => 'required|date']);

        try {
            $registro = $this->service->retirarEmpleado($id, $idEmpleado, $request->fecha_salida);
            return response()->json(['success' => true, 'data' => $registro]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }
}
