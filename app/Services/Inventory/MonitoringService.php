<?php

namespace App\Services\Inventory;

use App\Models\Inventory\InvOrdenCompra;
use App\Models\Inventory\InvOrdenCompraDetalle;
use App\Models\Inventory\InvPedido;
use App\Models\Inventory\InvPedidoDetalle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Servicio de sincronización de órdenes de compra desde Indigo ERP.
 *
 * Consulta la vista en Microsoft Fabric (in.Inventory_OrdenesDeCompra_DigiPharma)
 * y sincroniza con la BD local. Ya NO necesita conexión directa a SQL Server
 * porque la data está replicada en Fabric.
 */
class MonitoringService
{
    public function __construct(
        private readonly FabricInventoryService $fabricService
    ) {}

    /**
     * Sincroniza órdenes de compra desde Fabric (vista Indigo) hacia la BD local.
     * Agrupa registros por número de orden y crea/actualiza localmente.
     *
     * @param int $userId ID del usuario que ejecuta la sync
     * @param array $options Opciones: fecha_desde, limit
     */
    public function syncIndigoOrders(int $userId = 1, array $options = []): array
    {
        $fechaDesde = $options['fecha_desde'] ?? now()->subDays(7)->format('Y-m-d');
        $limit = $options['limit'] ?? 2000;

        Log::channel('daily')->info('[INDIGO-SYNC] Iniciando sincronización', [
            'fecha_desde' => $fechaDesde,
            'limit' => $limit,
        ]);

        try {
            // Consultar Fabric (ya no necesita SQL Server directo)
            $result = $this->fabricService->getIndigoOrders([
                'fecha_desde' => $fechaDesde,
                'limit' => $limit,
            ]);

            if (!$result['success'] || empty($result['data'])) {
                Log::channel('daily')->warning('[INDIGO-SYNC] Sin datos o error', [
                    'message' => $result['message'] ?? 'Sin registros',
                ]);
                return [
                    'success' => false,
                    'message' => $result['message'] ?? 'Error al obtener datos de Indigo',
                    'stats' => ['procesadas' => 0, 'nuevas' => 0, 'actualizadas' => 0],
                ];
            }

            $registros = collect($result['data']);

            // Agrupar por OrdenCompra (cada orden tiene múltiples productos)
            $ordenesAgrupadas = $registros->groupBy('OrdenCompra');

            $procesadas = 0;
            $nuevas = 0;
            $actualizadas = 0;
            $errores = 0;

            foreach ($ordenesAgrupadas as $numeroOrdenIndigo => $detalles) {
                if (!$numeroOrdenIndigo) continue;

                $cabecera = $detalles->first();

                // Extraer número de pedido interno de la descripción (ej: FLA-2026-001)
                $descripcion = $cabecera['Descripcion_Orden'] ?? $cabecera['Descripcion'] ?? '';
                $numeroPedidoInterno = null;
                if (preg_match('/([A-Z]{3}-\d{4}-\d{3,6})/', $descripcion, $matches)) {
                    $numeroPedidoInterno = $matches[1];
                }

                DB::beginTransaction();
                try {
                    $ordenLocal = InvOrdenCompra::where('oc_indigo', $numeroOrdenIndigo)->first();

                    if (!$ordenLocal) {
                        $ordenLocal = InvOrdenCompra::create([
                            'numero_orden_compra' => $numeroPedidoInterno
                                ? "{$numeroPedidoInterno}-OC"
                                : "IND-{$numeroOrdenIndigo}",
                            'fecha_orden'         => $cabecera['Fecha'] ?? now()->toDateString(),
                            'observaciones'       => $descripcion,
                            'proveedor_nombre'    => $cabecera['Proveedor'] ?? null,
                            'estado'              => 'EN_TRANSITO',
                            'sincronizado_indigo' => true,
                            'creado_por'          => $userId,
                            'oc_indigo'           => $numeroOrdenIndigo,
                        ]);
                        $nuevas++;
                    } else {
                        $ordenLocal->update([
                            'sincronizado_indigo' => true,
                            'observaciones'       => $descripcion,
                            'proveedor_nombre'    => $cabecera['Proveedor'] ?? $ordenLocal->proveedor_nombre,
                        ]);
                        $actualizadas++;
                    }

                    // Sincronizar detalles (productos de la orden)
                    foreach ($detalles as $item) {
                        $codProducto = $item['CodProducto'] ?? $item['Codigo'] ?? '';
                        if (!$codProducto) continue;

                        $detalleLocal = InvOrdenCompraDetalle::where('compra_id', $ordenLocal->id)
                            ->where('codigo_producto_indigo', $codProducto)
                            ->first();

                        if (!$detalleLocal) {
                            InvOrdenCompraDetalle::create([
                                'compra_id'                  => $ordenLocal->id,
                                'codigo_producto_indigo'     => $codProducto,
                                'producto_nombre'            => $item['Producto'] ?? $item['NombreProducto'] ?? '',
                                'proveedor'                  => $item['Proveedor'] ?? null,
                                'cantidad_solicitada_compra' => (int) ($item['Cantidad'] ?? 0),
                                'fecha_entrega_estimada'     => $item['FechaEntrega'] ?? null,
                                'precio_unitario_compra'     => (float) ($item['CostoPromedio'] ?? 0),
                                'observaciones'              => "Sincronizado de Indigo",
                                'estado'                     => 'solicitado',
                            ]);
                        } else {
                            $detalleLocal->update([
                                'cantidad_solicitada_compra' => (int) ($item['Cantidad'] ?? $detalleLocal->cantidad_solicitada_compra),
                                'precio_unitario_compra'     => (float) ($item['CostoPromedio'] ?? $detalleLocal->precio_unitario_compra),
                                'fecha_entrega_estimada'     => $item['FechaEntrega'] ?? $detalleLocal->fecha_entrega_estimada,
                                'producto_nombre'            => $item['Producto'] ?? $detalleLocal->producto_nombre,
                            ]);
                        }
                    }

                    DB::commit();
                    $procesadas++;
                } catch (\Exception $e) {
                    DB::rollBack();
                    $errores++;
                    Log::error("[INDIGO-SYNC] Error orden {$numeroOrdenIndigo}: " . $e->getMessage());
                }
            }

            $stats = [
                'procesadas' => $procesadas,
                'nuevas' => $nuevas,
                'actualizadas' => $actualizadas,
                'errores' => $errores,
                'total_registros' => $registros->count(),
                'total_ordenes' => $ordenesAgrupadas->count(),
            ];

            Log::channel('daily')->info('[INDIGO-SYNC] Sincronización completada', $stats);

            return [
                'success' => true,
                'message' => "Sincronización exitosa. Procesadas: {$procesadas}, Nuevas: {$nuevas}, Actualizadas: {$actualizadas}",
                'stats' => $stats,
            ];
        } catch (\Exception $e) {
            Log::error('[INDIGO-SYNC] Error general: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error al sincronizar con Indigo: ' . $e->getMessage(),
                'stats' => ['procesadas' => 0, 'nuevas' => 0, 'actualizadas' => 0],
            ];
        }
    }

