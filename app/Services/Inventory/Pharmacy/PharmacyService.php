<?php

namespace App\Services\Inventory\Pharmacy;

use App\Models\Inventory\InvPedido;
use App\Models\Inventory\InvPedidoDetalle;
use App\Models\Inventory\InvOrdenCompra;
use App\Models\Inventory\InvOrdenCompraDetalle;
use App\Models\Inventory\InvRecepcion;
use App\Models\Inventory\InvRecepcionDetalle;
use App\Models\Inventory\InvMuestreoNivel;
use App\Models\Inventory\InvMuestreoExclusion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Servicio principal del módulo de Inventario de Farmacia.
 *
 * Coordina la gestión de pedidos, órdenes de compra, recepciones técnicas
 * y muestreo de productos farmacéuticos.
 */
class PharmacyService
{
    /**
     * Obtener resumen general del inventario de farmacia.
     */
    public function getDashboard(): array
    {
        $pedidosPendientes = InvPedido::where('estado', 'pendiente')->count();
        $pedidosEnProceso = InvPedido::where('estado', 'en_proceso')->count();
        $ordenesEnTransito = InvOrdenCompra::where('estado', 'en_transito')->count();
        $ordenesEnSitio = InvOrdenCompra::where('estado', 'en_sitio')->count();
        $recepcionesRecientes = InvRecepcion::where('created_at', '>=', now()->subDays(7))->count();

        return [
            'pedidos' => [
                'pendientes' => $pedidosPendientes,
                'en_proceso' => $pedidosEnProceso,
            ],
            'ordenes_compra' => [
                'en_transito' => $ordenesEnTransito,
                'en_sitio' => $ordenesEnSitio,
            ],
            'recepciones_semana' => $recepcionesRecientes,
        ];
    }

    /**
     * Calcular tamaño de muestra según ISO 2859-1 (NTC-ISO 2859-1).
     *
     * @param int $cantidadLote Cantidad del lote recibido
     * @param string $codigoProducto Código del producto (para verificar exclusiones)
     * @return array ['requiere_muestreo' => bool, 'tamano_muestra' => int, 'letra_codigo' => string]
     */
    public function calcularMuestra(int $cantidadLote, string $codigoProducto): array
    {
        // Verificar si el producto está excluido de muestreo
        $excluido = InvMuestreoExclusion::where('codigo_producto', $codigoProducto)
            ->where('activo', true)
            ->exists();

        if ($excluido) {
            return [
                'requiere_muestreo' => false,
                'tamano_muestra' => 0,
                'letra_codigo' => '-',
                'motivo' => 'Producto excluido de muestreo (Control Especial / Alto Costo)',
            ];
        }

        // Buscar nivel de inspección
        $nivel = InvMuestreoNivel::where('activo', true)
            ->where('lote_min', '<=', $cantidadLote)
            ->where('lote_max', '>=', $cantidadLote)
            ->first();

        if (!$nivel) {
            return [
                'requiere_muestreo' => true,
                'tamano_muestra' => max(2, (int) ceil($cantidadLote * 0.1)),
                'letra_codigo' => '?',
                'motivo' => 'Sin nivel de inspección definido, se aplica 10%',
            ];
        }

        return [
            'requiere_muestreo' => true,
            'tamano_muestra' => $nivel->tamano_muestra,
            'letra_codigo' => $nivel->letra_codigo,
            'motivo' => "Nivel {$nivel->nivel_inspeccion}, Letra {$nivel->letra_codigo}",
        ];
    }

    /**
     * Obtener estado de disponibilidad de un pedido (cuánto está en OC y cuánto falta).
     */
    public function getDisponibilidadPedido(int $pedidoId): array
    {
        $pedido = InvPedido::with('detalles')->find($pedidoId);
        if (!$pedido) {
            return ['success' => false, 'message' => 'Pedido no encontrado'];
        }

        $resultado = [];
        foreach ($pedido->detalles as $detalle) {
            $enCompras = InvOrdenCompraDetalle::where('pedido_detalle_id', $detalle->id)
                ->where('estado', '!=', 'cancelada')
                ->whereHas('ordenCompra', fn($q) => $q->where('estado', '!=', 'CANCELADA'))
                ->sum('cantidad_solicitada_compra');

            $disponible = max(0, $detalle->cantidad_solicitada - $enCompras);
            $ordenesActivas = InvOrdenCompraDetalle::where('pedido_detalle_id', $detalle->id)
                ->where('estado', '!=', 'cancelada')
                ->distinct('compra_id')
                ->count('compra_id');

            $resultado[] = [
                'pedido_detalle_id' => $detalle->id,
                'codigo_producto' => $detalle->codigo_producto,
                'producto_nombre' => $detalle->producto_nombre,
                'cantidad_solicitada' => $detalle->cantidad_solicitada,
                'cantidad_en_compras' => (float) $enCompras,
                'cantidad_disponible' => $disponible,
                'ordenes_activas' => $ordenesActivas,
                'estado_disponibilidad' => $this->determinarEstadoDisponibilidad(
                    $detalle->cantidad_solicitada,
                    (float) $enCompras,
                    $ordenesActivas
                ),
            ];
        }

        return [
            'success' => true,
            'pedido' => $pedido->numero_pedido,
            'total_items' => count($resultado),
            'detalle' => $resultado,
        ];
    }

    /**
     * Determinar estado de disponibilidad de un producto en un pedido.
     */
    private function determinarEstadoDisponibilidad(int $solicitada, float $enCompras, int $ordenes): string
    {
        if ($ordenes >= 2) return 'BLOQUEADO';
        if ($enCompras >= $solicitada && $solicitada > 0) return 'COMPLETO';
        if ($enCompras > 0) return 'PARCIAL';
        return 'PENDIENTE';
    }

    /**
     * Obtener historial de recepciones de una orden de compra.
     */
    public function getRecepcionesOrden(int $compraId): array
    {
        $recepciones = InvRecepcion::where('compra_id', $compraId)
            ->with('detalles')
            ->orderBy('fecha_recepcion', 'desc')
            ->get();

        return [
            'success' => true,
            'total_recepciones' => $recepciones->count(),
            'recepciones' => $recepciones->map(function ($rec) {
                return [
                    'id' => $rec->id,
                    'fecha_recepcion' => $rec->fecha_recepcion,
                    'total_items' => $rec->total_items,
                    'estado' => $rec->estado,
                    'items_recibidos' => $rec->detalles->where('cantidad_recibida', '>', 0)->count(),
                ];
            }),
        ];
    }
}
