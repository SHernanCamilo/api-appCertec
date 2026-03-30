<?php

namespace App\Http\Controllers\Workflow;

use App\Http\Controllers\Controller;
use App\Models\Workflow\WfModulo;
use App\Models\Workflow\WfDefinicion;
use App\Models\Workflow\WfPaso;
use App\Models\Workflow\WfRegla;
use App\Models\Workflow\WfAprobador;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Controlador de Administración de Flujos.
 *
 * Permite configurar módulos, flujos, pasos, reglas y aprobadores.
 */
class WorkflowController extends Controller
{
    // ========================================================================
    // MÓDULOS
    // ========================================================================

    /**
     * Listar módulos del sistema.
     *
     * GET /api/workflow/modulos
     */
    public function listarModulos(): JsonResponse
    {
        $modulos = WfModulo::activos()->get();

        return response()->json([
            'success' => true,
            'data' => $modulos,
        ]);
    }

    // ========================================================================
    // FLUJOS
    // ========================================================================

    /**
     * Listar flujos configurados.
     *
     * GET /api/workflow/flujos
     */
    public function listarFlujos(Request $request): JsonResponse
    {
        $query = WfDefinicion::with(['modulo', 'empresa', 'pasos', 'reglas']);

        if ($request->has('id_modulo')) {
            $query->where('id_modulo', $request->id_modulo);
        }

        if ($request->has('id_empresa')) {
            $query->where('id_empresa', $request->id_empresa);
        }

        $flujos = $query->activos()->get();

        return response()->json([
            'success' => true,
            'data' => $flujos,
        ]);
    }

