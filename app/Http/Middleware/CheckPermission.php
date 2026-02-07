<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;

class CheckPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  ...$permisos - Códigos de permisos requeridos (OR logic)
     */
    public function handle(Request $request, Closure $next, ...$permisos): Response
    {
        $user = auth('api')->user();
        
        // Verificar autenticación
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'No autenticado'
            ], 401);
        }

        // Si no se especifican permisos, solo verificar autenticación
        if (empty($permisos)) {
            return $next($request);
        }

        // Cargar relaciones necesarias
        $user->load(['rolesCustom.perfiles.permisos']);

        // Verificar si el usuario tiene permisos
        if ($this->usuarioTienePermiso($user, $permisos)) {
            return $next($request);
        }

        // Log del intento de acceso no autorizado
        Log::warning('Acceso denegado', [
            'user_id' => $user->id,
            'email' => $user->email,
            'permisos_requeridos' => $permisos,
            'ruta' => $request->path(),
            'metodo' => $request->method(),
            'ip' => $request->ip()
        ]);

        return response()->json([
            'success' => false,
            'message' => 'No tienes permisos para realizar esta acción',
            'required_permissions' => $permisos
        ], 403);
    }

    /**
     * Verificar si el usuario tiene al menos uno de los permisos requeridos
     *
     * @param  \App\Models\User  $user
     * @param  array  $permisosRequeridos
     * @return bool
     */
    private function usuarioTienePermiso($user, array $permisosRequeridos): bool
    {
        // Verificar si alguno de los roles es administrador
        foreach ($user->rolesCustom as $rol) {
            if ($rol->es_admin) {
                return true;
            }
        }

        // Obtener todos los permisos del usuario
        $permisosUsuario = collect();
        foreach ($user->rolesCustom as $rol) {
            foreach ($rol->perfiles as $perfil) {
                // Agregar permisos del perfil
                $permisosUsuario = $permisosUsuario->merge(
                    $perfil->permisos->pluck('codigo')
                );
                
                // Agregar permisos CRUD básicos
                if ($perfil->puede_crear) {
                    $permisosUsuario->push($perfil->modulo->codigo . '-crear');
                }
                if ($perfil->puede_leer) {
                    $permisosUsuario->push($perfil->modulo->codigo . '-leer');
                }
                if ($perfil->puede_editar) {
                    $permisosUsuario->push($perfil->modulo->codigo . '-editar');
                }
                if ($perfil->puede_eliminar) {
                    $permisosUsuario->push($perfil->modulo->codigo . '-eliminar');
                }
            }
        }

        // Eliminar duplicados
        $permisosUsuario = $permisosUsuario->unique();

        // Verificar si tiene alguno de los permisos requeridos (OR logic)
        foreach ($permisosRequeridos as $permiso) {
            if ($permisosUsuario->contains($permiso)) {
                return true;
            }
        }

        return false;
    }
}