<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Services\Inventory\InvOrdenCompraService;
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
                'search'    => $request->query('search'),
                'estado'    => $request->query('estado'),
                'proveedor' => $request->query('proveedor'),
                'source'    => $request->query('source'),
                'limit'     => $request->query('limit', 25),
                'offset'    => $request->query('offset', 0),
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
            $userId = auth()->user()->id ?? 1;
            $result = $this->service->syncFromExternal($request->all(), $userId);

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
     * Cambiar estado de la orden
     * PATCH /api/inventario/ordenes-compra/{id}/estado
     */
    public function cambiarEstado(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'estado' => 'required|string|in:BORRADOR,CONFIRMADO,EN_TRANSITO,RECIBIDA_PARCIAL,RECIBIDA_TOTAL,CANCELADA'
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
}
