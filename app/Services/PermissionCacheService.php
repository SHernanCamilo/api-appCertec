<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use App\Models\User;

class PermissionCacheService
{
    private const CACHE_PREFIX = 'user_permissions_';
    private const CACHE_TTL = 3600; // 1 hora

    /**
     * Obtener permisos del usuario (con caché)
     *
     * @param User $user
     * @return array
     */
    public function getUserPermissions(User $user): array
    {
        $cacheKey = self::CACHE_PREFIX . $user->id;
        
        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($user) {
            return $this->calculatePermissions($user);
        });
    }

    /**
     * Limpiar caché de permisos del usuario
     *
     * @param User $user
     * @return void
     */
    public function clearUserPermissions(User $user): void
    {
        $cacheKey = self::CACHE_PREFIX . $user->id;
        Cache::forget($cacheKey);
    }

    /**
     * Limpiar caché de todos los usuarios
     *
     * @return void
     */
    public function clearAllPermissions(): void
    {
        Cache::flush();
    }

    /**
     * Calcular permisos del usuario
     *
     * @param User $user
     * @return array
     */
    private function calculatePermissions(User $user): array
    {
        $user->load(['rolesCustom.perfiles.permisos', 'rolesCustom.perfiles.modulo']);
        
        $permisos = collect();
        $esAdmin = false;
        
        foreach ($user->rolesCustom as $rol) {
            // Si es admin, tiene todos los permisos
            if ($rol->es_admin) {
                $esAdmin = true;
                break;
            }
            
            foreach ($rol->perfiles as $perfil) {
                // Permisos específicos del perfil
                foreach ($perfil->permisos as $permiso) {
                    $permisos->push([
                        'id' => $permiso->id,
                        'codigo' => $permiso->codigo,
                        'nombre' => $permiso->nombre,
                        'tipo' => $permiso->tipo,
                        'modulo_id' => $permiso->id_modulo,
                        'modulo_codigo' => $permiso->modulo->codigo ?? null,
                        'modulo_nombre' => $permiso->modulo->nombre ?? null
                    ]);
                }
                
                // Permisos CRUD básicos
                $moduloCodigo = $perfil->modulo->codigo ?? 'unknown';
                
                if ($perfil->puede_crear) {
                    $permisos->push([
                        'codigo' => $moduloCodigo . '-crear',
                        'nombre' => 'Crear ' . ($perfil->modulo->nombre ?? ''),
                        'tipo' => 'accion',
                        'modulo_id' => $perfil->id_modulo,
                        'modulo_codigo' => $moduloCodigo,
                        'modulo_nombre' => $perfil->modulo->nombre ?? null
                    ]);
                }
                
                if ($perfil->puede_leer) {
                    $permisos->push([
                        'codigo' => $moduloCodigo . '-leer',
                        'nombre' => 'Leer ' . ($perfil->modulo->nombre ?? ''),
                        'tipo' => 'accion',
                        'modulo_id' => $perfil->id_modulo,
                        'modulo_codigo' => $moduloCodigo,
                        'modulo_nombre' => $perfil->modulo->nombre ?? null
                    ]);
                }
                
                if ($perfil->puede_editar) {
                    $permisos->push([
                        'codigo' => $moduloCodigo . '-editar',
                        'nombre' => 'Editar ' . ($perfil->modulo->nombre ?? ''),
                        'tipo' => 'accion',
                        'modulo_id' => $perfil->id_modulo,
                        'modulo_codigo' => $moduloCodigo,
                        'modulo_nombre' => $perfil->modulo->nombre ?? null
                    ]);
                }
                
                if ($perfil->puede_eliminar) {
                    $permisos->push([
                        'codigo' => $moduloCodigo . '-eliminar',
                        'nombre' => 'Eliminar ' . ($perfil->modulo->nombre ?? ''),
                        'tipo' => 'accion',
                        'modulo_id' => $perfil->id_modulo,
                        'modulo_codigo' => $moduloCodigo,
                        'modulo_nombre' => $perfil->modulo->nombre ?? null
                    ]);
                }
            }
        }
        
        return [
            'es_admin' => $esAdmin,
            'permisos' => $permisos->unique('codigo')->values()->toArray()
        ];
    }

    /**
     * Verificar si el usuario tiene un permiso específico
     *
     * @param User $user
     * @param string $codigoPermiso
     * @return bool
     */
    public function hasPermission(User $user, string $codigoPermiso): bool
    {
        $data = $this->getUserPermissions($user);
        
        // Si es admin, tiene todos los permisos
        if ($data['es_admin']) {
            return true;
        }
        
        // Buscar el permiso en la lista
        foreach ($data['permisos'] as $permiso) {
            if ($permiso['codigo'] === $codigoPermiso) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Verificar si el usuario tiene alguno de los permisos
     *
     * @param User $user
     * @param array $codigosPermisos
     * @return bool
     */
    public function hasAnyPermission(User $user, array $codigosPermisos): bool
    {
        foreach ($codigosPermisos as $codigo) {
            if ($this->hasPermission($user, $codigo)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Verificar si el usuario tiene todos los permisos
     *
     * @param User $user
     * @param array $codigosPermisos
     * @return bool
     */
    public function hasAllPermissions(User $user, array $codigosPermisos): bool
    {
        foreach ($codigosPermisos as $codigo) {
            if (!$this->hasPermission($user, $codigo)) {
                return false;
            }
        }
        
        return true;
    }
}
