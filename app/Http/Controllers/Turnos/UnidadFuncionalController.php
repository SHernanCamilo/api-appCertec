<?php

namespace App\Http\Controllers\Turnos;

use App\Http\Controllers\Controller;
use App\Models\Turnos\ConfigUnidadFuncional;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class UnidadFuncionalController extends Controller
{
    /**
     * GET /api/turnos/unidades-funcionales
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = ConfigUnidadFuncional::with(['empresa', 'sucursal', 'sede']);

            if ($request->filled('id_empresa')) {
                $query->porEmpresa((int) $request->id_empresa);
            }

            if ($request->filled('id_sucursal')) {
                $query->where('id_sucursal', (int) $request->id_sucursal);
            }

            if ($request->filled('id_sede')) {
                $query->porSede((int) $request->id_sede);
            }

            if ($request->filled('estado')) {
                $query->where('estado', filter_var($request->estado, FILTER_VALIDATE_BOOLEAN));
            } else {
                $query->activas();
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('codigo', 'LIKE', "%{$search}%")
                      ->orWhere('nombre', 'LIKE', "%{$search}%");
                });
            }

            $unidades = $query->orderBy('nombre')->get();

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
     * GET /api/turnos/unidades-funcionales/del-usuario
     * 
     * Retorna unidades según el rol del usuario (4-tier access control):
     * 1. SUPER_ADMIN: Todas las unidades activas
     * 2. TRANSVERSAL: Todas las unidades activas
     * 3. EMPRESA_ADMIN: Solo unidades de su(s) empresa(s) asignada(s)
     * 4. USUARIO_NORMAL: Solo sus unidades funcionales asignadas
     */
    public function delUsuario(): JsonResponse
    {
        try {
            $user = auth()->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no autenticado',
                ], 401);
            }

            \Log::info('🔐 delUsuario() - Usuario actual', [
                'user_id' => $user->id,
                'user_email' => $user->email,
            ]);

            // Usar el servicio de control de acceso
            $accessControl = new \App\Services\Turnos\AccessControlService($user);
            $unidades = $accessControl->getUnidades();

            \Log::info('✅ delUsuario() - Unidades obtenidas', [
                'user_id' => $user->id,
                'access_level' => $accessControl->getAccessLevel(),
                'total_unidades' => $unidades->count(),
                'primer_unidad' => $unidades->first() ? [
                    'id' => $unidades->first()->id,
                    'nombre' => $unidades->first()->nombre,
                    'id_empresa' => $unidades->first()->id_empresa,
                    'id_sede' => $unidades->first()->id_sede,
                    'empresa' => $unidades->first()->empresa,
                    'sede' => $unidades->first()->sede,
                ] : null,
            ]);

            // Transformar para asegurar que empresa, sucursal y sede se incluyen
            $unidadesFormateadas = $unidades->map(function($unidad) {
                return [
                    'id' => $unidad->id,
                    'codigo' => $unidad->codigo,
                    'nombre' => $unidad->nombre,
                    'id_empresa' => $unidad->id_empresa,
                    'id_sucursal' => $unidad->id_sucursal,
                    'id_sede' => $unidad->id_sede,
                    'estado' => $unidad->estado,
                    'empresa' => $unidad->empresa ? [
                        'id' => $unidad->empresa->id,
                        'nombre' => $unidad->empresa->nombre,
                    ] : null,
                    'sucursal' => $unidad->sucursal ? [
                        'id' => $unidad->sucursal->id,
                        'nombre' => $unidad->sucursal->nombre,
                    ] : null,
                    'sede' => $unidad->sede ? [
                        'id' => $unidad->sede->id,
                        'nombre' => $unidad->sede->nombre,
                    ] : null,
                ];
            })->values();

            return response()->json([
                'success' => true,
                'data' => $unidadesFormateadas,
                'user_id' => $user->id,
                'access_level' => $accessControl->getAccessLevel(),
                'debug' => config('app.debug') ? $accessControl->getDebugInfo() : null,
            ]);

        } catch (\Exception $e) {
            \Log::error('❌ Error en delUsuario:', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener unidades: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'codigo'     => 'required|string|max:50|unique:config_unidades_funcionales,codigo',
            'nombre'     => 'required|string|max:150',
            'id_empresa' => 'required|integer|exists:ent_empresas,id',
            'id_sede'    => 'nullable|integer|exists:config_ubi_sede,id',
            'estado'     => 'boolean',
        ]);

        try {
            $unidad = ConfigUnidadFuncional::create($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Unidad funcional creada exitosamente.',
                'data'    => $unidad->load(['empresa', 'sede']),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear unidad funcional: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function show(int $id): JsonResponse
    {
        try {
            $unidad = ConfigUnidadFuncional::with(['empresa', 'sede'])->findOrFail($id);
            return response()->json(['success' => true, 'data' => $unidad]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Unidad funcional no encontrada.'], 404);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'codigo'     => "string|max:50|unique:config_unidades_funcionales,codigo,{$id}",
            'nombre'     => 'string|max:150',
            'id_empresa' => 'integer|exists:ent_empresas,id',
            'id_sede'    => 'nullable|integer|exists:config_ubi_sede,id',
            'estado'     => 'boolean',
        ]);

        try {
            $unidad = ConfigUnidadFuncional::findOrFail($id);
            $unidad->update($request->all());
            return response()->json([
                'success' => true,
                'message' => 'Unidad funcional actualizada.',
                'data'    => $unidad->fresh()->load(['empresa', 'sede']),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar unidad funcional: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            $unidad = ConfigUnidadFuncional::findOrFail($id);
            $unidad->update(['estado' => false]);
            return response()->json(['success' => true, 'message' => 'Unidad funcional desactivada.']);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al desactivar unidad funcional: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/turnos/unidades-funcionales/{id}/empleados
     * 
     * Obtiene empleados ASIGNADOS A UNA UNIDAD FUNCIONAL
     * desde la tabla config_unidades_fun_usuarios (relación M2M).
     * 
     * Esto retorna los empleados que están ligados a esta unidad específica.
     */
    public function empleados(Request $request, int $id): JsonResponse
    {
        try {
            $unidad = ConfigUnidadFuncional::findOrFail($id);
            $user = auth()->user();

            // Validar acceso
            $accessControl = new \App\Services\Turnos\AccessControlService($user);
            if (!$accessControl->tieneAccesoUnidad($id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes acceso a esta unidad',
                ], 403);
            }

            // Construir query: ahora id_user apunta a config_person_tercero
            $query = \DB::table('config_unidades_fun_usuarios as cfu')
                ->join('config_person_tercero', 'cfu.id_user', '=', 'config_person_tercero.id')
                ->where('cfu.id_unidad_funcional', $id)
                ->select(
                    'cfu.id_user as id',
                    'config_person_tercero.nombre',
                    'config_person_tercero.email',
                    'cfu.id_unidad_funcional as id_unidad',
                    \DB::raw('CASE 
                        WHEN cfu.id_user IN (
                            SELECT id_user 
                            FROM config_unidades_fun_responsable 
                            WHERE id_unidad_funcional = cfu.id_unidad_funcional
                        ) THEN 1 
                        ELSE 0 
                    END as es_responsable')
                );

            // Si es USUARIO_RESPONSABLE_TURNO, solo ve sus propias unidades
            if ($accessControl->getAccessLevel() === 'usuario_responsable_turno') {
                $query->whereIn('cfu.id_unidad_funcional', function ($subquery) use ($user) {
                    $subquery->select('id_unidad_funcional')
                        ->from('config_unidades_fun_responsable')
                        ->where('id_user', $user->id);
                });
            }
            // Si es EMPRESA_ADMIN o SUPER_ADMIN, ve todos

            $empleados = $query
                ->orderBy('es_responsable', 'DESC')
                ->orderBy('config_person_tercero.nombre', 'ASC')
                ->get();

            return response()->json([
                'success' => true,
                'data'    => $empleados,
                'access_level' => $accessControl->getAccessLevel(),
                'message' => $empleados->isEmpty() 
                    ? "No hay empleados asignados a la unidad '{$unidad->nombre}'"
                    : null,
            ]);
        } catch (\Exception $e) {
            \Log::error('❌ Error en empleados():', [
                'user_id' => auth()->id(),
                'unidad_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener empleados: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/turnos/empresas/{id}/sucursales
     */
    public function sucursalesPorEmpresa(int $empresaId): JsonResponse
    {
        try {
            $user = auth()->user();
            $accessControl = new \App\Services\Turnos\AccessControlService($user);

            if (!$accessControl->tieneAccesoEmpresa($empresaId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes acceso a esta empresa',
                ], 403);
            }

            $sucursales = $accessControl->getSucursalesPorEmpresa($empresaId);

            return response()->json([
                'success' => true,
                'data' => $sucursales,
                'empresa_id' => $empresaId,
                'total' => $sucursales->count(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener sucursales: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/turnos/sucursales/{id}/sedes
     */
    public function sedesPorSucursal(int $sucursalId): JsonResponse
    {
        try {
            $user = auth()->user();
            $accessControl = new \App\Services\Turnos\AccessControlService($user);

            $sedes = $accessControl->getSedesPorSucursal($sucursalId);

            return response()->json([
                'success' => true,
                'data' => $sedes,
                'sucursal_id' => $sucursalId,
                'total' => $sedes->count(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener sedes: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/turnos/sucursales/{sucursalId}/unidades-terceros?id_empresa=X
     * 
     * Cuando una sucursal NO tiene sedes, busca las unidades funcionales
     * directamente usando los TAGs de esa sucursal en matzobs_agentes
     */
    public function unidadesTercerosPorSucursal(Request $request, int $sucursalId): JsonResponse
    {
        try {
            $empresaId = (int) $request->query('id_empresa');
            
            if (!$empresaId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Se requiere el parámetro id_empresa',
                ], 422);
            }

            // Obtener TODOS los tags de la sucursal (filtrando por empresa + sucursal)
            $tags = \DB::table('matzobs_agentes')
                ->where('matzobs_agentes.id_empresa', $empresaId)
                ->where('matzobs_agentes.id_sucursal', $sucursalId)
                ->whereNotNull('matzobs_agentes.tag')
                ->select('matzobs_agentes.tag')
                ->distinct()
                ->pluck('tag')
                ->toArray();

            if (empty($tags)) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'message' => 'No se encontraron tags para esta sucursal',
                    'debug' => ['sucursal_id' => $sucursalId, 'empresa_id' => $empresaId],
                ]);
            }

            // Buscar unidades en config_person_tercero que coincidan con los tags
            $query = \DB::table('config_person_tercero')
                ->where('id_empresa', $empresaId)
                ->where('estado', true)
                ->whereNotNull('unidad')
                ->where('unidad', '!=', '');

            $query->where(function($q) use ($tags) {
                foreach ($tags as $tag) {
                    $q->orWhere('unidad', 'LIKE', "%-{$tag}");
                    $q->orWhere('unidad', 'LIKE', "%- {$tag}");
                    $q->orWhere('unidad', 'LIKE', "% - {$tag}");
                    $q->orWhere('unidad', 'LIKE', "{$tag}-%");
                    $q->orWhere('unidad', 'LIKE', "{$tag} -%");
                    $q->orWhere('unidad', 'LIKE', "{$tag} - %");
                }
            });

            $unidades = $query->select('unidad')
                ->distinct()
                ->orderBy('unidad')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $unidades,
                'sucursal_id' => $sucursalId,
                'empresa_id' => $empresaId,
                'tags' => $tags,
                'total' => $unidades->count(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener unidades por sucursal: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/turnos/sedes/{sedeId}/unidades-terceros?id_empresa=X
     * 
     * Obtiene las unidades funcionales DISTINCT del campo 'unidad' de config_person_tercero
     * filtrando por el TAG de la sede
     */
    public function unidadesTercerosPorSede(Request $request, int $sedeId): JsonResponse
    {
        try {
            $user = auth()->user();
            $accessControl = new \App\Services\Turnos\AccessControlService($user);

            $empresaId = (int) $request->query('id_empresa');
            
            if (!$empresaId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Se requiere el parámetro id_empresa',
                ], 422);
            }

            // Obtener TODOS los tags de la sede (puede haber más de uno)
            $tags = \DB::table('matzobs_agentes')
                ->where('matzobs_agentes.id_sede', $sedeId)
                ->whereNotNull('matzobs_agentes.tag')
                ->select('matzobs_agentes.tag')
                ->distinct()
                ->pluck('tag')
                ->toArray();

            if (empty($tags)) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'message' => 'No se encontraron tags para esta sede',
                    'debug' => ['sede_id' => $sedeId],
                ]);
            }

            // Buscar unidades que contengan CUALQUIERA de los tags (prefijo o sufijo)
            $query = \DB::table('config_person_tercero')
                ->where('id_empresa', $empresaId)
                ->where('estado', true)
                ->whereNotNull('unidad')
                ->where('unidad', '!=', '');

            $query->where(function($q) use ($tags) {
                foreach ($tags as $tag) {
                    // Buscar como sufijo: "ADMISION-NVA" o "ADMISION - NVA"
                    $q->orWhere('unidad', 'LIKE', "%-{$tag}");
                    $q->orWhere('unidad', 'LIKE', "%- {$tag}");
                    $q->orWhere('unidad', 'LIKE', "% - {$tag}");
                    // Buscar como prefijo: "CMI-GERENCIA" o "CMI - GERENCIA"
                    $q->orWhere('unidad', 'LIKE', "{$tag}-%");
                    $q->orWhere('unidad', 'LIKE', "{$tag} -%");
                    $q->orWhere('unidad', 'LIKE', "{$tag} - %");
                }
            });

            $unidades = $query->select('unidad')
                ->distinct()
                ->orderBy('unidad')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $unidades,
                'sede_id' => $sedeId,
                'total' => $unidades->count(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener unidades: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/turnos/sedes/{sedeId}/empleados-terceros?id_empresa=X&unidad=Y
     * 
     * Obtiene empleados de config_person_tercero filtrando por el TAG de la sede
     * Si se pasa 'unidad', filtra por esa unidad exacta
     */
    public function empleadosTercerosPorSede(Request $request, int $sedeId): JsonResponse
    {
        try {
            $user = auth()->user();
            $accessControl = new \App\Services\Turnos\AccessControlService($user);

            $empresaId = (int) $request->query('id_empresa');
            $unidad = $request->query('unidad');
            
            if (!$empresaId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Se requiere el parámetro id_empresa',
                ], 422);
            }

            if (!$accessControl->tieneAccesoEmpresa($empresaId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes acceso a esta empresa',
                ], 403);
            }

            // Si se pasa unidad exacta, filtrar por ella
            if ($unidad) {
                $empleados = \DB::table('config_person_tercero')
                    ->where('id_empresa', $empresaId)
                    ->where('estado', true)
                    ->where('unidad', $unidad)
                    ->select('id', 'nombre', 'email', 'unidad', 'numero_identificacion')
                    ->orderBy('nombre')
                    ->get();
            } else {
                $empleados = $accessControl->getEmpleadosTercerosPorSede($empresaId, $sedeId);
            }

            return response()->json([
                'success' => true,
                'data' => $empleados,
                'sede_id' => $sedeId,
                'empresa_id' => $empresaId,
                'total' => $empleados->count(),
            ]);
        } catch (\Exception $e) {
            \Log::error('Error en empleadosTercerosPorSede:', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener empleados: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/turnos/empresas/{id}/sedes
     * 
     * Obtiene sedes de una empresa específica según el acceso del usuario
     */
    public function sedesPorEmpresa(int $empresaId): JsonResponse
    {
        try {
            $user = auth()->user();
            $accessControl = new \App\Services\Turnos\AccessControlService($user);

            // Validar acceso a la empresa
            if (!$accessControl->tieneAccesoEmpresa($empresaId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes acceso a esta empresa',
                ], 403);
            }

            $sedes = $accessControl->getSedesPorEmpresa($empresaId);

            return response()->json([
                'success' => true,
                'data' => $sedes,
                'empresa_id' => $empresaId,
                'total' => $sedes->count(),
            ]);
        } catch (\Exception $e) {
            \Log::error('Error en sedesPorEmpresa:', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener sedes: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/turnos/sedes/{id}/unidades
     * 
     * Obtiene unidades de una sede específica dentro de una empresa
     */
    public function unidadesPorSede(int $empresaId, int $sedeId): JsonResponse
    {
        try {
            $user = auth()->user();
            $accessControl = new \App\Services\Turnos\AccessControlService($user);

            // Validar acceso a la empresa
            if (!$accessControl->tieneAccesoEmpresa($empresaId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes acceso a esta empresa',
                ], 403);
            }

            $unidades = $accessControl->getUnidadesPorSede($empresaId, $sedeId);

            return response()->json([
                'success' => true,
                'data' => $unidades,
                'empresa_id' => $empresaId,
                'sede_id' => $sedeId,
                'total' => $unidades->count(),
            ]);
        } catch (\Exception $e) {
            \Log::error('Error en unidadesPorSede:', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener unidades: ' . $e->getMessage(),
            ], 500);
        }
    }
    /**
     * Extrae palabras clave útiles del código y nombre, descartando prefijos
     * administrativos comunes y palabras de menos de 3 letras.
     */
    private function extraerPalabrasClave(?string $codigo, ?string $nombre): array
    {
        $stopwords = ['de', 'del', 'la', 'el', 'los', 'las', 'y', 'a', 'en',
                      'nacional', 'nal', 'ma', 'mesa', 'ayuida', 'ayuda'];

        $crudo = strtolower(trim(($codigo ?? '') . ' ' . ($nombre ?? '')));
        $crudo = preg_replace('/[\-_\.,;:\/]+/', ' ', $crudo);
        $crudo = preg_replace('/\b\d+\b/', ' ', $crudo);

        $palabras = array_unique(array_filter(
            explode(' ', $crudo),
            fn($p) => strlen($p) >= 3 && !in_array($p, $stopwords, true)
        ));

        return array_values($palabras);
    }
}
