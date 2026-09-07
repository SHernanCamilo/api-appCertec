<?php

namespace App\Http\Controllers\Workflow;

use App\Http\Controllers\Controller;
use App\Models\Workflow\WfModulo;
use App\Models\Workflow\WfDefinicion;
use App\Models\Workflow\WfPaso;
use App\Models\Workflow\WfRegla;
use App\Models\Workflow\WfAprobador;
use App\Models\Workflow\WfGrupo;
use App\Models\Workflow\WfInstancia;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * Controlador de Administración de Flujos.
 *
 * Gestiona módulos, definiciones (flujos), pasos, reglas, aprobadores,
 * grupos e instancias.
 */
class WorkflowController extends Controller
{
    // ========================================================================
    // MÓDULOS
    // ========================================================================

    public function listarModulos(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => WfModulo::activos()->get()]);
    }

    // ========================================================================
    // DEFINICIONES (flujos)
    // ========================================================================

    public function listarDefiniciones(Request $request): JsonResponse
    {
        $query = WfDefinicion::with(['modulo', 'empresa']);

        if ($request->filled('modulo')) {
            $query->whereHas('modulo', fn($q) => $q->where('codigo', $request->modulo));
        }
        if ($request->filled('id_empresa')) {
            $query->porEmpresa((int) $request->id_empresa);
        }
        if ($request->has('estado') && $request->estado !== null) {
            $query->where('estado', (bool) $request->estado);
        }

        $perPage = (int) ($request->per_page ?? 50);
        $resultado = $query->orderBy('nombre')->paginate($perPage);

        return response()->json([
            'success'      => true,
            'data'         => $resultado->items(),
            'total'        => $resultado->total(),
            'current_page' => $resultado->currentPage(),
            'per_page'     => $resultado->perPage(),
            'last_page'    => $resultado->lastPage(),
        ]);
    }

    /** Alias legacy /flujos */
    public function listarFlujos(Request $request): JsonResponse
    {
        return $this->listarDefiniciones($request);
    }

    public function verDefinicion(int $id): JsonResponse
    {
        try {
            $flujo = WfDefinicion::with([
                'modulo', 'empresa',
                'pasos.aprobadores.user', 'pasos.aprobadores.unidadFuncional',
                'reglas',
            ])->findOrFail($id);

            return response()->json(['success' => true, 'data' => $flujo]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Flujo no encontrado'], 404);
        }
    }

    public function verFlujo(int $id): JsonResponse
    {
        return $this->verDefinicion($id);
    }

    public function crearDefinicion(Request $request): JsonResponse
    {
        $request->validate([
            'codigo'      => 'required|string|max:50|unique:wf_definiciones,codigo',
            'nombre'      => 'required|string|max:150',
            'descripcion' => 'nullable|string',
            'id_modulo'   => 'nullable|integer|exists:wf_modulos,id',
            'modulo'      => 'nullable|string',
            'id_empresa'  => 'nullable|integer|exists:ent_empresas,id',
            'estado'      => 'sometimes|boolean',
        ]);

        try {
            $data = $request->only('codigo', 'nombre', 'descripcion', 'id_empresa', 'estado');

            // Resolver id_modulo desde el código del módulo si vino como string
            if ($request->filled('id_modulo')) {
                $data['id_modulo'] = $request->id_modulo;
            } elseif ($request->filled('modulo')) {
                $modulo = WfModulo::where('codigo', $request->modulo)->first();
                if (!$modulo) {
                    return response()->json(['success' => false, 'message' => "Módulo '{$request->modulo}' no existe"], 422);
                }
                $data['id_modulo'] = $modulo->id;
            }

            $flujo = WfDefinicion::create($data);

            return response()->json([
                'success' => true,
                'message' => 'Flujo creado exitosamente',
                'data'    => $flujo,
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al crear flujo: ' . $e->getMessage()], 500);
        }
    }

    public function crearFlujo(Request $request): JsonResponse
    {
        return $this->crearDefinicion($request);
    }

    public function actualizarDefinicion(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'codigo'      => 'sometimes|string|max:50|unique:wf_definiciones,codigo,' . $id,
            'nombre'      => 'sometimes|string|max:150',
            'descripcion' => 'nullable|string',
            'estado'      => 'sometimes|boolean',
        ]);

        try {
            $flujo = WfDefinicion::findOrFail($id);
            $flujo->update($request->only('codigo', 'nombre', 'descripcion', 'estado'));

            return response()->json(['success' => true, 'message' => 'Flujo actualizado exitosamente', 'data' => $flujo]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al actualizar flujo: ' . $e->getMessage()], 500);
        }
    }

    public function actualizarFlujo(Request $request, int $id): JsonResponse
    {
        return $this->actualizarDefinicion($request, $id);
    }

    public function eliminarDefinicion(int $id): JsonResponse
    {
        try {
            WfDefinicion::findOrFail($id)->update(['estado' => false]);
            return response()->json(['success' => true, 'message' => 'Flujo desactivado exitosamente']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al eliminar flujo: ' . $e->getMessage()], 500);
        }
    }

    public function eliminarFlujo(int $id): JsonResponse
    {
        return $this->eliminarDefinicion($id);
    }

    public function toggleEstadoDefinicion(int $id): JsonResponse
    {
        try {
            $flujo = WfDefinicion::findOrFail($id);
            $flujo->update(['estado' => !$flujo->estado]);
            return response()->json(['success' => true, 'message' => 'Estado actualizado', 'data' => $flujo]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // ========================================================================
    // PASOS
    // ========================================================================

    public function listarPasos(int $idDefinicion): JsonResponse
    {
        $pasos = WfPaso::where('id_definicion', $idDefinicion)
            ->with('aprobadores')->activos()->ordenados()->get();

        return response()->json(['success' => true, 'data' => $pasos]);
    }

    public function crearPaso(Request $request): JsonResponse
    {
        $request->validate([
            'id_definicion'   => 'required|integer|exists:wf_definiciones,id',
            'orden'           => 'required|integer|min:1',
            'nombre_paso'     => 'required|string|max:100',
            'rol_aprobador'   => 'required|string|max:50',
            'es_opcional'     => 'sometimes|boolean',
            'permite_rechazo' => 'sometimes|boolean',
            'requiere_monto'  => 'sometimes|boolean',
        ]);

        try {
            $paso = WfPaso::create($request->all());
            return response()->json(['success' => true, 'message' => 'Paso creado exitosamente', 'data' => $paso], 201);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al crear paso: ' . $e->getMessage()], 500);
        }
    }

    public function agregarPaso(Request $request, int $idFlujo): JsonResponse
    {
        $request->merge(['id_definicion' => $idFlujo]);
        return $this->crearPaso($request);
    }

    public function actualizarPaso(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'orden'           => 'sometimes|integer|min:1',
            'nombre_paso'     => 'sometimes|string|max:100',
            'rol_aprobador'   => 'sometimes|string|max:50',
            'es_opcional'     => 'sometimes|boolean',
            'permite_rechazo' => 'sometimes|boolean',
            'requiere_monto'  => 'sometimes|boolean',
            'estado'          => 'sometimes|boolean',
        ]);

        try {
            $paso = WfPaso::findOrFail($id);
            $paso->update($request->all());
            return response()->json(['success' => true, 'message' => 'Paso actualizado exitosamente', 'data' => $paso]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al actualizar paso: ' . $e->getMessage()], 500);
        }
    }

    public function eliminarPaso(int $id): JsonResponse
    {
        try {
            WfPaso::findOrFail($id)->update(['estado' => false]);
            return response()->json(['success' => true, 'message' => 'Paso desactivado exitosamente']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al eliminar paso: ' . $e->getMessage()], 500);
        }
    }

    // ========================================================================
    // REGLAS
    // ========================================================================

    public function listarReglas(int $idDefinicion): JsonResponse
    {
        $reglas = WfRegla::where('id_definicion', $idDefinicion)->activos()->ordenadas()->get();
        return response()->json(['success' => true, 'data' => $reglas]);
    }

    public function crearRegla(Request $request): JsonResponse
    {
        $request->validate([
            'id_definicion' => 'required|integer|exists:wf_definiciones,id',
            'prioridad'     => 'required|integer|min:1',
            'condiciones'   => 'required|array',
            'descripcion'   => 'nullable|string',
            'estado'        => 'sometimes|boolean',
        ]);

        try {
            $regla = WfRegla::create($request->all());
            return response()->json(['success' => true, 'message' => 'Regla creada exitosamente', 'data' => $regla], 201);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al crear regla: ' . $e->getMessage()], 500);
        }
    }

    public function agregarRegla(Request $request, int $idFlujo): JsonResponse
    {
        $request->merge(['id_definicion' => $idFlujo]);
        return $this->crearRegla($request);
    }

    public function actualizarRegla(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'prioridad'   => 'sometimes|integer|min:1',
            'condiciones' => 'sometimes|array',
            'descripcion' => 'nullable|string',
            'estado'      => 'sometimes|boolean',
        ]);

        try {
            $regla = WfRegla::findOrFail($id);
            $regla->update($request->all());
            return response()->json(['success' => true, 'message' => 'Regla actualizada exitosamente', 'data' => $regla]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al actualizar regla: ' . $e->getMessage()], 500);
        }
    }

    public function eliminarRegla(int $id): JsonResponse
    {
        try {
            WfRegla::findOrFail($id)->update(['estado' => false]);
            return response()->json(['success' => true, 'message' => 'Regla eliminada exitosamente']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al eliminar regla: ' . $e->getMessage()], 500);
        }
    }

    // ========================================================================
    // APROBADORES
    // ========================================================================

    public function listarAprobadores(int $idPaso): JsonResponse
    {
        $aprobadores = WfAprobador::where('id_paso', $idPaso)
            ->with(['user', 'unidadFuncional', 'sede', 'grupo'])->activos()->get();

        return response()->json(['success' => true, 'data' => $aprobadores]);
    }

    public function crearAprobador(Request $request): JsonResponse
    {
        $request->validate([
            'id_paso'             => 'required|integer|exists:wf_pasos,id',
            'tipo_aprobador'      => 'nullable|in:USER,RESPONSABLE_UF,RESPONSABLE_GRUPO,GRUPO,PERMISO,ROL_SPATIE',
            'estrategia'          => 'nullable|string',
            'id_user'             => 'nullable|integer|exists:users,id',
            'id_unidad_funcional' => 'nullable|integer',
            'prefijo_sucursal'    => 'nullable|string|max:10',
            'id_grupo'            => 'nullable|integer|exists:wf_grupos,id',
            'permiso_codigo'      => 'nullable|string|max:50',
            'rol_spatie'          => 'nullable|string|max:100',
            'alcance'             => 'nullable|string|max:20',
            'es_suplente'         => 'sometimes|boolean',
            'condiciones'         => 'nullable|array',
        ]);

        try {
            $data = $request->except('estrategia');

            // Mapear "estrategia" legacy → tipo_aprobador
            if (!$request->filled('tipo_aprobador') && $request->filled('estrategia')) {
                $map = [
                    'fijo'              => 'USER',
                    'unidad_funcional'  => 'RESPONSABLE_UF',
                    'prefijo_sucursal'  => 'USER',
                ];
                $data['tipo_aprobador'] = $map[$request->estrategia] ?? 'USER';
            }

            $aprobador = WfAprobador::create($data);
            $aprobador->load(['user', 'grupo']);

            return response()->json(['success' => true, 'message' => 'Aprobador agregado exitosamente', 'data' => $aprobador], 201);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al agregar aprobador: ' . $e->getMessage()], 500);
        }
    }

    public function agregarAprobador(Request $request, int $idPaso): JsonResponse
    {
        $request->merge(['id_paso' => $idPaso]);
        return $this->crearAprobador($request);
    }

    public function actualizarAprobador(Request $request, int $id): JsonResponse
    {
        try {
            $aprobador = WfAprobador::findOrFail($id);
            $aprobador->update($request->all());
            return response()->json(['success' => true, 'message' => 'Aprobador actualizado', 'data' => $aprobador->fresh(['user', 'grupo'])]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al actualizar aprobador: ' . $e->getMessage()], 500);
        }
    }

    public function eliminarAprobador(int $id): JsonResponse
    {
        try {
            WfAprobador::findOrFail($id)->update(['estado' => false]);
            return response()->json(['success' => true, 'message' => 'Aprobador eliminado exitosamente']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al eliminar aprobador: ' . $e->getMessage()], 500);
        }
    }

    // ========================================================================
    // GRUPOS (WfGrupo — Asistencial, Administrativo, Directivo)
    // ========================================================================

    public function listarGrupos(Request $request): JsonResponse
    {
        $query = WfGrupo::query();

        if ($request->filled('id_empresa')) {
            $query->where(fn($q) => $q->where('id_empresa', $request->id_empresa)->orWhereNull('id_empresa'));
        }
        if ($request->has('estado') && $request->estado !== null) {
            $query->where('estado', (bool) $request->estado);
        } else {
            $query->activos();
        }

        return response()->json(['success' => true, 'data' => $query->orderBy('nombre')->get()]);
    }

    public function verGrupo(int $id): JsonResponse
    {
        try {
            $grupo = WfGrupo::with(['cargos', 'unidadesFuncionales'])->findOrFail($id);
            return response()->json(['success' => true, 'data' => $grupo]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Grupo no encontrado'], 404);
        }
    }

    public function crearGrupo(Request $request): JsonResponse
    {
        $request->validate([
            'nombre'      => 'required|string|max:150',
            'descripcion' => 'nullable|string',
            'id_empresa'  => 'nullable|integer|exists:ent_empresas,id',
            'estado'      => 'sometimes|boolean',
        ]);

        try {
            $grupo = WfGrupo::create([
                'nombre'      => $request->nombre,
                'descripcion' => $request->descripcion,
                'id_empresa'  => $request->id_empresa,
                'estado'      => $request->estado ?? true,
            ]);

            return response()->json(['success' => true, 'message' => 'Grupo creado exitosamente', 'data' => $grupo], 201);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al crear grupo: ' . $e->getMessage()], 500);
        }
    }

    public function actualizarGrupo(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'nombre'      => 'sometimes|string|max:150',
            'descripcion' => 'nullable|string',
            'id_empresa'  => 'nullable|integer|exists:ent_empresas,id',
            'estado'      => 'sometimes|boolean',
        ]);

        try {
            $grupo = WfGrupo::findOrFail($id);
            $grupo->update($request->only('nombre', 'descripcion', 'id_empresa', 'estado'));

            return response()->json(['success' => true, 'message' => 'Grupo actualizado exitosamente', 'data' => $grupo]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al actualizar grupo: ' . $e->getMessage()], 500);
        }
    }

    public function eliminarGrupo(int $id): JsonResponse
    {
        try {
            WfGrupo::findOrFail($id)->update(['estado' => false]);
            return response()->json(['success' => true, 'message' => 'Grupo desactivado exitosamente']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al eliminar grupo: ' . $e->getMessage()], 500);
        }
    }

    public function listarCargosGrupo(int $id): JsonResponse
    {
        try {
            $grupo = WfGrupo::with('cargos')->findOrFail($id);
            // Envolver cada cargo para que el frontend reciba { cargo: {...} }
            $data = $grupo->cargos->map(fn($c) => [
                'id_cargo' => $c->id_cargo,
                'cargo'    => $c,
            ]);
            return response()->json(['success' => true, 'data' => $data]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // ========================================================================
    // INSTANCIAS (monitoreo)
    // ========================================================================

    public function listarInstancias(Request $request): JsonResponse
    {
        $query = WfInstancia::with(['definicion', 'pasoActual', 'solicitante']);

        if ($request->filled('modulo')) {
            $query->whereHas('modulo', fn($q) => $q->where('codigo', $request->modulo));
        }
        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }
        if ($request->filled('id_definicion')) {
            $query->where('id_definicion', $request->id_definicion);
        }

        $perPage = (int) ($request->per_page ?? 20);
        $resultado = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success'      => true,
            'data'         => $resultado->items(),
            'total'        => $resultado->total(),
            'current_page' => $resultado->currentPage(),
            'per_page'     => $resultado->perPage(),
            'last_page'    => $resultado->lastPage(),
        ]);
    }

    public function verInstancia(int $id): JsonResponse
    {
        try {
            $instancia = WfInstancia::with([
                'definicion', 'pasoActual', 'solicitante',
                'aprobaciones.user', 'aprobaciones.paso',
            ])->findOrFail($id);

            return response()->json(['success' => true, 'data' => $instancia]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Instancia no encontrada'], 404);
        }
    }
}
