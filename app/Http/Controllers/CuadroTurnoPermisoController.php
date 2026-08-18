<?php

namespace App\Http\Controllers;

use App\Models\CuadroTurnoPermiso;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * Controlador para gestionar permisos de Cuadro de Turnos
 * 
 * Permite asignar/quitar permisos de forma granular por usuario/empresa/sede
 */
class CuadroTurnoPermisoController extends Controller
{
    /**
     * GET /api/cuadro-turno-permisos/usuarios
     * Listar todos los usuarios para asignar permisos
     */
    public function listarUsuarios(Request $request): JsonResponse
    {
        try {
            $search = $request->query('search', '');
            
            $query = User::where('estado', 1); // Solo usuarios activos

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            }

            $usuarios = $query->select('id', 'name', 'email', 'cargo')
                ->orderBy('name')
                ->get();

            return response()->json([
                'success' => true,
                'data'    => $usuarios,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener usuarios: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/cuadro-turno-permisos/usuario-completo/{userId}
     * Obtener usuario con empresa, sede y unidades funcionales relacionadas
     */
    public function usuarioCompleto(int $userId): JsonResponse
    {
        try {
            // Obtener usuario
            $usuario = User::findOrFail($userId);

            // Obtener todas las asignaciones empresa-sede del usuario
            $asignacionesUsuario = \DB::table('seg_empresa_user')
                ->where('user_id', $userId)
                ->get();

            // Agrupar por empresa
            $datosCompletos = [];
            $empresasProcessadas = [];

            foreach ($asignacionesUsuario as $asignacion) {
                // Obtener datos de la empresa
                $empresa = \DB::table('ent_empresas')
                    ->where('id', $asignacion->empresa_id)
                    ->first();

                if (!$empresa) continue;

                // Crear clave única para la empresa
                $empresaKey = $empresa->id;

                // Si es la primera vez que vemos esta empresa, crearla
                if (!isset($empresasProcessadas[$empresaKey])) {
                    $empresasProcessadas[$empresaKey] = [
                        'empresa_id' => $empresa->id,
                        'empresa_nombre' => $empresa->nombre,
                        'empresa_prefijo' => $empresa->prefijo,
                        'sedes' => []
                    ];
                }

                // Obtener la sede
                $sede = null;
                if ($asignacion->id_sede) {
                    $sede = \DB::table('config_ubi_sede')
                        ->where('id', $asignacion->id_sede)
                        ->first();
                } else {
                    // Si no hay sede específica, obtener todas las sedes de la empresa
                    $sedes = \DB::table('config_ubi_sede')
                        ->join('config_ubi_sucursales', 'config_ubi_sede.id_sucursal', '=', 'config_ubi_sucursales.id')
                        ->where('config_ubi_sucursales.id_empresa', $empresa->id)
                        ->select('config_ubi_sede.*')
                        ->get();
                    
                    if ($sedes->count() > 0) {
                        $sede = $sedes->first();
                    }
                }

                if ($sede) {
                    // Crear clave única para la sede
                    $sedeKey = $sede->id;

                    // Si es la primera vez que vemos esta sede en esta empresa
                    if (!isset($empresasProcessadas[$empresaKey]['sedes'][$sedeKey])) {
                        // Obtener unidades funcionales de esta sede y empresa
                        $unidades = \DB::table('config_unidades_funcionales')
                            ->where('id_empresa', $empresa->id)
                            ->where(function ($q) use ($sede) {
                                $q->where('id_sede', $sede->id)
                                  ->orWhereNull('id_sede');
                            })
                            ->select('id', 'codigo', 'nombre', 'id_empresa', 'id_sede')
                            ->orderBy('nombre')
                            ->get();

                        $empresasProcessadas[$empresaKey]['sedes'][$sedeKey] = [
                            'id' => $sede->id,
                            'nombre' => $sede->nombre,
                            'id_sucursal' => $sede->id_sucursal,
                            'unidades_funcionales' => $unidades->toArray()
                        ];
                    }
                }
            }

            // Convertir a array indexado
            foreach ($empresasProcessadas as &$empresa) {
                $empresa['sedes'] = array_values($empresa['sedes']);
            }
            $datosCompletos = array_values($empresasProcessadas);

            return response()->json([
                'success' => true,
                'usuario' => [
                    'id' => $usuario->id,
                    'nombre' => $usuario->name,
                    'email' => $usuario->email,
                ],
                'datos_completos' => $datosCompletos,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener datos del usuario: ' . $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }

    /**
     * GET /api/cuadro-turno-permisos/debug
     * Endpoint de debug para verificar datos en la BD
     */
    public function debug(): JsonResponse
    {
        try {
            $empresas = \DB::table('ent_empresas')->count();
            $sedes = \DB::table('config_ubi_sede')->count();
            $unidades = \DB::table('config_unidad_funcional')->count();
            $usuarios = \DB::table('users')->count();

            return response()->json([
                'success' => true,
                'debug' => [
                    'empresas_count' => $empresas,
                    'sedes_count' => $sedes,
                    'unidades_count' => $unidades,
                    'usuarios_count' => $usuarios,
                    'empresas_data' => \DB::table('ent_empresas')->select('id', 'nombre')->limit(5)->get(),
                    'sedes_data' => \DB::table('config_ubi_sede')->select('id', 'nombre')->limit(5)->get(),
                    'usuarios_data' => \DB::table('users')->select('id', 'name', 'email')->limit(5)->get(),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }

    /**
     * GET /api/cuadro-turno-permisos/empresas
     * Listar empresas habilitadas para el módulo de Cuadro de Turnos
     */
    public function listarEmpresas(): JsonResponse
    {
        try {
            $query = \DB::table('ent_empresas')
                ->select('id', 'nombre', 'prefijo');

            $empresasHabilitadas = config('cuadro_turnos.empresas_habilitadas', []);
            if (!empty($empresasHabilitadas)) {
                $query->whereIn('id', $empresasHabilitadas);
            }

            $empresas = $query->orderBy('nombre')->get();

            return response()->json([
                'success' => true,
                'data'    => $empresas,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener empresas: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/cuadro-turno-permisos/sedes
     * Listar todas las sedes de la base de datos
     */
    public function listarSedes(): JsonResponse
    {
        try {
            $sedes = \DB::table('config_ubi_sede')
                ->select('id', 'nombre', 'id_sucursal')
                ->orderBy('nombre')
                ->get();

            return response()->json([
                'success' => true,
                'data'    => $sedes,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener sedes: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/cuadro-turno-permisos/unidades-funcionales/{sedeId}
     * Listar unidades funcionales de una sede específica
     */
    public function listarUnidadesPorSede(int $sedeId): JsonResponse
    {
        try {
            $unidades = \DB::table('config_unidades_funcionales')
                ->select('id', 'codigo', 'nombre', 'id_empresa', 'id_sede')
                ->where(function ($q) use ($sedeId) {
                    $q->where('id_sede', $sedeId)
                      ->orWhereNull('id_sede');
                })
                ->orderBy('nombre')
                ->get();

            return response()->json([
                'success' => true,
                'data'    => $unidades,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener unidades funcionales: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/cuadro-turno-permisos/unidades-funcionales-con-prefijo/{sedeId}
     * Listar unidades funcionales con prefijo de empresa
     */
    public function listarUnidadesPorSedeConPrefijo(int $sedeId): JsonResponse
    {
        try {
            $unidades = \DB::table('config_unidades_funcionales')
                ->join('ent_empresas', 'config_unidades_funcionales.id_empresa', '=', 'ent_empresas.id')
                ->select(
                    'config_unidades_funcionales.id',
                    'config_unidades_funcionales.codigo',
                    'config_unidades_funcionales.nombre',
                    'config_unidades_funcionales.id_empresa',
                    'config_unidades_funcionales.id_sede',
                    'ent_empresas.prefijo',
                    'ent_empresas.nombre as empresa_nombre',
                    \DB::raw("CONCAT(ent_empresas.prefijo, '-', config_unidades_funcionales.nombre) as nombre_con_prefijo")
                )
                ->where(function ($q) use ($sedeId) {
                    $q->where('config_unidades_funcionales.id_sede', $sedeId)
                      ->orWhereNull('config_unidades_funcionales.id_sede');
                })
                ->orderBy('ent_empresas.prefijo')
                ->orderBy('config_unidades_funcionales.nombre')
                ->get();

            return response()->json([
                'success' => true,
                'data'    => $unidades,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener unidades funcionales: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/cuadro-turno-permisos
     * Listar todos los permisos (con filtros opcionales)
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = CuadroTurnoPermiso::with(['usuario', 'empresa', 'sede']);

            if ($request->filled('user_id')) {
                $query->porUsuario((int) $request->user_id);
            }

            if ($request->filled('id_empresa')) {
                $query->porEmpresa((int) $request->id_empresa);
            }

            if ($request->filled('id_sede')) {
                $query->porSede((int) $request->id_sede);
            }

            if ($request->filled('tipo_permiso')) {
                $query->porTipoPermiso($request->tipo_permiso);
            }

            if ($request->filled('activo')) {
                $query->where('activo', filter_var($request->activo, FILTER_VALIDATE_BOOLEAN));
            } else {
                $query->activos();
            }

            $permisos = $query->orderBy('user_id')->orderBy('id_empresa')->get();

            return response()->json([
                'success' => true,
                'data'    => $permisos,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener permisos: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/cuadro-turno-permisos/usuario/{userId}
     * Obtener permisos de un usuario específico
     */
    public function permisosPorUsuario(int $userId): JsonResponse
    {
        try {
            $usuario = User::findOrFail($userId);

            $permisos = CuadroTurnoPermiso::activos()
                ->porUsuario($userId)
                ->with(['empresa', 'sede'])
                ->get()
                ->groupBy('id_empresa');

            return response()->json([
                'success' => true,
                'usuario' => [
                    'id' => $usuario->id,
                    'nombre' => $usuario->name,
                    'email' => $usuario->email,
                ],
                'permisos_por_empresa' => $permisos,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener permisos del usuario: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/cuadro-turno-permisos
     * Crear un nuevo permiso
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'user_id'       => 'required|integer|exists:users,id',
            'id_empresa'    => 'required|integer|exists:ent_empresas,id',
            'id_sede'       => 'nullable|integer|exists:config_ubi_sede,id',
            'tipo_permiso'  => 'required|in:visualizar,crear,editar,eliminar,publicar,cerrar',
            'activo'        => 'boolean',
        ]);

        try {
            // Verificar que el usuario tiene acceso a esa empresa/sede
            $this->verificarAccesoUsuario($request->user_id, $request->id_empresa, $request->id_sede);

            // Crear o actualizar el permiso
            $permiso = CuadroTurnoPermiso::updateOrCreate(
                [
                    'user_id'      => $request->user_id,
                    'id_empresa'   => $request->id_empresa,
                    'id_sede'      => $request->id_sede,
                    'tipo_permiso' => $request->tipo_permiso,
                ],
                [
                    'activo'         => $request->boolean('activo', true),
                    'creado_por'     => auth()->id(),
                    'actualizado_por' => auth()->id(),
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Permiso asignado exitosamente',
                'data'    => $permiso->load(['usuario', 'empresa', 'sede']),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al asignar permiso: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * DELETE /api/cuadro-turno-permisos/{id}
     * Eliminar un permiso
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $permiso = CuadroTurnoPermiso::findOrFail($id);
            $permiso->update(['activo' => false]);

            return response()->json([
                'success' => true,
                'message' => 'Permiso revocado exitosamente',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al revocar permiso: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/cuadro-turno-permisos/asignar-multiples
     * Asignar múltiples permisos a un usuario
     */
    public function asignarMultiples(Request $request): JsonResponse
    {
        $request->validate([
            'user_id'    => 'required|integer|exists:users,id',
            'permisos'   => 'required|array',
            'permisos.*' => 'array',
            'permisos.*.id_empresa'   => 'required|integer|exists:ent_empresas,id',
            'permisos.*.id_sede'      => 'nullable|integer|exists:config_ubi_sede,id',
            'permisos.*.tipo_permiso' => 'required|in:visualizar,crear,editar,eliminar,publicar,cerrar',
        ]);

        try {
            $userId = $request->user_id;
            $permisosCreados = [];

            foreach ($request->permisos as $permisoData) {
                $permiso = CuadroTurnoPermiso::updateOrCreate(
                    [
                        'user_id'      => $userId,
                        'id_empresa'   => $permisoData['id_empresa'],
                        'id_sede'      => $permisoData['id_sede'] ?? null,
                        'tipo_permiso' => $permisoData['tipo_permiso'],
                    ],
                    [
                        'activo'         => true,
                        'creado_por'     => auth()->id(),
                        'actualizado_por' => auth()->id(),
                    ]
                );
                $permisosCreados[] = $permiso;
            }

            return response()->json([
                'success' => true,
                'message' => 'Permisos asignados exitosamente',
                'data'    => $permisosCreados,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al asignar permisos: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Verifica que el usuario tiene acceso a la empresa/sede
     */
    private function verificarAccesoUsuario(int $userId, int $empresaId, ?int $sedeId = null): void
    {
        $tieneAcceso = \DB::table('seg_empresa_user')
            ->where('user_id', $userId)
            ->where('empresa_id', $empresaId)
            ->when($sedeId, function ($q) use ($sedeId) {
                return $q->where(function ($subQ) use ($sedeId) {
                    $subQ->where('id_sede', $sedeId)
                         ->orWhereNull('id_sede');
                });
            })
            ->exists();

        if (!$tieneAcceso) {
            throw new \Exception('El usuario no tiene acceso a esta empresa/sede');
        }
    }
}
