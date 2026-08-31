<?php

namespace App\Http\Controllers\Inventory\Pharmacy;

use App\Http\Controllers\Controller;
use App\Services\Inventory\Pharmacy\InvOrdenCompraService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class InvOrdenCompraController extends Controller
{
    protected InvOrdenCompraService $service;

    public function __construct(InvOrdenCompraService $service)
    {
        $this->service = $service;
    }

    /**
     * Listar órdenes de compra.
     * Soporta 'source=external' para traer desde el ERP (SQL Server)
     * GET /api/inventario/ordenes-compra
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $filters = [
                'search'      => $request->query('search'),
                'estado'      => $request->query('estado') ?? $request->query('status'),
                'proveedor'   => $request->query('proveedor'),
                'fecha_desde' => $request->query('fecha_desde'),
                'fecha_hasta' => $request->query('fecha_hasta'),
                'creado_por'  => $request->query('creado_por'),
                'source'      => $request->query('source'),
                'limit'       => $request->query('perPage') ?? $request->query('limit', 25),
                'offset'      => $request->query('offset', 0),
            ];

            $result = $this->service->getAll($filters);

            return response()->json($result, 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener órdenes de compra',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Ver orden local específica
     * GET /api/inventario/ordenes-compra/{id}
     */
    public function show(string $id): JsonResponse
    {
        try {
            $orden = $this->service->getById((int) $id);

            if (!$orden) {
                return response()->json([
                    'success' => false,
                    'message' => 'Orden no encontrada',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data'    => $orden,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener la orden',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Sincronizar o crear orden manualmente
     * POST /api/inventario/ordenes-compra
     */
    public function store(Request $request): JsonResponse
    {
        $userId = auth('api')->id() ?? 1;

        // Creación MANUAL desde el aplicativo: llega un pedido_id o detalles.
        // (Se distingue de la sincronización externa, que trae numero_orden + proveedor.)
        $esCreacionManual = $request->filled('pedido_id') || $request->filled('detalles');

        if ($esCreacionManual) {
            $validator = Validator::make($request->all(), [
                'sucursal_id' => 'required|integer',
                'detalles'    => 'nullable|array',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error de validación',
                    'errors'  => $validator->errors(),
                ], 422);
            }

            try {
                $result = $this->service->create($request->all(), (int) $userId);
                return response()->json($result, $result['success'] ? 201 : 500);
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error al crear la orden',
                    'error'   => $e->getMessage(),
                ], 500);
            }
        }

        // Camino de sincronización/creación externa.
        $validator = Validator::make($request->all(), [
            'numero_orden' => 'required|string',
            'proveedor'    => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $result = $this->service->syncFromExternal($request->all(), (int) $userId);

            return response()->json($result, $result['success'] ? 201 : 500);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al guardar orden',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Actualizar una orden de compra local.
     * PUT/PATCH /api/inventario/ordenes-compra/{id}
     * Solo OC creadas en el aplicativo, por su creador y en estado pendiente.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $userId = auth('api')->id();
            if (!$userId) {
                return response()->json(['success' => false, 'message' => 'No autenticado'], 401);
            }

            $result = $this->service->update((int) $id, $request->all(), (int) $userId);
            $status = $result['success'] ? 200 : ($result['code'] ?? 400);

            return response()->json($result, $status);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar la orden',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Eliminar una orden de compra local.
     * DELETE /api/inventario/ordenes-compra/{id}
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $userId = auth('api')->id();
            if (!$userId) {
                return response()->json(['success' => false, 'message' => 'No autenticado'], 401);
            }

            $result = $this->service->delete((int) $id, (int) $userId);
            $status = $result['success'] ? 200 : ($result['code'] ?? 400);

            return response()->json($result, $status);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar la orden',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Listar sucursales disponibles para el usuario autenticado (para el selector de UI).
     * GET /api/inventario/ordenes-compra/sucursales-disponibles
     */
    public function sucursalesDisponibles(): JsonResponse
    {
        try {
            $userId = auth('api')->id() ?? 0;
            $result = $this->service->getSucursalesDisponibles((int) $userId);
            return response()->json($result, 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener sucursales',
                'error'   => $e->getMessage(),
                'data'    => [],
            ], 500);
        }
    }

    /**
     * Cambiar estado de la orden
     * PATCH /api/inventario/ordenes-compra/{id}/estado
     */
    public function cambiarEstado(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'estado' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $userId = auth()->user()->id ?? 1;

            if (strtoupper($request->estado) === 'CONFIRMADO') {
                $result = $this->service->confirmPurchase((int) $id, $userId);
            } else {
                $result = $this->service->cambiarEstado((int) $id, $request->estado);
            }
            
            return response()->json($result, $result['success'] ? 200 : 400);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar estado',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Sincronizar orden desde Indigo (o general)
     * POST /api/inventario/ordenes-compra/sync
     */
    public function sync(Request $request, \App\Services\Inventory\Pharmacy\MonitoringService $monitoringService): JsonResponse
    {
        $numero_orden = $request->input('numero_orden');
        $sucursal_id  = $request->input('sucursal_id');
        
        try {
            $userId = auth('api')->id() ?? 1;
            
            // Control de acceso: si se indica sucursal, el usuario debe tener permiso sobre ella.
            if ($sucursal_id && !$this->service->usuarioTieneAccesoSucursal((int) $userId, (int) $sucursal_id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes acceso a la sucursal seleccionada.',
                ], 403);
            }

            $options = [];
            if ($numero_orden) {
                $options['numero_orden'] = $numero_orden;
            }
            if ($sucursal_id) {
                $options['sucursal_id'] = (int) $sucursal_id;
            }
            
            $result = $monitoringService->syncIndigoOrders($userId, $options);
            return response()->json($result, $result['success'] ? 200 : 500);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al sincronizar',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
