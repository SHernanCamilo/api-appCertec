<?php

namespace App\Services;

use App\Models\Modulo;
use App\Models\User;
use App\Models\Perfil;
use Illuminate\Support\Collection;

class SidebarService
{
    /**
     * Obtiene los módulos del sidebar para un usuario
     * Incluye jerarquía completa y permisos básicos CRUD
     *
     * @param User $user
     * @return array
     */
    public function getSidebarModules(User $user): array
    {
        \Log::info('🔍 Iniciando getSidebarModules para usuario:', ['user_id' => $user->id, 'name' => $user->name]);
        
        // Cargar relaciones necesarias (incluyendo permisos extras)
        $user->load(['rolesCustom.perfiles.modulo', 'rolesCustom.perfiles.permisos', 'empresas']);
        
        \Log::info('📋 Roles del usuario:', ['roles' => $user->rolesCustom->pluck('nombre')->toArray()]);
        \Log::info('🏢 Empresas del usuario:', ['empresas' => $user->empresas->pluck('nombre')->toArray()]);
        \Log::info('📊 Perfiles del usuario:', [
            'total' => $user->rolesCustom->flatMap->perfiles->count(),
            'modulos' => $user->rolesCustom->flatMap->perfiles->pluck('modulo.nombre')->unique()->toArray()
        ]);

        // Obtener todos los módulos activos con jerarquía
        $modulos = Modulo::activos()
            ->with(['hijos' => function ($query) {
                $query->activos()->orderBy('orden');
            }])
            ->raiz()
            ->orderBy('orden')
            ->get();
        
        \Log::info('📦 Módulos raíz encontrados:', ['total' => $modulos->count(), 'nombres' => $modulos->pluck('nombre')->toArray()]);

        // Construir jerarquía con permisos
        $sidebar = $this->construirJerarquia($modulos, $user, 0);
        
        \Log::info('✅ Sidebar construido:', ['total_items' => count($sidebar)]);

        return $sidebar;
    }

    /**
     * Construye la jerarquía de módulos recursivamente
     *
     * @param Collection $modulos
     * @param User $user
     * @param int $nivel
     * @return array
     */
    private function construirJerarquia(Collection $modulos, User $user, int $nivel): array
    {
        $resultado = [];

        foreach ($modulos as $modulo) {
            // Verificar si el usuario tiene acceso al módulo
            $tieneAcceso = $this->usuarioTieneAccesoModulo($user, $modulo);

            // Obtener permisos básicos del usuario para este módulo
            $permisosBasicos = $this->getPermisosBasicos($user, $modulo);

            // Construir hijos recursivamente
            $hijos = [];
            if ($modulo->hijos && $modulo->hijos->count() > 0) {
                $hijos = $this->construirJerarquia($modulo->hijos, $user, $nivel + 1);
            }

            // Si tiene hijos con acceso, el padre también debe tener acceso
            $tieneAccesoFinal = $tieneAcceso || count($hijos) > 0;

            // Solo incluir si tiene acceso o tiene hijos con acceso
            if ($tieneAccesoFinal) {
                $resultado[] = [
                    'id' => $modulo->id,
                    'nombre' => $modulo->nombre,
                    'codigo' => $modulo->codigo,
                    'icono' => $modulo->icono ?? 'bi-circle',
                    'ruta' => $modulo->ruta,
                    'orden' => $modulo->orden,
                    'nivel' => $nivel,
                    'id_modulo_padre' => $modulo->id_modulo_padre,
                    'tiene_acceso' => $tieneAccesoFinal, // Usar el acceso final
                    'permisos_basicos' => $permisosBasicos,
                    'hijos' => $hijos
                ];
            }
        }

        return $resultado;
    }

