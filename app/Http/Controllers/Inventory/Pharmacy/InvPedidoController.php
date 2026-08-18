<?php

namespace App\Http\Controllers\Inventory\Pharmacy;

use App\Http\Controllers\Controller;
use App\Services\Inventory\Pharmacy\InvPedidoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class InvPedidoController extends Controller
{
    protected InvPedidoService $service;

    public function __construct(InvPedidoService $service)
    {
        $this->service = $service;
    }

    /**
     * Listar pedidos
     * GET /api/inventario/pedidos
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $filters = [
                'search'    => $request->query('search'),
                'estado'    => $request->query('estado'),
                'proveedor' => $request->query('proveedor'),
                'limit'     => $request->query('limit', 25),
                'offset'    => $request->query('offset', 0),
            ];

            $result = $this->service->getAll($filters);

            return response()->json($result, 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener los pedidos',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mostrar pedido
     * GET /api/inventario/pedidos/{id}
     */
    public function show(string $id): JsonResponse
    {
        try {
            $pedido = $this->service->getById((int) $id);

            if (!$pedido) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pedido no encontrado',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data'    => $pedido,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener el pedido',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Crear pedido
     * POST /api/inventario/pedidos
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'proveedor'      => 'nullable|string|max:255',
            'fecha_pedido'   => 'nullable|date',
            'fecha_esperada' => 'nullable|date',
            'observaciones'  => 'nullable|string',
            'detalles'       => 'required|array|min:1',
            'detalles.*.codigo_producto'     => 'required|string',
            'detalles.*.producto_nombre'     => 'required|string',
            'detalles.*.cantidad_solicitada' => 'required|numeric|min:0.01',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            // El usuario autenticado viene del middleware
            $userId = auth()->user()->id ?? 1; // Fallback para dev local si auth falla
            $result = $this->service->create($request->all(), $userId);

            return response()->json($result, $result['success'] ? 201 : 500);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear el pedido',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Actualizar pedido (Borrador)
     * PUT /api/inventario/pedidos/{id}
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'proveedor'      => 'nullable|string|max:255',
            'fecha_esperada' => 'nullable|date',
            'observaciones'  => 'nullable|string',
            'detalles'       => 'nullable|array',
            'detalles.*.codigo_producto'     => 'required_with:detalles|string',
            'detalles.*.producto_nombre'     => 'required_with:detalles|string',
            'detalles.*.cantidad_solicitada' => 'required_with:detalles|numeric|min:0.01',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $result = $this->service->update((int) $id, $request->all());
            return response()->json($result, $result['success'] ? 200 : ($result['message'] === 'Pedido no encontrado' ? 404 : 400));
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar el pedido',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Cambiar estado del pedido
     * PATCH /api/inventario/pedidos/{id}/estado
     */
    public function cambiarEstado(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'estado' => 'required|string|in:BORRADOR,SOLICITADO,APROBADO,EN_TRANSITO,RECIBIDO,CANCELADO'
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
            $result = $this->service->cambiarEstado((int) $id, $request->estado, $userId);

            return response()->json($result, $result['success'] ? 200 : 400);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al cambiar estado',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Cancelar (eliminar) un pedido
     * DELETE /api/inventario/pedidos/{id}
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $userId = auth()->user()->id ?? 1;
            $result = $this->service->destroy((int) $id, $userId);

            return response()->json($result, $result['success'] ? 200 : 400);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al cancelar el pedido',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
