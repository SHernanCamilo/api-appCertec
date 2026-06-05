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
            $query = ConfigUnidadFuncional::with(['empresa', 'sede']);

            if ($request->filled('id_empresa')) {
                $query->porEmpresa((int) $request->id_empresa);
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

            // Transformar para asegurar que empresa y sede se incluyen
            $unidadesFormateadas = $unidades->map(function($unidad) {
                return [
                    'id' => $unidad->id,
                    'codigo' => $unidad->codigo,
                    'nombre' => $unidad->nombre,
                    'id_empresa' => $unidad->id_empresa,
                    'id_sede' => $unidad->id_sede,
                    'estado' => $unidad->estado,
                    'empresa' => $unidad->empresa ? [
                        'id' => $unidad->empresa->id,
                        'nombre' => $unidad->empresa->nombre,
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

            // Construir query según el nivel de acceso
            $query = \DB::table('config_unidades_fun_usuarios as cfu')
                ->join('users', 'cfu.id_user', '=', 'users.id')
                ->where('cfu.id_unidad_funcional', $id)
                ->select(
                    'cfu.id_user as id',
                    'users.name as nombre',
                    'users.email',
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
                ->orderBy('users.name', 'ASC')
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