    /**
     * Ver detalle de un flujo.
     *
     * GET /api/workflow/flujos/{id}
     */
    public function verFlujo(int $id): JsonResponse
    {
        try {
            $flujo = WfDefinicion::with([
                'modulo',
                'empresa',
                'pasos.aprobadores.user',
                'pasos.aprobadores.unidadFuncional',
                'reglas',
            ])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $flujo,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Flujo no encontrado',
            ], 404);
        }
    }

    /**
     * Crear nuevo flujo.
     *
     * POST /api/workflow/flujos
     */
    public function crearFlujo(Request $request): JsonResponse
    {
        $request->validate([
            'codigo' => 'required|string|max:50|unique:wf_definiciones,codigo',
            'nombre' => 'required|string|max:150',
            'descripcion' => 'nullable|string',
            'id_modulo' => 'required|integer|exists:wf_modulos,id',
            'id_empresa' => 'nullable|integer|exists:ent_empresas,id',
        ]);

        try {
            $flujo = WfDefinicion::create($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Flujo creado exitosamente',
                'data' => $flujo,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear flujo: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Actualizar flujo.
     *
     * PUT /api/workflow/flujos/{id}
     */
    public function actualizarFlujo(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'codigo' => 'sometimes|string|max:50|unique:wf_definiciones,codigo,' . $id,
            'nombre' => 'sometimes|string|max:150',
            'descripcion' => 'nullable|string',
            'estado' => 'sometimes|boolean',
        ]);

        try {
            $flujo = WfDefinicion::findOrFail($id);
            $flujo->update($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Flujo actualizado exitosamente',
                'data' => $flujo,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar flujo: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Eliminar flujo (soft delete).
     *
     * DELETE /api/workflow/flujos/{id}
     */
    public function eliminarFlujo(int $id): JsonResponse
    {
        try {
            $flujo = WfDefinicion::findOrFail($id);
            $flujo->update(['estado' => false]);

            return response()->json([
                'success' => true,
                'message' => 'Flujo desactivado exitosamente',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar flujo: ' . $e->getMessage(),
            ], 500);
        }
    }

    // ========================================================================
    // PASOS
    // ========================================================================

    /**
     * Listar pasos de un flujo.
     *
     * GET /api/workflow/flujos/{idFlujo}/pasos
     */
    public function listarPasos(int $idFlujo): JsonResponse
    {
        $pasos = WfPaso::where('id_definicion', $idFlujo)
            ->with('aprobadores')
            ->activos()
            ->ordenados()
            ->get();

        return response()->json([
            'success' => true,
            'data' => $pasos,
        ]);
    }

    /**
     * Agregar paso a un flujo.
     *
     * POST /api/workflow/flujos/{idFlujo}/pasos
     */
    public function agregarPaso(Request $request, int $idFlujo): JsonResponse
    {
        $request->validate([
            'orden' => 'required|integer|min:1',
            'nombre_paso' => 'required|string|max:100',
            'rol_aprobador' => 'required|string|max:50',
            'es_opcional' => 'sometimes|boolean',
            'permite_rechazo' => 'sometimes|boolean',
            'requiere_monto' => 'sometimes|boolean',
        ]);

        try {
            $paso = WfPaso::create([
                'id_definicion' => $idFlujo,
                ...$request->all(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Paso agregado exitosamente',
                'data' => $paso,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al agregar paso: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Actualizar paso.
     *
     * PUT /api/workflow/pasos/{id}
     */
    public function actualizarPaso(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'orden' => 'sometimes|integer|min:1',
            'nombre_paso' => 'sometimes|string|max:100',
            'rol_aprobador' => 'sometimes|string|max:50',
            'es_opcional' => 'sometimes|boolean',
            'permite_rechazo' => 'sometimes|boolean',
            'requiere_monto' => 'sometimes|boolean',
            'estado' => 'sometimes|boolean',
        ]);

        try {
            $paso = WfPaso::findOrFail($id);
            $paso->update($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Paso actualizado exitosamente',
                'data' => $paso,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar paso: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Eliminar paso.
     *
     * DELETE /api/workflow/pasos/{id}
     */
    public function eliminarPaso(int $id): JsonResponse
    {
        try {
            $paso = WfPaso::findOrFail($id);
            $paso->update(['estado' => false]);

            return response()->json([
                'success' => true,
                'message' => 'Paso desactivado exitosamente',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar paso: ' . $e->getMessage(),
            ], 500);
        }
    }

    // ========================================================================
    // REGLAS
    // ========================================================================

    /**
     * Listar reglas de un flujo.
     *
     * GET /api/workflow/flujos/{idFlujo}/reglas
     */
    public function listarReglas(int $idFlujo): JsonResponse
    {
        $reglas = WfRegla::where('id_definicion', $idFlujo)
            ->activos()
            ->ordenadas()
            ->get();

        return response()->json([
            'success' => true,
            'data' => $reglas,
        ]);
    }

    /**
     * Agregar regla a un flujo.
     *
     * POST /api/workflow/flujos/{idFlujo}/reglas
     */
    public function agregarRegla(Request $request, int $idFlujo): JsonResponse
    {
        $request->validate([
            'prioridad' => 'required|integer|min:1',
            'condiciones' => 'required|array',
        ]);

        try {
            $regla = WfRegla::create([
                'id_definicion' => $idFlujo,
                ...$request->all(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Regla agregada exitosamente',
                'data' => $regla,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al agregar regla: ' . $e->getMessage(),
            ], 500);
        }
    }

    // ========================================================================
    // APROBADORES
    // ========================================================================

    /**
     * Listar aprobadores de un paso.
     *
     * GET /api/workflow/pasos/{idPaso}/aprobadores
     */
    public function listarAprobadores(int $idPaso): JsonResponse
    {
        $aprobadores = WfAprobador::where('id_paso', $idPaso)
            ->with(['user', 'unidadFuncional', 'sede'])
            ->activos()
            ->get();

        return response()->json([
            'success' => true,
            'data' => $aprobadores,
        ]);
    }

    /**
     * Agregar aprobador a un paso.
     *
     * POST /api/workflow/pasos/{idPaso}/aprobadores
     */
    public function agregarAprobador(Request $request, int $idPaso): JsonResponse
    {
        $request->validate([
            'id_user' => 'nullable|integer|exists:users,id',
            'id_unidad_funcional' => 'nullable|integer|exists:anti_unidades_funcionales,id',
            'prefijo_sucursal' => 'nullable|string|max:10',
            'id_sede' => 'nullable|integer|exists:config_ubi_sede,id',
            'es_suplente' => 'sometimes|boolean',
        ]);

        try {
            $aprobador = WfAprobador::create([
                'id_paso' => $idPaso,
                ...$request->all(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Aprobador agregado exitosamente',
                'data' => $aprobador,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al agregar aprobador: ' . $e->getMessage(),
            ], 500);
        }
    }
}