    /**
     * Verifica si el usuario tiene acceso a un módulo
     *
     * @param User $user
     * @param Modulo $modulo
     * @return bool
     */
    private function usuarioTieneAccesoModulo(User $user, Modulo $modulo): bool
    {
        // Verificar si el usuario tiene algún perfil para este módulo
        foreach ($user->rolesCustom as $rol) {
            foreach ($rol->perfiles as $perfil) {
                // Verificar permisos extras del perfil (cualquier módulo)
                if ($perfil->permisos && $perfil->permisos->count() > 0) {
                    // Buscar permiso con orden = 0 (permiso -visible) para este módulo
                    $tienePermisoVisible = $perfil->permisos
                        ->where('id_modulo', $modulo->id)
                        ->where('orden', 0)
                        ->where('estado', true)
                        ->count() > 0;
                    
                    if ($tienePermisoVisible) {
                        \Log::debug("✅ Usuario tiene acceso a módulo '{$modulo->nombre}' via permiso orden=0 (-visible)");
                        return true;
                    }
                }
                
                // Si el perfil es específico de este módulo, verificar permisos CRUD
                if ($perfil->id_modulo == $modulo->id) {
                    \Log::debug("🔍 Verificando perfil '{$perfil->nombre}' para módulo '{$modulo->nombre}'", [
                        'puede_leer' => $perfil->puede_leer,
                        'permisos_count' => $perfil->permisos ? $perfil->permisos->count() : 0
                    ]);
                    
                    // Verificar que tenga al menos permiso de lectura
                    if ($perfil->puede_leer) {
                        \Log::debug("✅ Usuario tiene acceso a módulo '{$modulo->nombre}' via permiso CRUD");
                        return true;
                    }
                    
                    // Verificar si tiene otros permisos activos del módulo
                    if ($perfil->permisos && $perfil->permisos->count() > 0) {
                        $tienePermisoActivo = $perfil->permisos
                            ->where('id_modulo', $modulo->id)
                            ->where('estado', true)
                            ->count() > 0;
                        
                        if ($tienePermisoActivo) {
                            \Log::debug("✅ Usuario tiene acceso a módulo '{$modulo->nombre}' via permisos extras");
                            return true;
                        }
                    }
                }
            }
        }

        \Log::debug("❌ Usuario NO tiene acceso a módulo '{$modulo->nombre}'");
        return false;
    }

    /**
     * Obtiene los permisos básicos CRUD del usuario para un módulo
     *
     * @param User $user
     * @param Modulo $modulo
     * @return array
     */
    private function getPermisosBasicos(User $user, Modulo $modulo): array
    {
        $permisos = [
            'puede_leer' => false,
            'puede_crear' => false,
            'puede_editar' => false,
            'puede_eliminar' => false
        ];

        // Recorrer roles y perfiles del usuario
        foreach ($user->rolesCustom as $rol) {
            foreach ($rol->perfiles as $perfil) {
                if ($perfil->id_modulo == $modulo->id) {
                    // Acumular permisos (si tiene en algún perfil, lo tiene)
                    $permisos['puede_leer'] = $permisos['puede_leer'] || $perfil->puede_leer;
                    $permisos['puede_crear'] = $permisos['puede_crear'] || $perfil->puede_crear;
                    $permisos['puede_editar'] = $permisos['puede_editar'] || $perfil->puede_editar;
                    $permisos['puede_eliminar'] = $permisos['puede_eliminar'] || $perfil->puede_eliminar;
                }
            }
        }

        return $permisos;
    }

    /**
     * Obtiene los módulos en formato plano (sin jerarquía)
     * Útil para verificaciones rápidas
     *
     * @param User $user
     * @return Collection
     */
    public function getModulosPlanos(User $user): Collection
    {
        $modulos = collect();

        foreach ($user->rolesCustom as $rol) {
            foreach ($rol->perfiles as $perfil) {
                if ($perfil->modulo && $perfil->puede_leer) {
                    $modulos->push([
                        'id' => $perfil->modulo->id,
                        'codigo' => $perfil->modulo->codigo,
                        'nombre' => $perfil->modulo->nombre,
                        'puede_leer' => $perfil->puede_leer,
                        'puede_crear' => $perfil->puede_crear,
                        'puede_editar' => $perfil->puede_editar,
                        'puede_eliminar' => $perfil->puede_eliminar
                    ]);
                }
            }
        }

        return $modulos->unique('id');
    }
}
