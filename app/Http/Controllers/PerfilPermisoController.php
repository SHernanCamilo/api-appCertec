<?php

namespace App\Http\Controllers;

use App\Models\Perfil;
use App\Models\Permiso;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class PerfilPermisoController extends Controller
{
    /**
     * Obtener todos los permisos de un perfil
     */
    public function index($idPerfil): JsonResponse
    {
        $perfil = Perfil::with(['permisos', 'modulo'])->find($idPerfil);

        if (!$perfil) {
            return response()->json([
                'success' => false,
                'message' => 'Perfil no encontrado'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Permisos del perfil obtenidos exitosamente',
            'data' => [
                'perfil' => $perfil,
                'permisos' => $perfil->permisos,
                'total_permisos' => $perfil->permisos->count()
            ]
        ], 200);
    }

    /**
     * Agregar un permiso a un perfil
     */
    public function store(Request $request, $idPerfil): JsonResponse
    {
        $perfil = Perfil::find($idPerfil);

        if (!$perfil) {
            return response()->json([
                'success' => false,
                'message' => 'Perfil no encontrado'
            ], 404);
        }

        $request->validate([
            'id_permiso' => 'required|exists:seg_permisos,id'
        ]);

        $permiso = Permiso::find($request->id_permiso);

        // Verificar que el permiso pertenece al mismo módulo del perfil
        if ($permiso->id_modulo != $perfil->id_modulo) {
            return response()->json([
                'success' => false,
                'message' => 'El permiso no pertenece al módulo del perfil'
            ], 400);
        }

        // Verificar si ya existe la relación
        if ($perfil->permisos()->where('id_permiso', $request->id_permiso)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'El permiso ya está asignado a este perfil'
            ], 400);
        }

        // Agregar el permiso
        $perfil->permisos()->attach($request->id_permiso);

        $perfil->load('permisos');

        return response()->json([
            'success' => true,
            'message' => 'Permiso agregado exitosamente',
            'data' => [
                'perfil' => $perfil,
                'permiso_agregado' => $permiso
            ]
        ], 201);
    }

    /**
     * Sincronizar múltiples permisos de un perfil
     */
    public function sync(Request $request, $idPerfil): JsonResponse
    {
        $perfil = Perfil::find($idPerfil);

        if (!$perfil) {
            return response()->json([
                'success' => false,
                'message' => 'Perfil no encontrado'
            ], 404);
        }

        $request->validate([
            'permisos_ids' => 'required|array',
            'permisos_ids.*' => 'exists:seg_permisos,id'
        ]);

        // Verificar que todos los permisos pertenecen al módulo del perfil
        $permisos = Permiso::whereIn('id', $request->permisos_ids)->get();
        
        foreach ($permisos as $permiso) {
            if ($permiso->id_modulo != $perfil->id_modulo) {
                return response()->json([
                    'success' => false,
                    'message' => "El permiso '{$permiso->nombre}' no pertenece al módulo del perfil"
                ], 400);
            }
        }

        // Sincronizar permisos (elimina los que no están y agrega los nuevos)
        $perfil->permisos()->sync($request->permisos_ids);

        $perfil->load('permisos');

        return response()->json([
            'success' => true,
            'message' => 'Permisos sincronizados exitosamente',
            'data' => [
                'perfil' => $perfil,
                'total_permisos' => $perfil->permisos->count()
            ]
        ], 200);
    }

    /**
     * Eliminar un permiso de un perfil
     */
    public function destroy($idPerfil, $idPermiso): JsonResponse
    {
        $perfil = Perfil::find($idPerfil);

        if (!$perfil) {
            return response()->json([
                'success' => false,
                'message' => 'Perfil no encontrado'
            ], 404);
        }

        $permiso = Permiso::find($idPermiso);

        if (!$permiso) {
            return response()->json([
                'success' => false,
                'message' => 'Permiso no encontrado'
            ], 404);
        }

        // Verificar si existe la relación
        if (!$perfil->permisos()->where('id_permiso', $idPermiso)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'El permiso no está asignado a este perfil'
            ], 400);
        }

        // Eliminar la relación
        $perfil->permisos()->detach($idPermiso);

        return response()->json([
            'success' => true,
            'message' => 'Permiso eliminado del perfil exitosamente',
            'data' => [
                'perfil_id' => $perfil->id,
                'permiso_eliminado' => $permiso->nombre
            ]
        ], 200);
    }

    /**
     * Obtener permisos disponibles para asignar a un perfil
     * (permisos del módulo que NO están asignados al perfil)
     */
    public function disponibles($idPerfil): JsonResponse
    {
        $perfil = Perfil::with('permisos')->find($idPerfil);

        if (!$perfil) {
            return response()->json([
                'success' => false,
                'message' => 'Perfil no encontrado'
            ], 404);
        }

        // Obtener IDs de permisos ya asignados
        $permisosAsignadosIds = $perfil->permisos->pluck('id')->toArray();

        // Obtener permisos del módulo que NO están asignados
        $permisosDisponibles = Permiso::where('id_modulo', $perfil->id_modulo)
            ->whereNotIn('id', $permisosAsignadosIds)
            ->activos()
            ->orderBy('orden')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Permisos disponibles obtenidos exitosamente',
            'data' => [
                'perfil' => $perfil->only(['id', 'nombre', 'codigo']),
                'modulo' => $perfil->modulo->only(['id', 'nombre', 'codigo']),
                'permisos_disponibles' => $permisosDisponibles,
                'total_disponibles' => $permisosDisponibles->count(),
                'total_asignados' => count($permisosAsignadosIds)
            ]
        ], 200);
    }

    /**
     * Eliminar todos los permisos de un perfil
     */
    public function clear($idPerfil): JsonResponse
    {
        $perfil = Perfil::find($idPerfil);

        if (!$perfil) {
            return response()->json([
                'success' => false,
                'message' => 'Perfil no encontrado'
            ], 404);
        }

        $totalEliminados = $perfil->permisos()->count();
        
        // Eliminar todas las relaciones
        $perfil->permisos()->detach();

        return response()->json([
            'success' => true,
            'message' => 'Todos los permisos han sido eliminados del perfil',
            'data' => [
                'perfil_id' => $perfil->id,
                'permisos_eliminados' => $totalEliminados
            ]
        ], 200);
    }
}
