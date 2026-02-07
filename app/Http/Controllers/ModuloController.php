<?php

namespace App\Http\Controllers;

use App\Models\Modulo;
use App\Models\User;
use Illuminate\Http\Request;

class ModuloController extends Controller
{
    /**
     * Obtener módulos del usuario autenticado
     */
    public function getModulosUsuario()
    {
        try {
            $user = auth('api')->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'No autenticado'
                ], 401);
            }

            // Cargar relaciones necesarias
            try {
                $user->load(['empresas', 'rolesCustom.perfiles']);
            } catch (\Exception $e) {
                \Log::error('Error cargando relaciones del usuario: ' . $e->getMessage());
            }

            // Obtener todos los módulos raíz activos
            $modulos = Modulo::whereNull('id_modulo_padre')
                ->where('estado', 1)
                ->orderBy('orden')
                ->get();

            // Cargar hijos manualmente
            foreach ($modulos as $modulo) {
                $modulo->load(['hijos' => function($query) {
                    $query->where('estado', 1)->orderBy('orden');
                }]);
            }

            // Formatear respuesta con estructura jerárquica
            $menuItems = [];
            foreach ($modulos as $modulo) {
                $item = $this->formatModuloParaMenu($modulo, $user);
                if ($item) {
                    $menuItems[] = $item;
                }
            }

            return response()->json([
                'success' => true,
                'data' => $menuItems
            ]);
        } catch (\Exception $e) {
            \Log::error('Error en getModulosUsuario: ' . $e->getMessage());
            \Log::error('Stack trace: ' . $e->getTraceAsString());
            
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener módulos',
                'error' => $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null
            ], 500);
        }
    }

    /**
     * Formatear módulo para el menú
     */
    private function formatModuloParaMenu($modulo, $user)
    {
        // Verificar si el usuario tiene permisos para este módulo
        $tienePermiso = $this->usuarioTienePermisoModulo($user, $modulo);

        if (!$tienePermiso) {
            return null;
        }

        $item = [
            'id' => $modulo->id,
            'nombre' => $modulo->nombre,
            'codigo' => $modulo->codigo,
            'icono' => $modulo->icono ?? 'bi-circle',
            'ruta' => $modulo->ruta ?? '#',
            'orden' => $modulo->orden ?? 0,
            'hijos' => []
        ];

        // Obtener hijos si existen
        if ($modulo->hijos && $modulo->hijos->count() > 0) {
            $hijos = [];
            foreach ($modulo->hijos as $hijo) {
                $hijoFormateado = $this->formatModuloParaMenu($hijo, $user);
                if ($hijoFormateado) {
                    $hijos[] = $hijoFormateado;
                }
            }
            $item['hijos'] = $hijos;
        }

        return $item;
    }

    /**
     * Verificar si el usuario tiene permiso para acceder al módulo
     */
    private function usuarioTienePermisoModulo($user, $modulo)
    {
        // Si no tiene roles, no tiene acceso
        if (!$user->rolesCustom || $user->rolesCustom->isEmpty()) {
            return false;
        }

        // Si es super admin, tiene acceso a todo
        foreach ($user->rolesCustom as $rol) {
            if ($rol->es_admin) {
                return true;
            }
        }

        // Verificar si tiene perfiles relacionados con el módulo o sus hijos
        foreach ($user->rolesCustom as $rol) {
            if (!$rol->perfiles) {
                continue;
            }
            
            foreach ($rol->perfiles as $perfil) {
                // Verificar permiso directo en el módulo actual
                if ($perfil->id_modulo == $modulo->id && $perfil->puede_leer) {
                    return true;
                }
                
                // Verificar si tiene permiso en algún hijo del módulo
                if ($this->tienePermisoEnHijos($modulo, $perfil)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Verificar si el perfil tiene permiso en algún hijo del módulo (recursivo)
     */
    private function tienePermisoEnHijos($modulo, $perfil)
    {
        if (!$modulo->hijos || $modulo->hijos->isEmpty()) {
            return false;
        }

        foreach ($modulo->hijos as $hijo) {
            // Verificar permiso en el hijo
            if ($perfil->id_modulo == $hijo->id && $perfil->puede_leer) {
                return true;
            }
            
            // Verificar recursivamente en los hijos del hijo
            if ($this->tienePermisoEnHijos($hijo, $perfil)) {
                return true;
            }
        }

        return false;
    }
}
