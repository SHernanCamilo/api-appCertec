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
     * Nombre calificado de la tabla principal (evita ambigüedad en consultas con JOIN)
     */
    private const TABLA = 'matzobs_activos_c';

    /**
     * Aplicar el filtro de permisos del usuario sobre la consulta.
     * Si el usuario no tiene empresas asignadas, puede ver todos los activos.
     */
    private function aplicarFiltrosPermisos($query, $user): void
    {
        if (!$user->empresas || $user->empresas->count() === 0) {
            return;
        }

        $query->where(function ($q) use ($user) {
            foreach ($user->empresas as $empresa) {
                $pivot = $empresa->pivot;
                $empresaId = $empresa->id;
                $sucursalId = $pivot->id_sucursal ?? null;
                $sedeId = $pivot->id_sede ?? null;
                $recursivo = $pivot->recursivo ?? false;

                if ($recursivo && !$sucursalId) {
                    // Empresa completa: todas las sucursales y sedes
                    $q->orWhere(self::TABLA . '.id_empresa', $empresaId);
                } elseif ($recursivo && $sucursalId) {
                    // Sucursal completa: todas las sedes de esa sucursal
                    $q->orWhere(function ($sq) use ($empresaId, $sucursalId) {
                        $sq->where(self::TABLA . '.id_empresa', $empresaId)
                           ->where(self::TABLA . '.id_sucursal', $sucursalId);
                    });
                } else {
                    // Asignación específica
                    $q->orWhere(function ($sq) use ($empresaId, $sucursalId, $sedeId) {
                        $sq->where(self::TABLA . '.id_empresa', $empresaId);
                        if ($sucursalId) {
                            $sq->where(self::TABLA . '.id_sucursal', $sucursalId);
                        }
                        if ($sedeId) {
                            $sq->where(self::TABLA . '.id_sede', $sedeId);
                        }
                    });
                }
            }
        });
    }

    /**
     * Aplicar los filtros provenientes del request (empresa/sucursal/sede/agente/búsqueda).
     */
    private function aplicarFiltrosRequest($query, Request $request): void
    {
        if ($request->filled('empresa_id')) {
            $query->where(self::TABLA . '.id_empresa', $request->empresa_id);
        }

        if ($request->filled('sucursal_id')) {
            $query->where(self::TABLA . '.id_sucursal', $request->sucursal_id);
        }

        if ($request->filled('sede_id')) {
            $query->where(self::TABLA . '.id_sede', $request->sede_id);
        }

        if ($request->filled('agente')) {
            $query->where(self::TABLA . '.agente', 'like', '%' . $request->agente . '%');
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where(self::TABLA . '.nombre_equipo', 'like', "%{$search}%")
                  ->orWhere(self::TABLA . '.agente', 'like', "%{$search}%")
                  ->orWhere(self::TABLA . '.placa', 'like', "%{$search}%")
                  ->orWhere(self::TABLA . '.serial', 'like', "%{$search}%");
            });
        }
    }

    /**
     * Expresiones SQL de conteo condicional por estado de obsolescencia.
     * Umbrales: óptimo >= 100, funcional 60-100, potencializar 0-60, obsoleto null/0.
     */
    private function expresionesConteoPorEstado(string $col = 'puntaje'): string
    {
        return "
            COUNT(*) as total,
            SUM(CASE WHEN {$col} >= 100 THEN 1 ELSE 0 END) as optimo,
            SUM(CASE WHEN {$col} >= 60 AND {$col} < 100 THEN 1 ELSE 0 END) as funcional,
            SUM(CASE WHEN {$col} > 0 AND {$col} < 60 THEN 1 ELSE 0 END) as potencialmente,
            SUM(CASE WHEN {$col} IS NULL OR {$col} = 0 THEN 1 ELSE 0 END) as obsoleto
        ";
    }

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

            $this->aplicarFiltrosPermisos($query, $user);
            $this->aplicarFiltrosRequest($query, $request);

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
     * Datos agregados para el dashboard (conteos por estado, por tipo y por ubicación).
     * Reemplaza el cálculo en cliente que traía todos los registros (per_page=9999).
     */
    public function dashboard(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no autenticado'
                ], 401);
            }

            $user->load('empresas');

            // ── Conteo por estado de obsolescencia ──────────────────────────
            $estadoQuery = MatrizObsActivoC::query();
            $this->aplicarFiltrosPermisos($estadoQuery, $user);
            $this->aplicarFiltrosRequest($estadoQuery, $request);

            $estadoRow = $estadoQuery->selectRaw($this->expresionesConteoPorEstado(self::TABLA . '.puntaje'))->first();

            $estadisticasPorEstado = [
                'optimo'         => (int) ($estadoRow->optimo ?? 0),
                'funcional'      => (int) ($estadoRow->funcional ?? 0),
                'potencialmente' => (int) ($estadoRow->potencialmente ?? 0),
                'obsoleto'       => (int) ($estadoRow->obsoleto ?? 0),
            ];
            $totalActivos = (int) ($estadoRow->total ?? 0);

            // ── Conteo por tipo de equipo (detalle) ─────────────────────────
            $tipoQuery = MatrizObsActivoC::query()
                ->leftJoin('matzobs_activos_d', 'matzobs_activos_d.activo_c_id', '=', self::TABLA . '.id');
            $this->aplicarFiltrosPermisos($tipoQuery, $user);
            $this->aplicarFiltrosRequest($tipoQuery, $request);

            $estadisticasPorTipo = $tipoQuery
                ->selectRaw("COALESCE(NULLIF(matzobs_activos_d.tipo, ''), 'Sin tipo') as tipo, " . $this->expresionesConteoPorEstado(self::TABLA . '.puntaje'))
                ->groupBy('tipo')
                ->orderByDesc('total')
                ->get()
                ->map(fn ($row) => [
                    'tipo'           => $row->tipo,
                    'total'          => (int) $row->total,
                    'optimo'         => (int) $row->optimo,
                    'funcional'      => (int) $row->funcional,
                    'potencialmente' => (int) $row->potencialmente,
                    'obsoleto'       => (int) $row->obsoleto,
                ]);

            // ── Conteo por ubicación ────────────────────────────────────────
            // Sin empresa seleccionada: agrupar por empresa.
            // Con empresa seleccionada: agrupar por empresa + sucursal.
            $agruparPorEmpresa = !$request->filled('empresa_id');

            if ($agruparPorEmpresa) {
                $ubicacionExpr = "COALESCE(ent_empresas.nombre, 'Sin empresa')";
                $groupCols     = [self::TABLA . '.id_empresa'];
                $selectIds     = self::TABLA . '.id_empresa as empresa_id';
            } else {
                $ubicacionExpr = "CASE WHEN config_ubi_sucursales.nombre IS NOT NULL "
                    . "THEN CONCAT(COALESCE(ent_empresas.nombre, 'Sin empresa'), ' - ', config_ubi_sucursales.nombre) "
                    . "ELSE COALESCE(ent_empresas.nombre, 'Sin empresa') END";
                $groupCols     = [self::TABLA . '.id_empresa', self::TABLA . '.id_sucursal'];
                $selectIds     = self::TABLA . '.id_empresa as empresa_id, ' . self::TABLA . '.id_sucursal as sucursal_id';
            }

            $ubicacionQuery = MatrizObsActivoC::query()
                ->leftJoin('ent_empresas', 'ent_empresas.id', '=', self::TABLA . '.id_empresa')
                ->leftJoin('config_ubi_sucursales', 'config_ubi_sucursales.id', '=', self::TABLA . '.id_sucursal');
            $this->aplicarFiltrosPermisos($ubicacionQuery, $user);
            $this->aplicarFiltrosRequest($ubicacionQuery, $request);

            $estadisticasPorUbicacion = $ubicacionQuery
                ->selectRaw("{$selectIds}, {$ubicacionExpr} as ubicacion, " . $this->expresionesConteoPorEstado(self::TABLA . '.puntaje'))
                ->groupBy(array_merge($groupCols, [DB::raw($ubicacionExpr)]))
                ->orderByDesc('total')
                ->get()
                ->map(fn ($row) => [
                    'ubicacion'    => $row->ubicacion,
                    'empresa_id'   => $row->empresa_id !== null ? (int) $row->empresa_id : null,
                    'sucursal_id'  => (!$agruparPorEmpresa && isset($row->sucursal_id) && $row->sucursal_id !== null) ? (int) $row->sucursal_id : null,
                    'total'        => (int) $row->total,
                    'distribucion' => [
                        'optimo'         => (int) $row->optimo,
                        'funcional'      => (int) $row->funcional,
                        'potencialmente' => (int) $row->potencialmente,
                        'obsoleto'       => (int) $row->obsoleto,
                    ],
                ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'total_activos'              => $totalActivos,
                    'estadisticas_por_estado'    => $estadisticasPorEstado,
                    'estadisticas_por_tipo'      => $estadisticasPorTipo,
                    'estadisticas_por_ubicacion' => $estadisticasPorUbicacion,
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener los datos del dashboard',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Opciones para los dropdowns de filtros (empresa/sucursal/sede),
     * derivadas únicamente de los activos a los que el usuario tiene acceso.
     * Reemplaza la carga de todos los registros (per_page=9999) para llenar selects.
     *
     * Parámetros: tipo=empresa|sucursal|sede, empresa_id?, sucursal_id?
     */
    public function opcionesFiltros(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no autenticado'
                ], 401);
            }

            $user->load('empresas');

            $tipo = $request->get('tipo', 'empresa');

            $query = MatrizObsActivoC::query();
            $this->aplicarFiltrosPermisos($query, $user);

            if ($tipo === 'sucursal') {
                if ($request->filled('empresa_id')) {
                    $query->where(self::TABLA . '.id_empresa', $request->empresa_id);
                }
                $data = $query
                    ->join('config_ubi_sucursales', 'config_ubi_sucursales.id', '=', self::TABLA . '.id_sucursal')
                    ->whereNotNull(self::TABLA . '.id_sucursal')
                    ->distinct()
                    ->selectRaw(self::TABLA . '.id_sucursal as id, config_ubi_sucursales.nombre as nombre')
                    ->orderBy('nombre')
                    ->get();
            } elseif ($tipo === 'sede') {
                if ($request->filled('empresa_id')) {
                    $query->where(self::TABLA . '.id_empresa', $request->empresa_id);
                }
                if ($request->filled('sucursal_id')) {
                    $query->where(self::TABLA . '.id_sucursal', $request->sucursal_id);
                }
                $data = $query
                    ->join('config_ubi_sede', 'config_ubi_sede.id', '=', self::TABLA . '.id_sede')
                    ->whereNotNull(self::TABLA . '.id_sede')
                    ->distinct()
                    ->selectRaw(self::TABLA . '.id_sede as id, config_ubi_sede.nombre as nombre')
                    ->orderBy('nombre')
                    ->get();
            } else {
                $data = $query
                    ->join('ent_empresas', 'ent_empresas.id', '=', self::TABLA . '.id_empresa')
                    ->whereNotNull(self::TABLA . '.id_empresa')
                    ->distinct()
                    ->selectRaw(self::TABLA . '.id_empresa as id, ent_empresas.nombre as nombre')
                    ->orderBy('nombre')
                    ->get();
            }

            return response()->json([
                'success' => true,
                'data' => $data
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener las opciones de filtro',
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
                'detalle.proveedor' => 'nullable|string|max:255',
                'detalle.max_ram' => 'nullable|numeric|min:0'
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
                
                if (isset($validated['detalle']['max_ram'])) {
                    $detalle->max_ram = $validated['detalle']['max_ram'];
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
