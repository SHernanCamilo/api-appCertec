<?php

namespace App\Http\Controllers\Turnos;

use App\Http\Controllers\Controller;
use App\Models\Turnos\CtGrupo;
use App\Services\Turnos\CuadroTurnoService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class GrupoController extends Controller
{
    public function __construct(
        private CuadroTurnoService $service
    ) {}

    /**
     * GET /api/turnos/grupos
     * Listar grupos con filtros opcionales.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = CtGrupo::with(['empresa', 'sede', 'encargadoActual.user']);

            if ($request->filled('id_empresa')) {
                $query->porEmpresa((int) $request->id_empresa);
            }

            if ($request->filled('id_sede')) {
                $query->porSede((int) $request->id_sede);
            }

            if ($request->filled('estado')) {
                $query->where('estado', filter_var($request->estado, FILTER_VALIDATE_BOOLEAN));
            } else {
                $query->activos();
            }

            $grupos = $query->orderBy('nombre')->get();

            return response()->json([
                'success' => true,
                'data'    => $grupos,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener grupos: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/turnos/grupos
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'codigo'      => 'required|string|max:50|unique:humtal_ct_grupos,codigo',
            'nombre'      => 'required|string|max:150',
            'descripcion' => 'nullable|string',
            'id_empresa'  => 'required|integer|exists:ent_empresas,id',
            'id_sede'     => 'nullable|integer|exists:config_ubi_sede,id',
            'estado'      => 'boolean',
        ]);

        try {
            $grupo = $this->service->crearGrupo($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Grupo creado exitosamente.',
                'data'    => $grupo->load(['empresa', 'sede']),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear grupo: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/turnos/grupos/{id}
     * Detalle del grupo con encargado actual y empleados activos.
     */
    public function show(int $id): JsonResponse
    {
        try {
            $grupo = CtGrupo::with([
                'empresa',
                'sede',
                'encargadoActual.user',
                'empleados' => function ($q) {
                    $q->activos()->with('empleado');
                },
            ])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data'    => $grupo,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Grupo no encontrado.',
            ], 404);
        }
    }

    /**
     * PUT /api/turnos/grupos/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'codigo'      => "string|max:50|unique:humtal_ct_grupos,codigo,{$id}",
            'nombre'      => 'string|max:150',
            'descripcion' => 'nullable|string',
            'id_empresa'  => 'integer|exists:ent_empresas,id',
            'id_sede'     => 'nullable|integer|exists:config_ubi_sede,id',
            'estado'      => 'boolean',
        ]);

        try {
            $grupo = $this->service->actualizarGrupo($id, $request->all());

            return response()->json([
                'success' => true,
                'message' => 'Grupo actualizado.',
                'data'    => $grupo->load(['empresa', 'sede']),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar grupo: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * DELETE /api/turnos/grupos/{id}
     * Soft delete: desactiva el grupo.
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $grupo = CtGrupo::findOrFail($id);
            $grupo->update(['estado' => false]);

            return response()->json([
                'success' => true,
                'message' => 'Grupo desactivado.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al desactivar grupo: ' . $e->getMessage(),
            ], 500);
        }
    }

    // =========================================================================
    // ENCARGADOS
    // =========================================================================

    /**
     * POST /api/turnos/grupos/{id}/encargado
     * Asignar nuevo encargado al grupo.
     */
    public function asignarEncargado(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'id_user'      => 'required|integer|exists:users,id',
            'fecha_inicio' => 'required|date',
            'motivo'       => 'nullable|string|max:255',
        ]);

        try {
            $encargado = $this->service->asignarEncargado(
                $id,
                $request->id_user,
                $request->fecha_inicio,
                $request->motivo,
                auth()->id()
            );

            return response()->json([
                'success' => true,
                'message' => 'Encargado asignado exitosamente.',
                'data'    => $encargado->load('user'),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al asignar encargado: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/turnos/grupos/{id}/encargado/historial
     */
    public function historialEncargados(int $id): JsonResponse
    {
        try {
            $grupo = CtGrupo::findOrFail($id);

            $historial = $grupo->encargados()
                ->with(['user', 'registradoPor'])
                ->orderByDesc('fecha_inicio')
                ->get();

            return response()->json([
                'success' => true,
                'data'    => $historial,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener historial: ' . $e->getMessage(),
            ], 500);
        }
    }

    // =========================================================================
    // EMPLEADOS DEL GRUPO
    // =========================================================================

    /**
     * GET /api/turnos/grupos/{id}/empleados
     * Listar empleados activos (y opcionalmente históricos).
     */
    public function listarEmpleados(Request $request, int $id): JsonResponse
    {
        try {
            $grupo = CtGrupo::findOrFail($id);

            $query = $grupo->empleados()->with('empleado');

            if (!$request->boolean('incluir_historico', false)) {
                $query->activos();
            }

            $empleados = $query->orderBy('fecha_ingreso')->get();

            return response()->json([
                'success' => true,
                'data'    => $empleados,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al listar empleados: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/turnos/grupos/{id}/empleados
     * Agregar empleado al grupo.
     */
    public function agregarEmpleado(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'id_empleado'   => 'required|integer|exists:config_person_tercero,id',
            'fecha_ingreso' => 'required|date',
        ]);

        try {
            $registro = $this->service->agregarEmpleado(
                $id,
                $request->id_empleado,
                $request->fecha_ingreso
            );

            return response()->json([
                'success' => true,
                'message' => 'Empleado agregado al grupo.',
                'data'    => $registro->load('empleado'),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al agregar empleado: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * DELETE /api/turnos/grupos/{id}/empleados/{idEmpleado}
     * Retirar empleado del grupo.
     */
    public function retirarEmpleado(Request $request, int $id, int $idEmpleado): JsonResponse
    {
        $request->validate([
            'fecha_salida' => 'required|date',
        ]);

        try {
            $registro = $this->service->retirarEmpleado(
                $id,
                $idEmpleado,
                $request->fecha_salida
            );

            return response()->json([
                'success' => true,
                'message' => 'Empleado retirado del grupo.',
                'data'    => $registro,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al retirar empleado: ' . $e->getMessage(),
            ], 422);
        }
    }
}
