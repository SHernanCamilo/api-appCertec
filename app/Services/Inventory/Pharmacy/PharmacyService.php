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
     * Devuelve la tabla de muestreo (niveles ISO 2859-1) y las exclusiones activas.
     * El frontend la usa para calcular el tamaño de muestra en vivo al cambiar
     * la cantidad a recibir, sin llamar al backend celda por celda.
     *
     * @return array{success:bool, niveles:array, exclusiones:array}
     */
    public function getTablaMuestreo(): array
    {
        $niveles = InvMuestreoNivel::where('activo', true)
            ->orderBy('lote_min')
            ->get(['nivel_inspeccion', 'lote_min', 'lote_max', 'letra_codigo', 'tamano_muestra'])
            ->map(fn ($n) => [
                'nivel_inspeccion' => $n->nivel_inspeccion,
                'lote_min'         => (int) $n->lote_min,
                'lote_max'         => (int) $n->lote_max,
                'letra_codigo'     => $n->letra_codigo,
                'tamano_muestra'   => (int) $n->tamano_muestra,
            ])->values()->all();

        // Solo los códigos excluidos (muestreo 100%); el frontend compara por código.
        $exclusiones = InvMuestreoExclusion::where('activo', true)
            ->pluck('codigo_producto')
            ->map(fn ($c) => (string) $c)
            ->values()
            ->all();

        return [
            'success'     => true,
            'niveles'     => $niveles,
            'exclusiones' => $exclusiones,
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
        if ($cantidadLote <= 0) {
            return [
                'requiere_muestreo' => false,
                'tamano_muestra' => 0,
                'letra_codigo' => '-',
                'inspeccion_total' => false,
                'motivo' => 'Sin cantidad en lote',
            ];
        }

        // Productos en tabla de exclusión ISO: no usan muestreo estadístico → inspección 100%
        $exclusion = InvMuestreoExclusion::where('codigo_producto', $codigoProducto)
            ->where('activo', true)
            ->first();

        if ($exclusion) {
            return [
                'requiere_muestreo' => true,
                'tamano_muestra' => $cantidadLote,
                'letra_codigo' => '100%',
                'inspeccion_total' => true,
                'motivo' => 'Inspección total del lote (' . ($exclusion->motivo ?? 'Control Especial / Alto Costo') . ')',
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
                'inspeccion_total' => false,
                'motivo' => 'Sin nivel de inspección definido, se aplica 10%',
            ];
        }

        return [
            'requiere_muestreo' => true,
            'tamano_muestra' => (int) $nivel->tamano_muestra,
            'letra_codigo' => $nivel->letra_codigo,
            'inspeccion_total' => false,
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
