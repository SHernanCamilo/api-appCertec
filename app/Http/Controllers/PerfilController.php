<?php

namespace App\Http\Controllers;

use App\Models\Perfil;
use App\Models\Modulo;
use App\Models\Permiso;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class PerfilController extends Controller
{
    /**
     * Listar todos los perfiles
     */
    public function index(Request $request): JsonResponse
    {
        $query = Perfil::with(['modulo', 'roles', 'permisos']);

        // Filtros opcionales
        if ($request->has('id_modulo')) {
            $query->porModulo($request->id_modulo);
        }

        if ($request->has('estado')) {
            $query->where('estado', $request->estado);
        }

        $perfiles = $query->orderBy('nombre')->get();

        return response()->json([
            'success' => true,
            'message' => 'Perfiles obtenidos exitosamente',
            'data' => $perfiles
        ], 200);
    }

    /**
     * Obtener un perfil específico
     */
    public function show($id): JsonResponse
    {
        $perfil = Perfil::with(['modulo', 'roles', 'permisos'])->find($id);

        if (!$perfil) {
            return response()->json([
                'success' => false,
                'message' => 'Perfil no encontrado'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Perfil obtenido exitosamente',
            'data' => $perfil
        ], 200);
    }

    /**
     * Crear un nuevo perfil
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'nombre' => 'required|string|max:50',
            'codigo' => 'nullable|string|max:20|unique:seg_perfiles,codigo',
            'descripcion' => 'nullable|string|max:255',
            'puede_crear' => 'boolean',
            'puede_leer' => 'boolean',
            'puede_editar' => 'boolean',
            'puede_eliminar' => 'boolean',
            'permisos_ids' => 'nullable|array',
            'permisos_ids.*' => 'exists:seg_permisos,id',
            'estado' => 'boolean'
        ]);

        // Generar código si no se proporciona
        $codigo = $request->codigo ?? Str::slug($request->nombre);

        // Determinar id_modulo desde los permisos seleccionados (usar el primer permiso)
        $idModulo = null;
        if ($request->has('permisos_ids') && count($request->permisos_ids) > 0) {
            $primerPermiso = Permiso::find($request->permisos_ids[0]);
            $idModulo = $primerPermiso ? $primerPermiso->id_modulo : null;
        }

        $perfil = Perfil::create([
            'nombre' => $request->nombre,
            'codigo' => $codigo,
            'id_modulo' => $idModulo, // Puede ser null
            'descripcion' => $request->descripcion,
            'puede_crear' => $request->puede_crear ?? false,
            'puede_leer' => $request->puede_leer ?? true,
            'puede_editar' => $request->puede_editar ?? false,
            'puede_eliminar' => $request->puede_eliminar ?? false,
            'estado' => $request->estado ?? true,
        ]);

        // Sincronizar permisos extras usando la tabla relacional
        if ($request->has('permisos_ids')) {
            $perfil->permisos()->sync($request->permisos_ids);
        }

        $perfil->load(['modulo', 'roles', 'permisos']);

        return response()->json([
            'success' => true,
            'message' => 'Perfil creado exitosamente',
            'data' => $perfil
        ], 201);
    }

    /**
     * Actualizar un perfil
     */
    public function update(Request $request, $id): JsonResponse
    {
        $perfil = Perfil::find($id);

        if (!$perfil) {
            return response()->json([
                'success' => false,
                'message' => 'Perfil no encontrado'
            ], 404);
        }

        $request->validate([
            'nombre' => 'sometimes|required|string|max:50',
            'codigo' => 'sometimes|required|string|max:20|unique:seg_perfiles,codigo,' . $id,
            'descripcion' => 'nullable|string|max:255',
            'puede_crear' => 'boolean',
            'puede_leer' => 'boolean',
            'puede_editar' => 'boolean',
            'puede_eliminar' => 'boolean',
            'permisos_ids' => 'nullable|array',
            'permisos_ids.*' => 'exists:seg_permisos,id',
            'estado' => 'boolean'
        ]);

        $perfil->update($request->only([
            'nombre',
            'codigo',
            'descripcion',
            'puede_crear',
            'puede_leer',
            'puede_editar',
            'puede_eliminar',
            'estado'
        ]));

        // Sincronizar permisos extras usando la tabla relacional
        if ($request->has('permisos_ids')) {
            $perfil->permisos()->sync($request->permisos_ids);
        }

        $perfil->load(['modulo', 'roles', 'permisos']);

        return response()->json([
            'success' => true,
            'message' => 'Perfil actualizado exitosamente',
            'data' => $perfil
        ], 200);
    }

    /**
     * Eliminar un perfil
     */
    public function destroy($id): JsonResponse
    {
        $perfil = Perfil::find($id);

        if (!$perfil) {
            return response()->json([
                'success' => false,
                'message' => 'Perfil no encontrado'
            ], 404);
        }

        // Verificar si tiene roles asignados
        if ($perfil->roles()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'No se puede eliminar el perfil porque tiene roles asignados'
            ], 400);
        }

        $perfil->delete();

        return response()->json([
            'success' => true,
            'message' => 'Perfil eliminado exitosamente'
        ], 200);
    }

    /**
     * Obtener perfiles agrupados por módulo
     */
    public function porModulo(): JsonResponse
    {
        $modulos = Modulo::with(['perfiles' => function($query) {
            $query->activos()->orderBy('nombre');
        }])
        ->activos()
        ->orderBy('nombre')
        ->get();

        $resultado = $modulos->map(function($modulo) {
            return [
                'modulo' => $modulo,
                'perfiles' => $modulo->perfiles,
                'total_perfiles' => $modulo->perfiles->count(),
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'Perfiles por módulo obtenidos exitosamente',
            'data' => $resultado
        ], 200);
    }

    /**
     * Obtener permisos disponibles para un módulo
     */
    public function permisosDisponibles($idModulo): JsonResponse
    {
        $modulo = Modulo::find($idModulo);

        if (!$modulo) {
            return response()->json([
                'success' => false,
                'message' => 'Módulo no encontrado'
            ], 404);
        }

        $permisos = Permiso::where('id_modulo', $idModulo)
            ->activos()
            ->orderBy('orden')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Permisos disponibles obtenidos exitosamente',
            'data' => [
                'modulo' => $modulo,
                'permisos' => $permisos
            ]
        ], 200);
    }

    /**
     * Obtener perfiles por módulo específico
     */
    public function perfilesPorModulo($idModulo): JsonResponse
    {
        $modulo = Modulo::find($idModulo);

        if (!$modulo) {
            return response()->json([
                'success' => false,
                'message' => 'Módulo no encontrado'
            ], 404);
        }

        $perfiles = Perfil::with('roles')
            ->where('id_modulo', $idModulo)
            ->activos()
            ->orderBy('nombre')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Perfiles obtenidos exitosamente',
            'data' => [
                'modulo' => $modulo,
                'perfiles' => $perfiles
            ]
        ], 200);
    }
}
