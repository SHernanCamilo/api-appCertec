<?php

namespace App\Http\Controllers\Inventory\Pharmacy;

use App\Http\Controllers\Controller;
use App\Services\Inventory\Pharmacy\InvReporteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Reportes unificados de Farmacia (tablero tipo BI).
 * Consolida Pedidos + Órdenes de Compra + Recepciones Técnicas.
 */
class InvReporteController extends Controller
{
    public function __construct(private InvReporteService $service)
    {
    }

    /**
     * GET /api/inventario/reportes/dashboard
     *
     * Filtros: fecha_desde, fecha_hasta, proveedor, estado_pedido,
     *          estado_recepcion, sucursal_id
     */
    public function dashboard(Request $request): JsonResponse
    {
        $userId = auth('api')->id();
        if (!$userId) {
            return response()->json(['success' => false, 'message' => 'No autenticado'], 401);
        }

        $validator = Validator::make($request->all(), [
            'fecha_desde'      => 'nullable|date',
            'fecha_hasta'      => 'nullable|date|after_or_equal:fecha_desde',
            'proveedor'        => 'nullable|string|max:200',
            'estado_pedido'    => 'nullable|string|max:30',
            'estado_recepcion' => 'nullable|string|max:30',
            'sucursal_id'      => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $filters = $validator->validated();
            $filters['user_id'] = (int) $userId;

            return response()->json($this->service->getDashboard($filters), 200);
        } catch (\Throwable $e) {
            Log::error('Error en reportes de farmacia: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id'   => $userId,
            ]);

            // No se expone el mensaje interno al cliente.
            return response()->json([
                'success' => false,
                'message' => 'Error al generar el reporte. Intenta de nuevo.',
            ], 500);
        }
    }

    /**
     * GET /api/inventario/reportes/tiempos
     *
     * Tablero de tiempos de gestión (Pedido → OC → Recepción) con filtros de
     * fecha independientes por etapa.
     *
     * Filtros: pedido_desde, pedido_hasta, orden_desde, orden_hasta,
     *          recepcion_desde, recepcion_hasta, proveedor, sucursal_id,
     *          umbral_ok, umbral_alerta
     */
    public function tiempos(Request $request): JsonResponse
    {
        $userId = auth('api')->id();
        if (!$userId) {
            return response()->json(['success' => false, 'message' => 'No autenticado'], 401);
        }

        $validator = Validator::make($request->all(), [
            'pedido_desde'    => 'nullable|date',
            'pedido_hasta'    => 'nullable|date|after_or_equal:pedido_desde',
            'orden_desde'     => 'nullable|date',
            'orden_hasta'     => 'nullable|date|after_or_equal:orden_desde',
            'recepcion_desde' => 'nullable|date',
            'recepcion_hasta' => 'nullable|date|after_or_equal:recepcion_desde',
            'proveedor'       => 'nullable|string|max:200',
            'sucursal_id'     => 'nullable|integer',
            'umbral_ok'       => 'nullable|integer|min:1|max:365',
            'umbral_alerta'   => 'nullable|integer|min:1|max:365',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $filters = $validator->validated();
            $filters['user_id'] = (int) $userId;

            return response()->json($this->service->tiemposGestion($filters), 200);
        } catch (\Throwable $e) {
            Log::error('Error en reporte de tiempos de farmacia: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id'   => $userId,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al generar el reporte de tiempos. Intenta de nuevo.',
            ], 500);
        }
    }

    /**
     * GET /api/inventario/reportes/trazabilidad-producto
     *
     * Trazabilidad de un producto en las órdenes de compra: en qué OC está, su
     * estado y lo recepcionado vs. pendiente.
     *
     * Filtros: q (código o nombre), estado, sucursal_id
     */
    public function trazabilidadProducto(Request $request): JsonResponse
    {
        $userId = auth('api')->id();
        if (!$userId) {
            return response()->json(['success' => false, 'message' => 'No autenticado'], 401);
        }

        $validator = Validator::make($request->all(), [
            'q'           => 'nullable|string|max:150',
            'estado'      => 'nullable|string|max:30',
            'sucursal_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $filters = $validator->validated();
            $filters['user_id'] = (int) $userId;

            return response()->json($this->service->trazabilidadProducto($filters), 200);
        } catch (\Throwable $e) {
            Log::error('Error en trazabilidad de producto: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id'   => $userId,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al generar la trazabilidad. Intenta de nuevo.',
            ], 500);
        }
    }
}