    /**
     * Procesar devolución de una orden desde Indigo.
     */
    public function processReturn(string $numeroOrdenIndigo, int $userId): array
    {
        $orden = InvOrdenCompra::where('oc_indigo', $numeroOrdenIndigo)->first();
        if (!$orden) {
            return ['success' => false, 'message' => 'Orden no encontrada localmente.'];
        }

        $orden->update(['estado' => 'CANCELADA']);

        Log::info("[INDIGO-SYNC] Devolución procesada: orden {$numeroOrdenIndigo}");

        return ['success' => true, 'message' => 'Devolución procesada'];
    }

    /**
     * Vincular una orden de Indigo con un pedido local existente.
     */
    public function linkOrderToPedido(string $numeroOrdenIndigo, int $pedidoId): array
    {
        $orden = InvOrdenCompra::where('oc_indigo', $numeroOrdenIndigo)->first();
        if (!$orden) {
            return ['success' => false, 'message' => 'Orden Indigo no encontrada'];
        }

        $pedido = InvPedido::find($pedidoId);
        if (!$pedido) {
            return ['success' => false, 'message' => 'Pedido no encontrado'];
        }

        // Vincular detalles por código de producto
        $detallesOrden = InvOrdenCompraDetalle::where('compra_id', $orden->id)->get();
        $detallesPedido = InvPedidoDetalle::where('pedido_id', $pedidoId)->get();

        $linked = 0;
        foreach ($detallesOrden as $detalleOC) {
            $match = $detallesPedido->first(fn($dp) =>
                $dp->codigo_producto === $detalleOC->codigo_producto_indigo
            );

            if ($match && !$detalleOC->pedido_detalle_id) {
                $detalleOC->update(['pedido_detalle_id' => $match->id]);
                $linked++;
            }
        }

        return [
            'success' => true,
            'message' => "Orden vinculada al pedido. {$linked} productos enlazados.",
            'linked' => $linked,
        ];
    }
}
