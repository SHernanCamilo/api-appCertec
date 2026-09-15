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
}
