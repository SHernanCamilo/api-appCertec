<?php

namespace App\Http\Controllers;

use App\Models\MatrizObsActivoC;
use App\Models\MatrizObsActivoD;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class MatrizObsActivoController extends Controller
{
    /**
     * Obtener activos según permisos del usuario
     * Si el usuario tiene empresas asignadas, filtra por esas empresas
     * Si tiene recursivo=true, incluye sucursales y sedes
     */
    public function getActivosPorPermisos(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no autenticado'
                ], 401);
            }

            // Cargar empresas del usuario con pivot
            $user->load('empresas');
            
            $query = MatrizObsActivoC::with(['detalle', 'empresa', 'sucursal', 'sede']);

            // Si el usuario tiene empresas asignadas, filtrar por permisos
            if ($user->empresas && $user->empresas->count() > 0) {
                $query->where(function($q) use ($user) {
                    foreach ($user->empresas as $empresa) {
                        $pivot = $empresa->pivot;
                        $empresaId = $empresa->id;
                        $sucursalId = $pivot->id_sucursal ?? null;
                        $sedeId = $pivot->id_sede ?? null;
                        $recursivo = $pivot->recursivo ?? false;

                        if ($recursivo && !$sucursalId) {
                            // Empresa completa: todas las sucursales y sedes
                            $q->orWhere('id_empresa', $empresaId);
                        } elseif ($recursivo && $sucursalId) {
                            // Sucursal completa: todas las sedes de esa sucursal
                            $q->orWhere(function($sq) use ($empresaId, $sucursalId) {
                                $sq->where('id_empresa', $empresaId)
                                   ->where('id_sucursal', $sucursalId);
                            });
                        } else {
                            // Asignación específica
                            $q->orWhere(function($sq) use ($empresaId, $sucursalId, $sedeId) {
                                $sq->where('id_empresa', $empresaId);
                                if ($sucursalId) {
                                    $sq->where('id_sucursal', $sucursalId);
                                }
                                if ($sedeId) {
                                    $sq->where('id_sede', $sedeId);
                                }
                            });
                        }
                    }
                });
            }
            // Si no tiene empresas asignadas, puede ver todos los activos

            // Aplicar filtros adicionales
            if ($request->has('empresa_id')) {
                $query->where('id_empresa', $request->empresa_id);
            }

            if ($request->has('sucursal_id')) {
                $query->where('id_sucursal', $request->sucursal_id);
            }

            if ($request->has('sede_id')) {
                $query->where('id_sede', $request->sede_id);
            }

            if ($request->has('agente')) {
                $query->where('agente', 'like', '%' . $request->agente . '%');
            }

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('nombre_equipo', 'like', "%{$search}%")
                      ->orWhere('agente', 'like', "%{$search}%")
                      ->orWhere('placa', 'like', "%{$search}%")
                      ->orWhere('serial', 'like', "%{$search}%");
                });
            }

            // Paginación
            $perPage = $request->get('per_page', 10);
            $activos = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $activos->items(),
                'total' => $activos->total(),
                'per_page' => $activos->perPage(),
                'current_page' => $activos->currentPage(),
                'last_page' => $activos->lastPage()
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener los activos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener todos los activos (solo admin)
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = MatrizObsActivoC::with(['detalle', 'empresa', 'sucursal', 'sede']);

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('nombre_equipo', 'like', "%{$search}%")
                      ->orWhere('agente', 'like', "%{$search}%")
                      ->orWhere('placa', 'like', "%{$search}%")
                      ->orWhere('serial', 'like', "%{$search}%");
                });
            }

            $perPage = $request->get('per_page', 10);
            $activos = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $activos->items(),
                'total' => $activos->total(),
                'per_page' => $activos->perPage(),
                'current_page' => $activos->currentPage(),
                'last_page' => $activos->lastPage()
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener los activos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener un activo específico
     */
    public function show(string $id): JsonResponse
    {
        try {
            $activo = MatrizObsActivoC::with(['detalle', 'empresa', 'sucursal', 'sede'])
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $activo
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Activo no encontrado',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Obtener estadísticas de activos
     */
    public function getEstadisticas(): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no autenticado'
                ], 401);
            }

            $query = MatrizObsActivoC::query();

            // Aplicar filtros de permisos si el usuario tiene empresas asignadas
            if ($user->empresas && $user->empresas->count() > 0) {
                $query->where(function($q) use ($user) {
                    foreach ($user->empresas as $empresa) {
                        $pivot = $empresa->pivot;
                        $empresaId = $empresa->id;
                        $sucursalId = $pivot->id_sucursal ?? null;
                        $recursivo = $pivot->recursivo ?? false;

                        if ($recursivo && !$sucursalId) {
                            $q->orWhere('id_empresa', $empresaId);
                        } elseif ($recursivo && $sucursalId) {
                            $q->orWhere(function($sq) use ($empresaId, $sucursalId) {
                                $sq->where('id_empresa', $empresaId)
                                   ->where('id_sucursal', $sucursalId);
                            });
                        } else {
                            $q->orWhere(function($sq) use ($empresaId, $sucursalId, $pivot) {
                                $sq->where('id_empresa', $empresaId);
                                if ($sucursalId) {
                                    $sq->where('id_sucursal', $sucursalId);
                                }
                                if ($pivot->id_sede) {
                                    $sq->where('id_sede', $pivot->id_sede);
                                }
                            });
                        }
                    }
                });
            }

            $totalActivos = $query->count();
            $activosRecientes = (clone $query)->where('created_at', '>=', now()->subDays(30))->count();
            $promedioObsolescencia = (clone $query)->avg('puntaje') ?? 0;
            
            // Contar empresas únicas con acceso
            $empresasConAcceso = $user->empresas && $user->empresas->count() > 0 
                ? $user->empresas->count() 
                : DB::table('ent_empresas')->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'total_activos' => $totalActivos,
                    'activos_recientes' => $activosRecientes,
                    'empresas_con_acceso' => $empresasConAcceso,
                    'promedio_obsolescencia' => round($promedioObsolescencia, 2)
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener estadísticas',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener activos por empresa específica
     */
    public function getActivosPorEmpresa(string $empresaId, Request $request): JsonResponse
    {
        try {
            $query = MatrizObsActivoC::with(['detalle', 'empresa', 'sucursal', 'sede'])
                ->where('id_empresa', $empresaId);

            if ($request->has('sucursal_id')) {
                $query->where('id_sucursal', $request->sucursal_id);
            }

            if ($request->has('sede_id')) {
                $query->where('id_sede', $request->sede_id);
            }

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('nombre_equipo', 'like', "%{$search}%")
                      ->orWhere('agente', 'like', "%{$search}%")
                      ->orWhere('placa', 'like', "%{$search}%")
                      ->orWhere('serial', 'like', "%{$search}%");
                });
            }

            $perPage = $request->get('per_page', 10);
            $activos = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $activos->items(),
                'total' => $activos->total(),
                'per_page' => $activos->perPage(),
                'current_page' => $activos->currentPage(),
                'last_page' => $activos->lastPage()
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener los activos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener activos por sucursal específica
     */
    public function getActivosPorSucursal(string $sucursalId, Request $request): JsonResponse
    {
        try {
            $query = MatrizObsActivoC::with(['detalle', 'empresa', 'sucursal', 'sede'])
                ->where('id_sucursal', $sucursalId);

            if ($request->has('sede_id')) {
                $query->where('id_sede', $request->sede_id);
            }

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('nombre_equipo', 'like', "%{$search}%")
                      ->orWhere('agente', 'like', "%{$search}%")
                      ->orWhere('placa', 'like', "%{$search}%")
                      ->orWhere('serial', 'like', "%{$search}%");
                });
            }

            $perPage = $request->get('per_page', 10);
            $activos = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $activos->items(),
                'total' => $activos->total(),
                'per_page' => $activos->perPage(),
                'current_page' => $activos->currentPage(),
                'last_page' => $activos->lastPage()
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener los activos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener activos por sede específica
     */
    public function getActivosPorSede(string $sedeId, Request $request): JsonResponse
    {
        try {
            $query = MatrizObsActivoC::with(['detalle', 'empresa', 'sucursal', 'sede'])
                ->where('id_sede', $sedeId);

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('nombre_equipo', 'like', "%{$search}%")
                      ->orWhere('agente', 'like', "%{$search}%")
                      ->orWhere('placa', 'like', "%{$search}%")
                      ->orWhere('serial', 'like', "%{$search}%");
                });
            }

            $perPage = $request->get('per_page', 10);
            $activos = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $activos->items(),
                'total' => $activos->total(),
                'per_page' => $activos->perPage(),
                'current_page' => $activos->currentPage(),
                'last_page' => $activos->lastPage()
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener los activos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Exportar activos a Excel según permisos
     */
    public function exportarActivos(Request $request): JsonResponse
    {
        try {
            // TODO: Implementar exportación a Excel
            return response()->json([
                'success' => false,
                'message' => 'Funcionalidad de exportación en desarrollo'
            ], 501);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al exportar activos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Exportar estadísticas a PDF
     */
    public function exportarEstadisticas(): JsonResponse
    {
        try {
            // TODO: Implementar exportación a PDF
            return response()->json([
                'success' => false,
                'message' => 'Funcionalidad de exportación en desarrollo'
            ], 501);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al exportar estadísticas',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar campos editables de un activo
     */
    public function update(Request $request, string $id): JsonResponse
    {
        try {
            // Validar datos
            $validated = $request->validate([
                'ubicacion' => 'nullable|string|max:255',
                'detalle' => 'nullable|array',
                'detalle.tipo_unidad' => 'nullable|string|max:255',
                'detalle.fecha_compra' => 'nullable|date',
                'detalle.modalidad' => 'nullable|string|max:255',
                'detalle.proveedor' => 'nullable|string|max:255'
            ]);

            // Buscar el activo
            $activo = MatrizObsActivoC::findOrFail($id);

            // Actualizar campos del activo principal
            if (isset($validated['ubicacion'])) {
                $activo->ubicacion = $validated['ubicacion'];
            }

            $activo->save();

            // Actualizar campos del detalle si existen
            if (isset($validated['detalle']) && $activo->detalle) {
                $detalle = $activo->detalle;
                
                if (isset($validated['detalle']['tipo_unidad'])) {
                    $detalle->tipo_unidad = $validated['detalle']['tipo_unidad'];
                }
                
                if (isset($validated['detalle']['fecha_compra'])) {
                    $detalle->fecha_compra = $validated['detalle']['fecha_compra'];
                }
                
                if (isset($validated['detalle']['modalidad'])) {
                    $detalle->modalidad = $validated['detalle']['modalidad'];
                }
                
                if (isset($validated['detalle']['proveedor'])) {
                    $detalle->proveedor = $validated['detalle']['proveedor'];
                }
                
                $detalle->save();
            }

            // Recargar el activo con sus relaciones
            $activo->load(['detalle', 'empresa', 'sucursal', 'sede']);

            return response()->json([
                'success' => true,
                'message' => 'Activo actualizado correctamente',
                'data' => $activo
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Activo no encontrado'
            ], 404);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar el activo',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
