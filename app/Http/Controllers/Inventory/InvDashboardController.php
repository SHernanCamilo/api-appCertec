<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Inventory\InvOrdenCompra;
use App\Models\Inventory\InvPedido;
use App\Models\Inventory\InvProducto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InvDashboardController extends Controller
{
    /**
     * Obtener estadísticas para el dashboard principal de inventario/compras.
     * GET /api/inventario/dashboard/stats
     */
    public function getStats(Request $request): JsonResponse
    {
        try {
            $totalPedidos = InvPedido::count();
            $totalOrdenes = InvOrdenCompra::count();
            $totalProductos = InvProducto::count();
            
            // Suma del total de las órdenes de compra activas o recibidas (excluyendo canceladas)
            $valorTotalCompras = InvOrdenCompra::whereNotIn('estado', ['CANCELADA', 'RECHAZADA'])
                ->sum('total');

            // Podríamos agrupar pedidos por estado
            $pedidosPorEstado = InvPedido::select('estado', DB::raw('count(*) as total'))
                ->groupBy('estado')
                ->get();

            // Órdenes por estado
            $ordenesPorEstado = InvOrdenCompra::select('estado', DB::raw('count(*) as total'))
                ->groupBy('estado')
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'resumen' => [
                        'total_pedidos' => $totalPedidos,
                        'total_ordenes' => $totalOrdenes,
                        'total_productos' => $totalProductos,
                        'valor_total_compras' => (float) $valorTotalCompras,
                    ],
                    'pedidos_por_estado' => $pedidosPorEstado,
                    'ordenes_por_estado' => $ordenesPorEstado
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener estadísticas del dashboard',
                'error'   => $e->getMessage()
            ], 500);
        }
    }
}
