<?php

namespace App\Http\Controllers;

use App\Models\MatrizObsGrupoParametro;
use App\Models\MatrizObsParametro;
use App\Services\MatrizObsolescenciaCalculatorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MatrizObsParametroController extends Controller
{
    /**
     * Obtener todos los grupos con sus parámetros
     */
    public function index()
    {
        try {
            $grupos = MatrizObsGrupoParametro::with('parametros')->get();
            
            return response()->json([
                'success' => true,
                'data' => $grupos
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener los parámetros',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener un grupo específico con sus parámetros
     */
    public function getGrupo($id)
    {
        try {
            $grupo = MatrizObsGrupoParametro::with('parametros')->findOrFail($id);
            
            return response()->json([
                'success' => true,
                'data' => $grupo
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Grupo no encontrado',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Crear un nuevo grupo de parámetros
     */
    public function storeGrupo(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nombre' => 'required|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $grupo = MatrizObsGrupoParametro::create($request->all());
            
            return response()->json([
                'success' => true,
                'message' => 'Grupo creado exitosamente',
                'data' => $grupo
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear el grupo',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Crear un nuevo parámetro
     */
    public function storeParametro(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id_grupo' => 'required|exists:matzobs_grupo_parametros,id',
            'nombre' => 'required|string|max:150',
            'valor' => 'nullable|string|max:100',
            'frecuencia' => 'nullable|string|max:100',
            'rango_i' => 'nullable|numeric',
            'rango_f' => 'nullable|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $parametro = MatrizObsParametro::create($request->all());
            
            return response()->json([
                'success' => true,
                'message' => 'Parámetro creado exitosamente',
                'data' => $parametro
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear el parámetro',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar un parámetro
     */
    public function updateParametro(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'nombre' => 'sometimes|required|string|max:150',
            'valor' => 'nullable|string|max:100',
            'frecuencia' => 'nullable|string|max:100',
            'rango_i' => 'nullable|numeric',
            'rango_f' => 'nullable|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $parametro = MatrizObsParametro::findOrFail($id);
            $parametro->update($request->all());
            
            return response()->json([
                'success' => true,
                'message' => 'Parámetro actualizado exitosamente',
                'data' => $parametro
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar el parámetro',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar un parámetro
     */
    public function deleteParametro($id)
    {
        try {
            $parametro = MatrizObsParametro::findOrFail($id);
            $parametro->delete();
            
            return response()->json([
                'success' => true,
                'message' => 'Parámetro eliminado exitosamente'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar el parámetro',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener parámetros por grupo
     */
    public function getParametrosByGrupo($grupoId)
    {
        try {
            $parametros = MatrizObsParametro::where('id_grupo', $grupoId)->get();
            
            return response()->json([
                'success' => true,
                'data' => $parametros
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener los parámetros',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Aplicar filtros de permisos del usuario a una query
     */
    private function aplicarFiltrosPermisos($query)
    {
        $user = auth()->user();
        
        if (!$user) {
            return $query;
        }

        // Cargar empresas del usuario con pivot
        $user->load('empresas');

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
                        $q->orWhere('ac.id_empresa', $empresaId);
                    } elseif ($recursivo && $sucursalId) {
                        // Sucursal completa: todas las sedes de esa sucursal
                        $q->orWhere(function($sq) use ($empresaId, $sucursalId) {
                            $sq->where('ac.id_empresa', $empresaId)
                               ->where('ac.id_sucursal', $sucursalId);
                        });
                    } else {
                        // Asignación específica
                        $q->orWhere(function($sq) use ($empresaId, $sucursalId, $sedeId) {
                            $sq->where('ac.id_empresa', $empresaId);
                            if ($sucursalId) {
                                $sq->where('ac.id_sucursal', $sucursalId);
                            }
                            if ($sedeId) {
                                $sq->where('ac.id_sede', $sedeId);
                            }
                        });
                    }
                }
            });
        }
        // Si no tiene empresas asignadas, puede ver todos los activos

        return $query;
    }

    /**
     * Obtener estadísticas por tipo de equipo para el gráfico circular
     */
    public function getEstadisticasPorTipo()
    {
        try {
            // Obtener estadísticas agrupadas por tipo de equipo
            $query = \DB::table('matzobs_activos_c as ac')
                ->join('matzobs_activos_d as ad', 'ac.id', '=', 'ad.activo_c_id')
                ->select(
                    'ad.tipo',
                    \DB::raw('COUNT(*) as total'),
                    \DB::raw('AVG(ac.puntaje) as promedio_puntaje'),
                    \DB::raw('SUM(CASE WHEN ac.puntaje >= 80 THEN 1 ELSE 0 END) as optimo'),
                    \DB::raw('SUM(CASE WHEN ac.puntaje >= 60 AND ac.puntaje < 80 THEN 1 ELSE 0 END) as funcional'),
                    \DB::raw('SUM(CASE WHEN ac.puntaje >= 40 AND ac.puntaje < 60 THEN 1 ELSE 0 END) as potencial'),
                    \DB::raw('SUM(CASE WHEN ac.puntaje < 40 THEN 1 ELSE 0 END) as obsoleto')
                )
                ->whereNotNull('ad.tipo');

            // Aplicar filtros de permisos
            $query = $this->aplicarFiltrosPermisos($query);

            $estadisticas = $query->groupBy('ad.tipo')
                ->orderBy('total', 'desc')
                ->get();

            // Calcular totales generales
            $totalGeneral = $estadisticas->sum('total');
            $promedioGeneral = $estadisticas->avg('promedio_puntaje');

            // Formatear datos para el gráfico
            $datosGrafico = $estadisticas->map(function($item) use ($totalGeneral) {
                return [
                    'tipo' => $item->tipo,
                    'total' => $item->total,
                    'porcentaje' => $totalGeneral > 0 ? round(($item->total / $totalGeneral) * 100, 2) : 0,
                    'promedio_puntaje' => round($item->promedio_puntaje, 2),
                    'distribucion' => [
                        'optimo' => $item->optimo,
                        'funcional' => $item->funcional,
                        'potencial' => $item->potencial,
                        'obsoleto' => $item->obsoleto
                    ]
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'tipos' => $datosGrafico,
                    'resumen' => [
                        'total_equipos' => $totalGeneral,
                        'promedio_general' => round($promedioGeneral, 2),
                        'tipos_diferentes' => $estadisticas->count()
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener estadísticas por tipo',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener estadísticas por ubicación (empresa/sucursal/sede) segregadas por concepto
     */
    public function getEstadisticasPorUbicacion()
    {
        try {
            // Obtener estadísticas agrupadas por empresa, sucursal y sede
            $query = \DB::table('matzobs_activos_c as ac')
                ->leftJoin('ent_empresas as e', 'ac.id_empresa', '=', 'e.id')
                ->leftJoin('config_ubi_sucursales as s', 'ac.id_sucursal', '=', 's.id')
                ->leftJoin('config_ubi_sede as se', 'ac.id_sede', '=', 'se.id')
                ->select(
                    'e.nombre as empresa_nombre',
                    's.nombre as sucursal_nombre',
                    'se.nombre as sede_nombre',
                    'ac.id_empresa',
                    'ac.id_sucursal',
                    'ac.id_sede',
                    \DB::raw('COUNT(*) as total'),
                    \DB::raw('SUM(CASE WHEN ac.puntaje >= 80 THEN 1 ELSE 0 END) as optimo'),
                    \DB::raw('SUM(CASE WHEN ac.puntaje >= 60 AND ac.puntaje < 80 THEN 1 ELSE 0 END) as funcional'),
                    \DB::raw('SUM(CASE WHEN ac.puntaje >= 40 AND ac.puntaje < 60 THEN 1 ELSE 0 END) as potencial'),
                    \DB::raw('SUM(CASE WHEN ac.puntaje < 40 THEN 1 ELSE 0 END) as obsoleto'),
                    \DB::raw('AVG(ac.puntaje) as promedio_puntaje')
                )
                ->whereNotNull('ac.puntaje'); // Asegurar que tenga puntaje calculado

            // Aplicar filtros de permisos
            $query = $this->aplicarFiltrosPermisos($query);

            $estadisticas = $query->groupBy('ac.id_empresa', 'ac.id_sucursal', 'ac.id_sede', 'e.nombre', 's.nombre', 'se.nombre')
                ->orderBy('total', 'desc')
                ->limit(15) // Aumentar límite para ver más ubicaciones
                ->get();

            \Log::info('Estadísticas por ubicación - Query result:', [
                'count' => $estadisticas->count(),
                'data' => $estadisticas->toArray()
            ]);

            // Formatear datos para el gráfico
            $datosGrafico = $estadisticas->map(function($item) {
                // Determinar el nombre de la ubicación con prioridad: sede > sucursal > empresa
                $ubicacion = $item->empresa_nombre ?: 'Sin empresa';
                
                if ($item->sucursal_nombre) {
                    $ubicacion = $item->sucursal_nombre;
                }
                
                if ($item->sede_nombre) {
                    $ubicacion = $item->sede_nombre;
                }

                return [
                    'ubicacion' => $ubicacion,
                    'empresa' => $item->empresa_nombre,
                    'sucursal' => $item->sucursal_nombre,
                    'sede' => $item->sede_nombre,
                    'total' => (int)$item->total,
                    'promedio_puntaje' => round($item->promedio_puntaje, 2),
                    'distribucion' => [
                        'optimo' => (int)$item->optimo,
                        'funcional' => (int)$item->funcional,
                        'potencial' => (int)$item->potencial,
                        'obsoleto' => (int)$item->obsoleto
                    ],
                    'porcentajes' => [
                        'optimo' => $item->total > 0 ? round(($item->optimo / $item->total) * 100, 1) : 0,
                        'funcional' => $item->total > 0 ? round(($item->funcional / $item->total) * 100, 1) : 0,
                        'potencial' => $item->total > 0 ? round(($item->potencial / $item->total) * 100, 1) : 0,
                        'obsoleto' => $item->total > 0 ? round(($item->obsoleto / $item->total) * 100, 1) : 0
                    ]
                ];
            });

            // Calcular totales generales
            $totalGeneral = $estadisticas->sum('total');

            \Log::info('Estadísticas por ubicación - Datos procesados:', [
                'ubicaciones_count' => $datosGrafico->count(),
                'total_general' => $totalGeneral,
                'ubicaciones' => $datosGrafico->toArray()
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'ubicaciones' => $datosGrafico,
                    'resumen' => [
                        'total_equipos' => $totalGeneral,
                        'ubicaciones_mostradas' => $datosGrafico->count(),
                        'promedio_general' => $estadisticas->avg('promedio_puntaje') ? round($estadisticas->avg('promedio_puntaje'), 2) : 0
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Error en getEstadisticasPorUbicacion:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener estadísticas por ubicación',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener equipos filtrados por tipo o ubicación
     */
    public function getEquiposPorFiltro(Request $request)
    {
        try {
            $tipo = $request->input('tipo');
            $ubicacion = $request->input('ubicacion');
            $empresaId = $request->input('empresa_id');
            $sucursalId = $request->input('sucursal_id');
            $sedeId = $request->input('sede_id');
            $page = $request->input('page', 1);
            $perPage = $request->input('per_page', 10);

            \Log::info('getEquiposPorFiltro - Parámetros recibidos:', [
                'tipo' => $tipo,
                'ubicacion' => $ubicacion,
                'empresa_id' => $empresaId,
                'sucursal_id' => $sucursalId,
                'sede_id' => $sedeId
            ]);

            $query = \DB::table('matzobs_activos_c as ac')
                ->leftJoin('matzobs_activos_d as ad', 'ac.id', '=', 'ad.activo_c_id')
                ->leftJoin('ent_empresas as e', 'ac.id_empresa', '=', 'e.id')
                ->leftJoin('config_ubi_sucursales as s', 'ac.id_sucursal', '=', 's.id')
                ->leftJoin('config_ubi_sede as se', 'ac.id_sede', '=', 'se.id')
                ->select(
                    'ac.*',
                    'ad.tipo',
                    'ad.marca',
                    'ad.referencia',
                    'ad.tamano_ram',
                    'ad.procesador',
                    'ad.tipo_disco',
                    'ad.tamano_disco',
                    'e.nombre as empresa_nombre',
                    's.nombre as sucursal_nombre',
                    'se.nombre as sede_nombre'
                );

            // Aplicar filtros de permisos
            $query = $this->aplicarFiltrosPermisos($query);

            // Filtrar por tipo de equipo
            if ($tipo) {
                $query->where('ad.tipo', $tipo);
            }

            // Filtrar por ubicación (empresa/sucursal/sede)
            // La ubicación puede venir como "Empresa - Sucursal" o solo "Empresa" o "Sucursal"
            if ($ubicacion) {
                // Separar la ubicación si contiene " - "
                $partes = explode(' - ', $ubicacion);
                
                if (count($partes) >= 2) {
                    // Si tiene formato "Empresa - Sucursal", buscar por ambas
                    $empresaNombre = trim($partes[0]);
                    $sucursalNombre = trim($partes[1]);
                    
                    $query->where(function($q) use ($empresaNombre, $sucursalNombre) {
                        $q->where(function($subQ) use ($empresaNombre, $sucursalNombre) {
                            $subQ->where('e.nombre', 'LIKE', '%' . $empresaNombre . '%')
                                 ->where('s.nombre', 'LIKE', '%' . $sucursalNombre . '%');
                        })
                        ->orWhere(function($subQ) use ($empresaNombre, $sucursalNombre) {
                            // También buscar si la sucursal contiene ambos nombres
                            $subQ->where('s.nombre', 'LIKE', '%' . $empresaNombre . '%')
                                 ->where('s.nombre', 'LIKE', '%' . $sucursalNombre . '%');
                        });
                    });
                } else {
                    // Si es un solo nombre, buscar en empresa, sucursal o sede
                    $query->where(function($q) use ($ubicacion) {
                        $q->where('e.nombre', 'LIKE', '%' . $ubicacion . '%')
                          ->orWhere('s.nombre', 'LIKE', '%' . $ubicacion . '%')
                          ->orWhere('se.nombre', 'LIKE', '%' . $ubicacion . '%');
                    });
                }
            }

            // Filtros adicionales
            if ($empresaId) {
                $query->where('ac.id_empresa', $empresaId);
            }
            if ($sucursalId) {
                $query->where('ac.id_sucursal', $sucursalId);
            }
            if ($sedeId) {
                $query->where('ac.id_sede', $sedeId);
            }

            // Paginación
            $total = $query->count();
            
            \Log::info('getEquiposPorFiltro - Total encontrado:', ['total' => $total]);
            
            $equipos = $query->orderBy('ac.puntaje', 'desc')
                ->skip(($page - 1) * $perPage)
                ->take($perPage)
                ->get();

            \Log::info('getEquiposPorFiltro - Equipos recuperados:', ['count' => $equipos->count()]);

            return response()->json([
                'success' => true,
                'data' => $equipos,
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => ceil($total / $perPage)
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Error en getEquiposPorFiltro:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener equipos filtrados',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Ejecutar cálculos automáticos para la matriz de obsolescencia
     */
    public function ejecutarCalculos(Request $request, MatrizObsolescenciaCalculatorService $calculatorService)
    {
        $validator = Validator::make($request->all(), [
            'activo_id' => 'nullable|integer|exists:matzobs_activos_c,id',
            'batch_size' => 'nullable|integer|min:1|max:100',
            'force' => 'nullable|boolean',
            'solo_nuevos' => 'nullable|boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $activoId = $request->input('activo_id');
            $batchSize = $request->input('batch_size', 50);
            $force = $request->input('force', false);
            $soloNuevos = $request->input('solo_nuevos', false);

            if ($activoId) {
                // Calcular para un activo específico
                $resultado = $calculatorService->calcularValoresActivo($activoId);
                
                if ($resultado) {
                    return response()->json([
                        'success' => true,
                        'message' => 'Cálculos ejecutados exitosamente para el activo específico',
                        'data' => [
                            'activo_id' => $activoId,
                            'calculado' => true
                        ]
                    ], 200);
                } else {
                    return response()->json([
                        'success' => false,
                        'message' => 'Error ejecutando cálculos para el activo específico'
                    ], 500);
                }
            } else {
                // Calcular para múltiples activos
                $activoIds = null;
                
                if ($soloNuevos && !$force) {
                    // Solo activos que no tienen valores calculados
                    $activoIds = \App\Models\MatrizObsolescencia\MatzobsActivosC::whereHas('detalles', function($q) {
                        $q->where(function($subQ) {
                            $subQ->whereNull('edad')
                                 ->orWhereNull('valoracion_edad')
                                 ->orWhereNull('valoracion_ram')
                                 ->orWhereNull('valoracion_procesador')
                                 ->orWhereNull('valoracion_disco');
                        });
                    })->pluck('id')->toArray();
                }
                
                $resultado = $calculatorService->calcularValoresLote($activoIds, $batchSize);
                
                return response()->json([
                    'success' => true,
                    'message' => 'Cálculos ejecutados exitosamente',
                    'data' => $resultado
                ], 200);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error ejecutando cálculos automáticos',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
