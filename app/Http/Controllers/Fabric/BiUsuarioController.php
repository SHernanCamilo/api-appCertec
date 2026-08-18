<?php

namespace App\Http\Controllers\Fabric;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Fabric\BiUsuarioPermisosService;
use App\Services\Fabric\BiVistaAuditService;
use App\Services\Tenant\UserGrupSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BiUsuarioController extends Controller
{
    public function __construct(
        private BiUsuarioPermisosService $permisosService,
        private UserGrupSyncService $userGrupSyncService,
        private BiVistaAuditService $auditService
    ) {}

    /**
     * GET /api/fabric/bi-usuarios/{userId}/permisos?empresa_id=X&sync=1
     */
    public function permisos(Request $request, int $userId): JsonResponse
    {
        $request->validate([
            'empresa_id' => 'nullable|exists:ent_empresas,id',
            'sync'       => 'nullable|boolean',
        ]);

        try {
            $user = User::query()->findOrFail($userId);
            $empresaId = $request->filled('empresa_id') ? (int) $request->empresa_id : null;

            $syncResult = null;
            if ($request->boolean('sync')) {
                $syncResult = $this->userGrupSyncService->syncFromAzureOnLogin($user, true);
            }

            $data = $this->permisosService->getPermisos($user, $empresaId);

            return response()->json([
                'success'     => true,
                'data'        => $data,
                'azure_sync'  => $syncResult,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al consultar permisos BI del usuario',
                'error'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * GET /api/fabric/bi-usuarios/auditoria
     */
    public function auditoria(Request $request): JsonResponse
    {
        $request->validate([
            'fecha_desde' => 'nullable|date',
            'fecha_hasta' => 'nullable|date|after_or_equal:fecha_desde',
            'empresa_id'  => 'nullable|exists:ent_empresas,id',
            'schema'      => 'nullable|string|max:50',
            'user_id'     => 'nullable|integer|exists:users,id',
            'accion'      => 'nullable|string|max:50',
            'view'        => 'nullable|string|max:200',
            'limit'       => 'nullable|integer|min:1|max:2000',
        ]);

        try {
            $result = $this->auditService->buscar($request->only([
                'fecha_desde',
                'fecha_hasta',
                'empresa_id',
                'schema',
                'user_id',
                'accion',
                'view',
                'limit',
            ]));

            $items = $result['items']->map(fn ($row) => [
                'id'             => $row->id,
                'accessed_at'    => $row->accessed_at?->toIso8601String(),
                'user_id'        => $row->user_id,
                'user_name'      => $row->user_name,
                'user_email'     => $row->user_email,
                'empresa_id'     => $row->empresa_id,
                'empresa_nombre' => $row->empresa_nombre,
                'schema'         => $row->schema_name,
                'view'           => $row->view_name,
                'accion'         => $row->accion,
                'rows_returned'  => $row->rows_returned,
                'elapsed_ms'     => $row->elapsed_ms,
                'success'        => $row->success,
                'ip_address'     => $row->ip_address,
            ])->values();

            return response()->json([
                'success' => true,
                'data'    => [
                    'items' => $items,
                    'total' => $result['total'],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al consultar auditoría BI',
                'error'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * GET /api/fabric/bi-usuarios/auditoria/esquemas?empresa_id=X
     */
    public function auditoriaEsquemas(Request $request): JsonResponse
    {
        $request->validate([
            'empresa_id' => 'nullable|exists:ent_empresas,id',
        ]);

        try {
            $empresaId = $request->filled('empresa_id') ? (int) $request->empresa_id : null;
            $esquemas = $this->auditService->listarEsquemas($empresaId);

            return response()->json([
                'success' => true,
                'data'    => $esquemas,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al listar esquemas para auditoría',
                'error'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
}
