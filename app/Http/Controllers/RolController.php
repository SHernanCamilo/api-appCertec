<?php

namespace App\Http\Controllers;

use App\Models\Rol;
use App\Models\Empresa;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class RolController extends Controller
{
    /**
     * Listar todos los roles
     */
    public function index(Request $request): JsonResponse
    {
        $query = Rol::with(['empresa', 'perfiles.modulo']);

        // Filtros opcionales
        if ($request->has('id_empresa')) {
            if ($request->id_empresa === 'global') {
                $query->globales();
            } else {
                $query->porEmpresa($request->id_empresa);
            }
        }

        if ($request->has('estado')) {
            $query->where('estado', $request->estado);
        }

        if ($request->has('es_admin')) {
            $query->where('es_admin', $request->es_admin);
        }

        $roles = $query->orderBy('nombre')->get();

        return response()->json([
            'success' => true,
            'message' => 'Roles obtenidos exitosamente',
            'data' => $roles
        ], 200);
    }

    /**
     * Obtener un rol específico
     */
    public function show($id): JsonResponse
    {
        $rol = Rol::with(['empresa', 'perfiles.modulo', 'usuarios'])->find($id);

        if (!$rol) {
            return response()->json([
                'success' => false,
                'message' => 'Rol no encontrado'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Rol obtenido exitosamente',
            'data' => $rol
        ], 200);
    }

    /**
     * Crear un nuevo rol
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'nombre' => 'required|string|max:50',
            'codigo' => 'nullable|string|max:20|unique:seg_roles_custom,codigo',
            'id_empresa' => 'nullable|exists:ent_empresas,id',
            'descripcion' => 'nullable|string|max:255',
            'es_admin' => 'boolean',
            'estado' => 'boolean',
            'perfiles' => 'array',
            'perfiles.*' => 'exists:seg_perfiles,id'
        ]);

        // Generar código si no se proporciona
        $codigo = $request->codigo ?? Str::slug($request->nombre);

        $rol = Rol::create([
            'nombre' => $request->nombre,
            'codigo' => $codigo,
            'id_empresa' => $request->id_empresa,
            'descripcion' => $request->descripcion,
            'es_admin' => $request->es_admin ?? false,
            'estado' => $request->estado ?? true,
        ]);

        // Asignar perfiles si se proporcionan
        if ($request->has('perfiles')) {
            $rol->perfiles()->attach($request->perfiles);
        }

        $rol->load(['empresa', 'perfiles.modulo']);

        return response()->json([
            'success' => true,
            'message' => 'Rol creado exitosamente',
            'data' => $rol
        ], 201);
    }

    /**
     * Actualizar un rol
     */
    public function update(Request $request, $id): JsonResponse
    {
        $rol = Rol::find($id);

        if (!$rol) {
            return response()->json([
                'success' => false,
                'message' => 'Rol no encontrado'
            ], 404);
        }

        $request->validate([
            'nombre' => 'sometimes|required|string|max:50',
            'codigo' => 'sometimes|required|string|max:20|unique:seg_roles_custom,codigo,' . $id,
            'id_empresa' => 'nullable|exists:ent_empresas,id',
            'descripcion' => 'nullable|string|max:255',
            'es_admin' => 'boolean',
            'estado' => 'boolean',
            'perfiles' => 'array',
            'perfiles.*' => 'exists:seg_perfiles,id'
        ]);

        $rol->update($request->only([
            'nombre',
            'codigo',
            'id_empresa',
            'descripcion',
            'es_admin',
            'estado'
        ]));

        // Actualizar perfiles si se proporcionan
        if ($request->has('perfiles')) {
            $rol->perfiles()->sync($request->perfiles);
        }

        $rol->load(['empresa', 'perfiles.modulo']);

        return response()->json([
            'success' => true,
            'message' => 'Rol actualizado exitosamente',
            'data' => $rol
        ], 200);
    }

    /**
     * Eliminar un rol
     */
    public function destroy($id): JsonResponse
    {
        $rol = Rol::find($id);

        if (!$rol) {
            return response()->json([
                'success' => false,
                'message' => 'Rol no encontrado'
            ], 404);
        }

        // Verificar si tiene usuarios asignados
        if ($rol->usuarios()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'No se puede eliminar el rol porque tiene usuarios asignados'
            ], 400);
        }

        $rol->delete();

        return response()->json([
            'success' => true,
            'message' => 'Rol eliminado exitosamente'
        ], 200);
    }

    /**
     * Asignar perfiles a un rol
     */
    public function asignarPerfiles(Request $request, $id): JsonResponse
    {
        $rol = Rol::find($id);

        if (!$rol) {
            return response()->json([
                'success' => false,
                'message' => 'Rol no encontrado'
            ], 404);
        }

        $request->validate([
            'perfiles' => 'required|array',
            'perfiles.*' => 'exists:seg_perfiles,id'
        ]);

        $rol->perfiles()->sync($request->perfiles);
        $rol->load(['perfiles.modulo']);

        // Invalidar caché del sidebar para todos los usuarios con este rol
        $sidebarService = app(\App\Services\SidebarService::class);
        foreach ($rol->usuarios as $usuario) {
            $sidebarService->clearCache($usuario);
        }

        return response()->json([
            'success' => true,
            'message' => 'Perfiles asignados exitosamente',
            'data' => $rol
        ], 200);
    }

    /**
     * Obtener permisos de un rol
     */
    public function obtenerPermisos($id): JsonResponse
    {
        $rol = Rol::with(['perfiles.modulo'])->find($id);

        if (!$rol) {
            return response()->json([
                'success' => false,
                'message' => 'Rol no encontrado'
            ], 404);
        }

        $permisos = $rol->obtenerPermisos();

        return response()->json([
            'success' => true,
            'message' => 'Permisos obtenidos exitosamente',
            'data' => $permisos
        ], 200);
    }

    /**
     * Obtener roles por empresa
     */
    public function porEmpresa($idEmpresa): JsonResponse
    {
        $empresa = Empresa::find($idEmpresa);

        if (!$empresa) {
            return response()->json([
                'success' => false,
                'message' => 'Empresa no encontrada'
            ], 404);
        }

        // Obtener roles de la empresa + roles globales
        $roles = Rol::with(['perfiles.modulo'])
            ->where(function($query) use ($idEmpresa) {
                $query->where('id_empresa', $idEmpresa)
                      ->orWhereNull('id_empresa');
            })
            ->activos()
            ->orderBy('nombre')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Roles obtenidos exitosamente',
            'data' => $roles
        ], 200);
    }

    /**
     * Obtener roles por empresa filtrados por módulos con permisos
     * Solo devuelve roles cuyos perfiles pertenecen a módulos donde la empresa tiene acceso
     * Si el usuario autenticado es administrador, devuelve todos los roles
     */
    public function rolesPorEmpresaConModulos($idEmpresa): JsonResponse
    {
        $empresa = Empresa::find($idEmpresa);

        if (!$empresa) {
            return response()->json([
                'success' => false,
                'message' => 'Empresa no encontrada'
            ], 404);
        }

        // Verificar si el usuario autenticado es administrador
        $user = auth('api')->user();
        $esAdministrador = false;
        
        if ($user) {
            // Verificar si tiene algún rol de administrador
            $esAdministrador = $user->rolesCustom()->where('es_admin', true)->exists();
        }

        // Si es administrador, devolver todos los roles sin filtrar
        if ($esAdministrador) {
            $roles = Rol::with(['perfiles.modulo'])
                ->where(function($query) use ($idEmpresa) {
                    $query->where('id_empresa', $idEmpresa)
                          ->orWhereNull('id_empresa');
                })
                ->activos()
                ->orderBy('nombre')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Todos los roles obtenidos (usuario administrador)',
                'data' => $roles,
                'is_admin' => true
            ], 200);
        }

        // Para usuarios no administradores, aplicar filtros por módulos
        $modulosConPermiso = DB::table('seg_modulo_empresa')
            ->where('id_empresa', $idEmpresa)
            ->where('activo', 1)
            ->pluck('id_modulo')
            ->toArray();

        if (empty($modulosConPermiso)) {
            return response()->json([
                'success' => true,
                'message' => 'No hay módulos asignados a esta empresa',
                'data' => [],
                'is_admin' => false
            ], 200);
        }

        // Obtener roles que tienen perfiles en esos módulos + roles globales sin perfiles específicos
        $roles = Rol::with(['perfiles.modulo'])
            ->where(function($query) use ($idEmpresa) {
                $query->where('id_empresa', $idEmpresa)
                      ->orWhereNull('id_empresa');
            })
            ->where(function($query) use ($modulosConPermiso) {
                // Roles que tienen perfiles en módulos permitidos
                $query->whereHas('perfiles', function($subQuery) use ($modulosConPermiso) {
                    $subQuery->whereIn('id_modulo', $modulosConPermiso);
                })
                // O roles que no tienen perfiles específicos (roles globales)
                ->orWhereDoesntHave('perfiles')
                // O roles de administrador (siempre disponibles)
                ->orWhere('es_admin', true);
            })
            ->activos()
            ->orderBy('nombre')
            ->get();

        // Filtrar perfiles de cada rol para mostrar solo los de módulos permitidos (excepto para roles admin)
        $roles->each(function($rol) use ($modulosConPermiso) {
            if (!$rol->es_admin) {
                $rol->perfiles = $rol->perfiles->filter(function($perfil) use ($modulosConPermiso) {
                    return in_array($perfil->id_modulo, $modulosConPermiso);
                });
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Roles filtrados por módulos obtenidos exitosamente',
            'data' => $roles,
            'is_admin' => false
        ], 200);
    }

    /**
     * Obtener roles por múltiples empresas filtrados por módulos con permisos
     * Recibe un array de IDs de empresas y devuelve roles que están disponibles
     * para al menos una de esas empresas
     */
    public function rolesPorMultiplesEmpresas(Request $request): JsonResponse
    {
        $request->validate([
            'empresas' => 'required|array|min:1',
            'empresas.*' => 'exists:ent_empresas,id'
        ]);

        $empresasIds = $request->empresas;

        // Verificar si el usuario autenticado es administrador
        $user = auth('api')->user();
        $esAdministrador = false;
        
        if ($user) {
            $esAdministrador = $user->rolesCustom()->where('es_admin', true)->exists();
        }

        // Si es administrador, devolver todos los roles sin filtrar
        if ($esAdministrador) {
            $roles = Rol::with(['perfiles.modulo'])
                ->where(function($query) use ($empresasIds) {
                    $query->whereIn('id_empresa', $empresasIds)
                          ->orWhereNull('id_empresa');
                })
                ->activos()
                ->orderBy('nombre')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Todos los roles obtenidos (usuario administrador)',
                'data' => $roles,
                'is_admin' => true,
                'empresas_procesadas' => $empresasIds
            ], 200);
        }

        // Para usuarios no administradores, obtener módulos con permisos de todas las empresas
        $modulosConPermiso = DB::table('seg_modulo_empresa')
            ->whereIn('id_empresa', $empresasIds)
            ->where('activo', 1)
            ->pluck('id_modulo')
            ->unique()
            ->toArray();

        if (empty($modulosConPermiso)) {
            return response()->json([
                'success' => true,
                'message' => 'No hay módulos asignados a estas empresas',
                'data' => [],
                'is_admin' => false,
                'empresas_procesadas' => $empresasIds
            ], 200);
        }

        // Obtener roles que están disponibles para al menos una de las empresas
        $roles = Rol::with(['perfiles.modulo'])
            ->where(function($query) use ($empresasIds) {
                $query->whereIn('id_empresa', $empresasIds)
                      ->orWhereNull('id_empresa');
            })
            ->where(function($query) use ($modulosConPermiso) {
                // Roles que tienen perfiles en módulos permitidos
                $query->whereHas('perfiles', function($subQuery) use ($modulosConPermiso) {
                    $subQuery->whereIn('id_modulo', $modulosConPermiso);
                })
                // O roles que no tienen perfiles específicos (roles globales)
                ->orWhereDoesntHave('perfiles')
                // O roles de administrador (siempre disponibles)
                ->orWhere('es_admin', true);
            })
            ->activos()
            ->orderBy('nombre')
            ->get();

        // Filtrar perfiles de cada rol para mostrar solo los de módulos permitidos (excepto para roles admin)
        $roles->each(function($rol) use ($modulosConPermiso) {
            if (!$rol->es_admin) {
                $rol->perfiles = $rol->perfiles->filter(function($perfil) use ($modulosConPermiso) {
                    return in_array($perfil->id_modulo, $modulosConPermiso);
                });
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Roles filtrados por múltiples empresas obtenidos exitosamente',
            'data' => $roles,
            'is_admin' => false,
            'empresas_procesadas' => $empresasIds
        ], 200);
    }
}
