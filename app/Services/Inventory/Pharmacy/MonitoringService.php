<?php

namespace App\Services\Inventory\Pharmacy;

use App\Models\Inventory\InvOrdenCompra;
use App\Models\Inventory\InvOrdenCompraDetalle;
use App\Models\Inventory\InvPedido;
use App\Models\Inventory\InvPedidoDetalle;
use App\Models\Inventory\InvPedidoTrazabilidad;
use App\Models\Inventory\InvIndigoItem;
use App\Models\Inventory\InvIndigoTrazabilidad;
use App\Models\Inventory\InvIndigoEvento;
use App\Models\Inventory\InvCompraAuditoria;
use App\Services\Inventory\FabricInventoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Servicio de sincronización de órdenes de compra desde Indigo ERP.
 */
class MonitoringService
{
    public function __construct(
        private readonly FabricInventoryService $fabricService
    ) {}

    /**
     * Sincroniza órdenes de compra desde Fabric (vista Indigo) hacia la BD local.
     */
    public function syncIndigoOrders(int $userId = 1, array $options = []): array
    {
        $fechaDesde = $options['fecha_desde'] ?? now()->subDays(7)->format('Y-m-d');
        $limit = $options['limit'] ?? 2000;
        $numeroOrden = $options['numero_orden'] ?? null;

        Log::channel('daily')->info('[INDIGO-SYNC] Iniciando sincronización', [
            'fecha_desde' => $fechaDesde,
            'limit' => $limit,
            'numero_orden' => $numeroOrden,
        ]);

        try {
            $queryParams = ['limit' => $limit];
            if ($numeroOrden) {
                $queryParams['numero_orden'] = $numeroOrden;
            } else {
                $queryParams['fecha_desde'] = $fechaDesde;
            }

            $result = $this->fabricService->getIndigoOrders($queryParams);

            if (!$result['success'] || empty($result['data'])) {
                Log::channel('daily')->warning('[INDIGO-SYNC] Sin datos o error', [
                    'message' => $result['message'] ?? 'Sin registros',
                ]);
                return [
                    'success' => false,
                    'message' => $result['message'] ?? 'Error al obtener datos de Indigo',
                    'stats' => ['procesadas' => 0, 'nuevas' => 0, 'actualizadas' => 0, 'devoluciones' => 0],
                ];
            }

            $registros = collect($result['data']);
            $ordenesAgrupadas = $registros->groupBy('OrdenCompra');

            $procesadas = 0;
            $nuevas = 0;
            $actualizadas = 0;
            $devoluciones = 0;
            $errores = 0;

            foreach ($ordenesAgrupadas as $numeroOrdenIndigo => $detalles) {
                if (!$numeroOrdenIndigo) continue;

                $cabecera = $detalles->first();
                $descripcion = $cabecera['Descripcion_Orden'] ?? $cabecera['Descripcion'] ?? '';
                $numeroPedidoInterno = null;
                if (preg_match('/([A-Z]{3}-\d{4}-\d{3,6})/', $descripcion, $matches)) {
                    $numeroPedidoInterno = $matches[1];
                }

                DB::beginTransaction();
                try {
                    $ordenLocal = InvOrdenCompra::where('oc_indigo', $numeroOrdenIndigo)->first();
                    $esNueva = false;

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
                        $esNueva = true;
                    } else {
                        $ordenLocal->update([
                            'sincronizado_indigo' => true,
                            'observaciones'       => $descripcion,
                            'proveedor_nombre'    => $cabecera['Proveedor'] ?? $ordenLocal->proveedor_nombre,
                        ]);
                        $actualizadas++;
                    }

                    // Intentar vincular a pedido si existe
                    $pedido = $numeroPedidoInterno ? InvPedido::where('numero_pedido', $numeroPedidoInterno)->first() : null;
                    if ($esNueva && $pedido) {
                        // Relación N:N
                        DB::table('inv_compras_pedidos')->insertOrIgnore([
                            'compra_id' => $ordenLocal->id,
                            'pedido_id' => $pedido->id,
                        ]);
                    }

                    // Sincronizar detalles (productos de la orden)
                    foreach ($detalles as $item) {
                        $codProducto = $item['CodProducto'] ?? $item['Codigo'] ?? '';
                        if (!$codProducto) continue;
                        
                        $cantidadIndigo = (float) ($item['Cantidad'] ?? 0);
                        $codigoDevolucion = trim((string)($item['CodigoDevolucion'] ?? ''));
                        $cantidadDevuelta = (float)($item['CantidadesDevueltas'] ?? 0);
                        $fechaDevolucion = $item['FechaDevolucion'] ?? null;
                        $usuarioDevolucion = $item['UsuarioConfirmaDevolucion'] ?? null;
                        $hasReturnMeta = $codigoDevolucion !== '' || $cantidadDevuelta > 0 || !empty($fechaDevolucion) || !empty($usuarioDevolucion);

                        $detalleLocal = InvOrdenCompraDetalle::where('compra_id', $ordenLocal->id)
                            ->where('codigo_producto_indigo', $codProducto)
                            ->first();

                        $pedidoDetalleId = null;
                        if ($pedido && !$detalleLocal) {
                            $match = InvPedidoDetalle::where('pedido_id', $pedido->id)->where('codigo_producto', $codProducto)->first();
                            if ($match) {
                                $pedidoDetalleId = $match->id;
                            }
                        } elseif ($detalleLocal) {
                            $pedidoDetalleId = $detalleLocal->pedido_detalle_id;
                        }

                        // Calcular cantidad neta real
                        $cantidadNeta = $cantidadIndigo;
                        if ($cantidadDevuelta > 0) {
                            if ($cantidadDevuelta > $cantidadIndigo && $cantidadIndigo > 0) {
                                $cantidadDevuelta = $cantidadIndigo;
                            }
                            $cantidadNeta = max(0, $cantidadIndigo - $cantidadDevuelta);
                        }

                        if (!$detalleLocal) {
                            if ($hasReturnMeta) {
                                // Devolucion detectada pero sin compra registrada localmente, la omitimos
                                InvIndigoEvento::create([
                                    'numero_pedido' => $numeroPedidoInterno,
                                    'orden_compra' => $numeroOrdenIndigo,
                                    'codigo_producto' => $codProducto,
                                    'nivel' => 'warning',
                                    'mensaje' => 'Devolución detectada sin detalle de compra previo'
                                ]);
                                continue;
                            }
                            
                            $detalleLocal = InvOrdenCompraDetalle::create([
                                'compra_id'                  => $ordenLocal->id,
                                'pedido_detalle_id'          => $pedidoDetalleId,
                                'codigo_producto_indigo'     => $codProducto,
                                'producto_nombre'            => $item['Producto'] ?? $item['NombreProducto'] ?? '',
                                'proveedor'                  => $item['Proveedor'] ?? null,
                                'cantidad_solicitada_compra' => $cantidadNeta,
                                'fecha_entrega_estimada'     => $item['FechaEntrega'] ?? null,
                                'precio_unitario_compra'     => (float) ($item['CostoPromedio'] ?? 0),
                                'observaciones'              => "Sincronizado de Indigo",
                                'estado'                     => 'solicitado',
                            ]);
                        } else {
                            if ($hasReturnMeta) {
                                $this->processReturn([
                                    'numero_pedido' => $numeroPedidoInterno,
                                    'pedido_id' => $pedido->id ?? null,
                                    'pedido_detalle_id' => $pedidoDetalleId,
                                    'oc_indigo' => $numeroOrdenIndigo,
                                    'codigo_producto' => $codProducto,
                                    'cantidad_indigo' => $cantidadIndigo,
                                    'cantidad_devuelta' => $cantidadDevuelta,
                                    'codigo_devolucion' => $codigoDevolucion,
                                    'fecha_devolucion' => $fechaDevolucion,
                                    'usuario_devolucion' => $usuarioDevolucion,
                                    'purchase_detail' => $detalleLocal,
                                    'has_return_meta' => $hasReturnMeta
                                ], $userId);
                                $devoluciones++;
                            } else {
                                $detalleLocal->update([
                                    'cantidad_solicitada_compra' => $cantidadNeta,
                                    'precio_unitario_compra'     => (float) ($item['CostoPromedio'] ?? $detalleLocal->precio_unitario_compra),
                                    'fecha_entrega_estimada'     => $item['FechaEntrega'] ?? $detalleLocal->fecha_entrega_estimada,
                                    'producto_nombre'            => $item['Producto'] ?? $detalleLocal->producto_nombre,
                                ]);
                            }
                        }
                        
                        // Guardar log en tabla IndigoItem
                        InvIndigoItem::updateOrCreate(
                            [
                                'orden_compra' => $numeroOrdenIndigo,
                                'codigo_producto' => $codProducto,
                                'numero_pedido' => $numeroPedidoInterno ?? 'N/A'
                            ],
                            [
                                'pedido_id' => $pedido->id ?? null,
                                'pedido_detalle_id' => $pedidoDetalleId,
                                'proveedor' => $item['Proveedor'] ?? null,
                                'cantidad_origen' => $cantidadIndigo,
                                'cantidad_aplicada' => $cantidadNeta,
                                'fecha_indigo' => $cabecera['Fecha'] ?? null,
                                'estado_orden' => $cabecera['Estado_Orden'] ?? null,
                                'descripcion_orden' => $descripcion,
                            ]
                        );
                    }

                    // Trazabilidad
                    InvIndigoTrazabilidad::updateOrCreate(
                        ['numero_pedido' => $numeroPedidoInterno ?? 'N/A'],
                        [
                            'estado_indigo' => $cabecera['Estado_Orden'] ?? 'pendiente',
                            'fecha_sincronizacion' => now(),
                        ]
                    );

                    DB::commit();
                    $procesadas++;
                } catch (\Exception $e) {
                    DB::rollBack();
                    $errores++;
                    Log::error("[INDIGO-SYNC] Error orden {$numeroOrdenIndigo}: " . $e->getMessage());
                    InvIndigoEvento::create([
                        'numero_pedido' => $numeroPedidoInterno,
                        'orden_compra' => $numeroOrdenIndigo,
                        'nivel' => 'error',
                        'mensaje' => $e->getMessage()
                    ]);
                }
            }

            $stats = [
                'procesadas' => $procesadas,
                'nuevas' => $nuevas,
                'actualizadas' => $actualizadas,
                'devoluciones' => $devoluciones,
                'errores' => $errores,
                'total_registros' => $registros->count(),
                'total_ordenes' => $ordenesAgrupadas->count(),
            ];

            Log::channel('daily')->info('[INDIGO-SYNC] Sincronización completada', $stats);

            return [
                'success' => true,
                'message' => "Sincronización exitosa. Procesadas: {$procesadas}, Nuevas: {$nuevas}, Actualizadas: {$actualizadas}, Devoluciones: {$devoluciones}",
                'stats' => $stats,
            ];
        } catch (\Exception $e) {
            Log::error('[INDIGO-SYNC] Error general: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error al sincronizar con Indigo: ' . $e->getMessage(),
                'stats' => ['procesadas' => 0, 'nuevas' => 0, 'actualizadas' => 0, 'devoluciones' => 0],
            ];
        }
    }

    /**
     * Procesar devolución de una orden desde Indigo.
     */
    private function processReturn(array $context, int $userId): bool
    {
        $detalleLocal = $context['purchase_detail'] ?? null;
        if (!$detalleLocal) return false;

        $orden = InvOrdenCompra::find($detalleLocal->compra_id);
        if (!$orden || $orden->estado === 'RECIBIDA') {
            return false; // Ya fue recibida
        }

        $cantidadActual = (float) $detalleLocal->cantidad_solicitada_compra;
        $cantidadIndigo = (float) ($context['cantidad_indigo'] ?? $cantidadActual);
        $cantidadDevuelta = (float) ($context['cantidad_devuelta'] ?? 0);

        if ($cantidadDevuelta <= 0) {
            $cantidadDevuelta = $cantidadActual - $cantidadIndigo;
            if ($cantidadDevuelta <= 0) return false;
        }

        $cantidadNueva = max(0, $cantidadActual - $cantidadDevuelta);

        // Actualizar detalle OC
        $nuevoEstadoDetalle = $cantidadNueva <= 0 ? 'cancelada' : $detalleLocal->estado;
        $detalleLocal->update([
            'cantidad_solicitada_compra' => $cantidadNueva,
            'estado' => $nuevoEstadoDetalle
        ]);

        // Actualizar detalle Pedido si existe
        if ($context['pedido_detalle_id']) {
            $this->updatePedidoDetailForReturn((int) $context['pedido_detalle_id']);
        }

        // Si se cancela toda la orden de compra
        $wasCanceled = $this->updatePurchaseStatusIfReturned($orden->id, $userId);

        $comentario = "Devolución Indigo OC {$context['oc_indigo']} | Producto {$context['codigo_producto']} | Devuelto {$cantidadDevuelta} | Cantidad nueva {$cantidadNueva}";
        if ($wasCanceled) {
            $comentario .= ' | Orden de compra devuelta completa';
        }

        $codigoDevolucion = trim((string)($context['codigo_devolucion'] ?? ''));
        if ($codigoDevolucion !== '') {
            $comentario .= ' | Código devolución ' . $codigoDevolucion;
        }

        if ($context['pedido_id']) {
            InvPedidoTrazabilidad::create([
                'pedido_id' => $context['pedido_id'],
                'estado' => 'EN_PROCESO', // Retorna a proceso si es parcial
                'comentarios' => $comentario,
                'cambiado_por' => $userId
            ]);
        }
        
        Log::info("[INDIGO-SYNC] Devolución procesada: {$comentario}");
        return true;
    }
    
    private function updatePedidoDetailForReturn(int $pedidoDetalleId)
    {
        $pd = InvPedidoDetalle::find($pedidoDetalleId);
        if (!$pd) return;
        
        $totalEnOc = InvOrdenCompraDetalle::where('pedido_detalle_id', $pedidoDetalleId)
                        ->where('estado', '!=', 'cancelada')
                        ->whereHas('ordenCompra', function($q) {
                            $q->where('estado', '!=', 'CANCELADA');
                        })
                        ->sum('cantidad_solicitada_compra');
                        
        $nuevoEstado = 'pendiente';
        if ($totalEnOc >= $pd->cantidad_solicitada && $pd->cantidad_solicitada > 0) {
            $nuevoEstado = 'en_transito';
        } elseif ($totalEnOc > 0) {
            $nuevoEstado = 'parcial';
        }
        
        $pd->update(['estado' => $nuevoEstado]);
    }
    
    private function updatePurchaseStatusIfReturned(int $compraId, int $userId): bool
    {
        $orden = InvOrdenCompra::find($compraId);
        if (!$orden) return false;
        
        $detalles = InvOrdenCompraDetalle::where('compra_id', $compraId)->get();
        $total = $detalles->count();
        $cancelados = $detalles->where('estado', 'cancelada')->count();
        
        if ($total > 0 && $cancelados >= $total) {
            $previous = $orden->estado;
            $orden->update(['estado' => 'CANCELADA']);
            
            InvCompraAuditoria::create([
                'compra_id' => $compraId,
                'campo_modificado' => 'estado',
                'valor_anterior' => $previous,
                'valor_nuevo' => 'CANCELADA',
                'motivo_modificacion' => 'Orden de compra devuelta completa desde Indigo',
                'modificado_por' => $userId
            ]);
            
            $this->registerPurchaseCancellation($compraId, $orden->numero_orden_compra, 'Devuelta completa desde Indigo', $userId);
            return true;
        }
        return false;
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

        DB::table('inv_compras_pedidos')->insertOrIgnore([
            'compra_id' => $orden->id,
            'pedido_id' => $pedido->id,
        ]);

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
    
    // --- EVENTOS / NOTIFICACIONES ---
    
    public function registerPurchaseConfirmation(int $purchaseId, int $userId): void
    {
        Log::info("[INVENTORY-EVENT] registerPurchaseConfirmation OC: {$purchaseId} por User: {$userId}");
        // TODO: Enviar a NotificationService si se requiere UI/Email
    }

    public function registerOrderCancellation(int $orderId, string $orderNumber, string $reason, int $userId): void
    {
        Log::info("[INVENTORY-EVENT] registerOrderCancellation Pedido: {$orderNumber} Motivo: {$reason}");
    }

    public function registerPurchaseCancellation(int $purchaseId, string $purchaseNumber, string $reason, int $userId): void
    {
        Log::info("[INVENTORY-EVENT] registerPurchaseCancellation OC: {$purchaseNumber} Motivo: {$reason}");
    }

    public function registerIncompleteReception(int $purchaseId, string $purchaseNumber, int $totalItems, int $receivedItems, int $userId): void
    {
        $missingItems = $totalItems - $receivedItems;
        Log::warning("[INVENTORY-EVENT] registerIncompleteReception OC: {$purchaseNumber} Faltan: {$missingItems} de {$totalItems}");
    }
}
