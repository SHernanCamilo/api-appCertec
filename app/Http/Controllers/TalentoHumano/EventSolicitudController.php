<?php

namespace App\Http\Controllers\TalentoHumano;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TalentoHumano\EventSolicitudService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EventSolicitudController extends Controller
{
    public function __construct(
        private readonly EventSolicitudService $service
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $user = auth('api')->user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no autenticado',
                ], 401);
            }

            $paginado = $this->service->listar($request->all(), $user->id);
            return response()->json([
                'success'      => true,
                'data'         => $paginado->items(),
                'total'        => $paginado->total(),
                'current_page' => $paginado->currentPage(),
                'per_page'     => $paginado->perPage(),
                'last_page'    => $paginado->lastPage(),
            ]);
        } catch (\Exception $e) {
            return $this->error('Error al listar solicitudes', $e);
        }
    }

    public function unidadesFuncionales(Request $request): JsonResponse
    {
        try {
            $user = auth('api')->user();
            if (!$user instanceof User) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no autenticado',
                ], 401);
            }

            $user->load('empresas');

            $query = DB::table('config_unidades_funcionales as uf')
                ->select([
                    'uf.id',
                    'uf.codigo',
                    'uf.nombre',
                    'uf.id_empresa',
                    'uf.id_sucursal',
                    'uf.id_sede',
                ])
                ->where('uf.estado', 1);

            if ($request->filled('empresa_id')) {
                $query->where('uf.id_empresa', (int) $request->empresa_id);
            }

            if ($request->filled('search')) {
                $term = trim((string)$request->search);
                if (strlen($term) >= 2) {
                    $query->where(function ($q) use ($term) {
                        $q->where('uf.nombre', 'like', "%{$term}%")
                            ->orWhere('uf.codigo', 'like', "%{$term}%");
                    });
                }
            }

            // Si tiene empresas asignadas, aplicar permisos; si no tiene, acceso total.
            if ($user->empresas && $user->empresas->count() > 0) {
                $query->where(function ($q) use ($user) {
                    foreach ($user->empresas as $empresa) {
                        $pivot = $empresa->pivot;
                        $empresaId = $empresa->id;
                        $sucursalId = $pivot->id_sucursal ?? null;
                        $sedeId = $pivot->id_sede ?? null;
                        $recursivo = (bool)($pivot->recursivo ?? false);

                        if ($recursivo && !$sucursalId) {
                            // Empresa completa
                            $q->orWhere('uf.id_empresa', $empresaId);
                        } elseif ($recursivo && $sucursalId) {
                            // Sucursal completa (incluye sedes)
                            $q->orWhere(function ($sq) use ($empresaId, $sucursalId) {
                                $sq->where('uf.id_empresa', $empresaId)
                                    ->where('uf.id_sucursal', $sucursalId);
                            });
                        } else {
                            // Asignación específica
                            $q->orWhere(function ($sq) use ($empresaId, $sucursalId, $sedeId) {
                                $sq->where('uf.id_empresa', $empresaId);
                                if ($sedeId) {
                                    $sq->where('uf.id_sede', $sedeId);
                                } elseif ($sucursalId) {
                                    $sq->where('uf.id_sucursal', $sucursalId)
                                        ->whereNull('uf.id_sede');
                                } else {
                                    $sq->whereNull('uf.id_sucursal')
                                        ->whereNull('uf.id_sede');
                                }
                            });
                        }
                    }
                });
            }

            $limit = min(max((int)$request->input('limit', 100), 10), 500);
            $page = max((int)$request->input('page', 1), 1);
            $offset = ($page - 1) * $limit;

            $data = $query
                ->orderBy('uf.nombre')
                ->limit($limit)
                ->offset($offset)
                ->get();

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al cargar unidades funcionales',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function unidadesFuncionalesResponsable(Request $request): JsonResponse
    {
        try {
            $user = auth('api')->user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Usuario no autenticado'], 401);
            }

            $empresaId = $request->filled('empresa_id') ? (int)$request->empresa_id : null;
            $data = $this->service->unidadesFuncionalesPorResponsable($user->id, $empresaId);

            return response()->json(['success' => true, 'data' => $data]);
        } catch (\Exception $e) {
            return $this->error('Error al cargar unidades funcionales del responsable', $e);
        }
    }

    public function empleadosMiUnidad(Request $request): JsonResponse
    {
        try {
            $user = auth('api')->user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Usuario no autenticado'], 401);
            }

            $empresaId = $request->filled('empresa_id') ? (int)$request->empresa_id : null;
            $search = $request->filled('search') ? (string)$request->search : null;
            $limit = (int)$request->input('limit', 100);
            $page = (int)$request->input('page', 1);

            $data = $this->service->empleadosPorUnidadesResponsable($user->id, $empresaId, $search, $limit, $page);

            return response()->json(['success' => true, 'data' => $data]);
        } catch (\Exception $e) {
            return $this->error('Error al cargar empleados de sus unidades', $e);
        }
    }

    public function flujoPreview(Request $request): JsonResponse
    {
        try {
            $flujo = $this->service->previewFlujo($request->all());
            return response()->json(['success' => true, 'data' => $flujo]);
        } catch (\Exception $e) {
            return $this->error('Error al previsualizar el flujo', $e);
        }
    }

    public function catalogoFlujos(): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data' => $this->service->listarFlujosEventos(),
            ]);
        } catch (\Exception $e) {
            return $this->error('Error al cargar catálogo de flujos', $e);
        }
    }

    public function configuracionFlujoUnidad(Request $request): JsonResponse
    {
        $request->validate([
            'unidad_funcional_id' => 'required|integer',
        ]);

        try {
            $data = $this->service->obtenerConfiguracionFlujoUnidad((int) $request->unidad_funcional_id);
            return response()->json(['success' => true, 'data' => $data]);
        } catch (\Exception $e) {
            return $this->error('Error al cargar configuración de flujo por unidad', $e);
        }
    }

    public function guardarConfiguracionFlujoUnidad(Request $request): JsonResponse
    {
        $request->validate([
            'unidad_funcional_id' => 'required|integer',
            'flujo_id' => 'required|integer',
            'responsables' => 'array',
            'responsables.*.id_paso' => 'required|integer',
            'responsables.*.id_user' => 'required|integer',
        ]);

        try {
            $data = $this->service->guardarConfiguracionFlujoUnidad(
                (int) $request->unidad_funcional_id,
                (int) $request->flujo_id,
                $request->input('responsables', [])
            );

            return response()->json([
                'success' => true,
                'message' => 'Configuración de flujo guardada',
                'data' => $data,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            return $this->error('Error al guardar configuración de flujo', $e);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'empleado_id'  => 'required|integer',
            'fecha_inicial' => 'required',
            'fecha_final'   => 'required',
            'estado'        => 'nullable|integer|between:1,6',
        ]);

        try {
            $user = auth('api')->user();
            $solicitud = $this->service->crear($request->all(), $user->id);
            return response()->json([
                'success' => true,
                'message' => 'Solicitud creada correctamente',
                'data'    => $solicitud->load(['empleado', 'novedad']),
            ], 201);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            return $this->error('Error al crear la solicitud', $e);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'estado' => 'sometimes|integer|between:1,6',
        ]);

        try {
            $user = auth('api')->user();
            if (!$user || !$this->service->perteneceAlUsuario($id, $user->id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tiene permiso para modificar esta solicitud',
                ], 403);
            }

            $solicitud = $this->service->actualizar($id, $request->all(), $user->id);
            return response()->json([
                'success' => true,
                'message' => 'Solicitud actualizada',
                'data'    => $solicitud,
            ]);
        } catch (\Exception $e) {
            return $this->error('Error al actualizar la solicitud', $e);
        }
    }

    public function pendientes(Request $request): JsonResponse
    {
        try {
            $user = auth('api')->user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Usuario no autenticado'], 401);
            }

            $paginado = $this->service->listarPendientes($user->id, $request->all());
            return response()->json([
                'success'      => true,
                'data'         => $paginado->items(),
                'total'        => $paginado->total(),
                'current_page' => $paginado->currentPage(),
                'per_page'     => $paginado->perPage(),
                'last_page'    => $paginado->lastPage(),
            ]);
        } catch (\Exception $e) {
            return $this->error('Error al listar pendientes', $e);
        }
    }

    public function gestionados(Request $request): JsonResponse
    {
        try {
            $user = auth('api')->user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Usuario no autenticado'], 401);
            }

            $paginado = $this->service->listarGestionados($user->id, $request->all());
            return response()->json([
                'success'      => true,
                'data'         => $paginado->items(),
                'total'        => $paginado->total(),
                'current_page' => $paginado->currentPage(),
                'per_page'     => $paginado->perPage(),
                'last_page'    => $paginado->lastPage(),
            ]);
        } catch (\Exception $e) {
            return $this->error('Error al listar eventos gestionados', $e);
        }
    }

    public function aprobar(Request $request, int $id): JsonResponse
    {
        $request->validate(['comentario' => 'nullable|string']);

        try {
            $user = auth('api')->user();
            if (!$user || !$this->service->puedeAprobar($id, $user->id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tiene permiso para aprobar este evento',
                ], 403);
            }

            $solicitud = $this->service->aprobar($id, $user->id, $request->input('comentario'));
            return response()->json([
                'success' => true,
                'message' => 'Evento aprobado',
                'data'    => $solicitud,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            return $this->error('Error al aprobar el evento', $e);
        }
    }

    public function rechazar(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'id_motivo_rechazo' => 'required|integer|exists:config_mot_rechazo,id',
            'comentario'        => 'nullable|string|max:500',
        ]);

        try {
            $user = auth('api')->user();
            if (!$user || !$this->service->puedeAprobar($id, $user->id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tiene permiso para rechazar este evento',
                ], 403);
            }

            $solicitud = $this->service->rechazar(
                $id,
                $user->id,
                (int) $request->input('id_motivo_rechazo'),
                $request->input('comentario')
            );
            return response()->json([
                'success' => true,
                'message' => 'Evento rechazado',
                'data'    => $solicitud,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            return $this->error('Error al rechazar el evento', $e);
        }
    }

    public function motivosRechazo(): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data'    => $this->service->listarMotivosRechazo(),
            ]);
        } catch (\Exception $e) {
            return $this->error('Error al listar motivos de rechazo', $e);
        }
    }

    public function historial(int $id): JsonResponse
    {
        try {
            $data = $this->service->historial($id);
            return response()->json(['success' => true, 'data' => $data]);
        } catch (\Exception $e) {
            return $this->error('Error al obtener el historial', $e);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            $user = auth('api')->user();
            if (!$user || !$this->service->perteneceAlUsuario($id, $user->id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tiene permiso para eliminar esta solicitud',
                ], 403);
            }

            $this->service->eliminar($id);
            return response()->json(['success' => true, 'message' => 'Solicitud eliminada']);
        } catch (\Exception $e) {
            return $this->error('Error al eliminar la solicitud', $e);
        }
    }

    private function error(string $message, \Exception $e, int $status = 500): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error'   => config('app.debug') ? $e->getMessage() : null,
        ], $status);
    }
}
