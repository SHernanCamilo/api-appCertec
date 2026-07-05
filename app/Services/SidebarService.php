<?php

namespace App\Services;

use App\Models\Modulo;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class SidebarService
{
    // TTL del cache del sidebar por usuario (10 minutos)
    private const CACHE_TTL = 600;

    public function getSidebarModules(User $user): array
    {
        $version  = (int) Cache::get('sidebar_cache_version', 1);
        $cacheKey = "sidebar_user_{$user->id}_v{$version}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($user) {
            return $this->buildSidebar($user);
        });
    }

    /**
     * Invalida el cache del sidebar de un usuario (llamar al cambiar roles/permisos)
     */
    public function clearCache(User $user): void
    {
        $version = (int) Cache::get('sidebar_cache_version', 1);
        Cache::forget("sidebar_user_{$user->id}_v{$version}");
    }

    /**
     * Invalida el sidebar de todos los usuarios (llamar al cambiar módulos del menú)
     */
    public function invalidateAllSidebarCache(): void
    {
        Cache::increment('sidebar_cache_version');
    }

    private function buildSidebar(User $user): array
    {
        // Una sola query con todas las relaciones necesarias
        $user->loadMissing(['rolesCustom.perfiles.modulo', 'rolesCustom.perfiles.permisos']);

        // Obtener todos los módulos activos con sus hijos en una sola query
        $modulos = Modulo::activos()
            ->with(['hijos' => function ($query) {
                $query->activos()->orderBy('orden');
            }])
            ->raiz()
            ->orderBy('orden')
            ->get();

        // Pre-calcular permisos del usuario indexados por id_modulo (evita loops anidados)
        $permisosPorModulo = $this->indexarPermisosPorModulo($user);

        return $this->construirJerarquia($modulos, $permisosPorModulo, 0);
    }

    /**
     * Indexa todos los permisos del usuario por id_modulo en una sola pasada.
     * Evita el triple loop anidado original.
     */
    private function indexarPermisosPorModulo(User $user): array
    {
        $index = []; // [modulo_id => ['tiene_acceso' => bool, 'crud' => [...], 'permisos_ids' => [...]]]

        foreach ($user->rolesCustom as $rol) {
            foreach ($rol->perfiles as $perfil) {
                $moduloId = $perfil->id_modulo;

                if (!isset($index[$moduloId])) {
                    $index[$moduloId] = [
                        'puede_leer'     => false,
                        'puede_crear'    => false,
                        'puede_editar'   => false,
                        'puede_eliminar' => false,
                        'permisos'       => [], // [modulo_id => [orden => bool]]
                    ];
                }

                // Acumular CRUD
                $index[$moduloId]['puede_leer']     = $index[$moduloId]['puede_leer']     || $perfil->puede_leer;
                $index[$moduloId]['puede_crear']    = $index[$moduloId]['puede_crear']    || $perfil->puede_crear;
                $index[$moduloId]['puede_editar']   = $index[$moduloId]['puede_editar']   || $perfil->puede_editar;
                $index[$moduloId]['puede_eliminar'] = $index[$moduloId]['puede_eliminar'] || $perfil->puede_eliminar;

                // Indexar permisos extras por módulo destino
                foreach ($perfil->permisos as $permiso) {
                    if (!$permiso->estado) continue;
                    $pid = $permiso->id_modulo;
                    if (!isset($index[$pid])) {
                        $index[$pid] = [
                            'puede_leer' => false, 'puede_crear' => false,
                            'puede_editar' => false, 'puede_eliminar' => false,
                            'permisos' => [],
                        ];
                    }
                    $index[$pid]['permisos'][$permiso->orden] = true;
                }
            }
        }

        return $index;
    }

    private function construirJerarquia(Collection $modulos, array $permisosPorModulo, int $nivel): array
    {
        $resultado = [];

        foreach ($modulos as $modulo) {
            $hijos = [];
            if ($modulo->hijos && $modulo->hijos->count() > 0) {
                $hijos = $this->construirJerarquia($modulo->hijos, $permisosPorModulo, $nivel + 1);
            }

            $tieneAcceso = $this->tieneAcceso($modulo->id, $permisosPorModulo);
            $tieneAccesoFinal = $tieneAcceso || count($hijos) > 0;

            if ($tieneAccesoFinal) {
                $crud = $permisosPorModulo[$modulo->id] ?? null;
                $resultado[] = [
                    'id'              => $modulo->id,
                    'nombre'          => $modulo->nombre,
                    'codigo'          => $modulo->codigo,
                    'icono'           => $modulo->icono ?? 'bi-circle',
                    'ruta'            => $modulo->ruta,
                    'orden'           => $modulo->orden,
                    'nivel'           => $nivel,
                    'id_modulo_padre' => $modulo->id_modulo_padre,
                    'tiene_acceso'    => $tieneAccesoFinal,
                    'permisos_basicos' => [
                        'puede_leer'     => $crud['puede_leer']     ?? false,
                        'puede_crear'    => $crud['puede_crear']    ?? false,
                        'puede_editar'   => $crud['puede_editar']   ?? false,
                        'puede_eliminar' => $crud['puede_eliminar'] ?? false,
                    ],
                    'hijos' => $hijos,
                ];
            }
        }

        return $resultado;
    }

    private function tieneAcceso(int $moduloId, array $permisosPorModulo): bool
    {
        if (!isset($permisosPorModulo[$moduloId])) {
            return false;
        }
        $data = $permisosPorModulo[$moduloId];

        // Tiene permiso visible (orden=0) o permiso CRUD de lectura
        return isset($data['permisos'][0]) || $data['puede_leer'];
    }

    public function getModulosPlanos(User $user): Collection
    {
        $modulos = collect();
        $user->loadMissing(['rolesCustom.perfiles.modulo']);

        foreach ($user->rolesCustom as $rol) {
            foreach ($rol->perfiles as $perfil) {
                if ($perfil->modulo && $perfil->puede_leer) {
                    $modulos->push([
                        'id'              => $perfil->modulo->id,
                        'codigo'          => $perfil->modulo->codigo,
                        'nombre'          => $perfil->modulo->nombre,
                        'puede_leer'      => $perfil->puede_leer,
                        'puede_crear'     => $perfil->puede_crear,
                        'puede_editar'    => $perfil->puede_editar,
                        'puede_eliminar'  => $perfil->puede_eliminar,
                    ]);
                }
            }
        }

        return $modulos->unique('id');
    }
}
