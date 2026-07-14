<?php

namespace App\Services\Inventory;

use App\Models\Inventory\External\IndigoOrdenCompra;
use App\Models\Inventory\InvOrdenCompra;
use App\Models\Inventory\InvOrdenCompraDetalle;
use App\Models\Inventory\InvPedido;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MonitoringService
{
    /**
     * Sincroniza órdenes de compra desde la vista de Indigo (SQL Server) hacia la base local.
     * Lee la vista externa y agrupa por número de orden.
     */
    public function syncIndigoOrders(int $userId = 1): array
    {
        Log::info("Iniciando sincronización de órdenes desde Indigo ERP...");
        
        try {
            // Traemos los registros de la vista externa.
            // Como la vista tiene un registro por cada producto de la orden, agruparemos en memoria.
            // En producción, es ideal filtrar por fecha para traer solo lo reciente: ->where('Fecha', '>=', now()->subDays(7))
            $registrosExternos = IndigoOrdenCompra::orderByDesc('Fecha')->take(1000)->get();
            
            // Agrupar por OrdenCompra (ID de Indigo)
            $ordenesAgrupadas = $registrosExternos->groupBy('OrdenCompra');
            
            $procesadas = 0;
            $nuevas = 0;
            $actualizadas = 0;

            foreach ($ordenesAgrupadas as $numeroOrdenIndigo => $detalles) {
                if (!$numeroOrdenIndigo) continue;

                // Tomamos la cabecera del primer registro
                $cabecera = $detalles->first();

                // Extraer el número de pedido interno (ej: FLA-2026-001) de la Descripción
                $descripcion = $cabecera->Descripcion_Orden ?? '';
                $numeroPedidoInterno = null;
                
                // Buscamos un patrón que parezca un número de pedido nuestro (ej: NVA-2026-001 o FLA-2026-001)
                if (preg_match('/([A-Z]{3}-\d{4}-\d{3,4})/', $descripcion, $matches)) {
                    $numeroPedidoInterno = $matches[1];
                }

                DB::beginTransaction();
                try {
                    $ordenLocal = InvOrdenCompra::where('oc_indigo', $numeroOrdenIndigo)->first();

                    if (!$ordenLocal) {
                        // Si encontramos a qué pedido pertenece, lo asociamos (opcional, si existe el campo en DB)
                        // $pedido = InvPedido::where('numero_pedido', $numeroPedidoInterno)->first();

                        // Crear nueva Orden de Compra Local
                        $ordenLocal = InvOrdenCompra::create([
                            // Generamos un número de orden local combinando el pedido o el ID de indigo
                            'numero_orden_compra' => $numeroPedidoInterno ? "{$numeroPedidoInterno}OC" : "IND-{$numeroOrdenIndigo}",
                            'fecha_orden'         => $cabecera->Fecha ?? now()->toDateString(),
                            'observaciones'       => $descripcion,
                            'estado'              => 'en_transito',
                            'sincronizado_indigo' => 1,
                            'creado_por'          => $userId,
                            'oc_indigo'           => $numeroOrdenIndigo,
                        ]);
                        $nuevas++;
                    } else {
                        // Actualizar cabecera existente si es necesario
                        $ordenLocal->update([
                            'sincronizado_indigo' => 1,
                            'observaciones'       => $descripcion,
                        ]);
                        $actualizadas++;
                    }

                    // Sincronizar detalles (productos)
                    $idsDetallesProcesados = [];
                    foreach ($detalles as $item) {
                        // Intentamos buscar si ya existe el detalle localmente
                        $detalleLocal = InvOrdenCompraDetalle::where('compra_id', $ordenLocal->id)
                            // En un caso real habría que cruzar por un ID de producto de Indigo
                            ->where('observaciones', 'LIKE', "%{$item->CodProducto}%") 
                            ->first();

                        if (!$detalleLocal) {
                            $detalleLocal = InvOrdenCompraDetalle::create([
                                'compra_id'                  => $ordenLocal->id,
                                'proveedor'                  => $item->Proveedor ?? null,
                                'cantidad_solicitada_compra' => $item->Cantidad ?? 0,
                                'fecha_entrega_estimada'     => $item->FechaEntrega ?? null,
                                'precio_unitario_compra'     => $item->CostoPromedio ?? 0,
                                // Guardamos info del producto en observaciones temporalmente o en un campo específico
                                'observaciones'              => "Cod: {$item->CodProducto} - {$item->Producto}",
                                'estado'                     => 'solicitado',
                            ]);
                        } else {
                            // Actualizar detalle
                            $detalleLocal->update([
                                'cantidad_solicitada_compra' => $item->Cantidad ?? $detalleLocal->cantidad_solicitada_compra,
                                'precio_unitario_compra'     => $item->CostoPromedio ?? $detalleLocal->precio_unitario_compra,
                                'fecha_entrega_estimada'     => $item->FechaEntrega ?? $detalleLocal->fecha_entrega_estimada,
                            ]);
                        }
                        $idsDetallesProcesados[] = $detalleLocal->id;
                    }

                    // Opcional: Eliminar detalles que ya no vienen de Indigo
                    // InvOrdenCompraDetalle::where('compra_id', $ordenLocal->id)
                    //     ->whereNotIn('id', $idsDetallesProcesados)
                    //     ->delete();

                    DB::commit();
                    $procesadas++;
                } catch (\Exception $e) {
                    DB::rollBack();
                    Log::error("Error sincronizando orden Indigo {$numeroOrdenIndigo}: " . $e->getMessage());
                }
            }

            Log::info("Sincronización terminada. Procesadas: {$procesadas}. Nuevas: {$nuevas}. Actualizadas: {$actualizadas}.");
            return [
                'success' => true,
                'message' => "Sincronización exitosa. Procesadas: {$procesadas}",
                'stats' => [
                    'procesadas' => $procesadas,
                    'nuevas' => $nuevas,
                    'actualizadas' => $actualizadas
                ]
            ];
        } catch (\Exception $e) {
            Log::error("Error general en syncIndigoOrders: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error al sincronizar con Indigo',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Procesar devoluciones que se reflejan en el ERP
     */
    public function processIndigoReturn(string $numeroOrdenIndigo): array
    {
        Log::info("Procesando devolución desde Indigo para orden {$numeroOrdenIndigo}");
        
        $orden = InvOrdenCompra::where('oc_indigo', $numeroOrdenIndigo)->first();
        if (!$orden) {
            return ['success' => false, 'message' => 'Orden no encontrada localmente.'];
        }

        // Lógica de devolución: cancelar la orden o ajustar cantidades recibidas
        $orden->update(['estado' => 'cancelada']);
        
        return ['success' => true, 'message' => 'Devolución procesada'];
    }
}