<?php

namespace App\Services\Inventory\Pharmacy;

use App\Models\Inventory\InvOrdenCompra;
use App\Models\Inventory\InvOrdenCompraDetalle;
use App\Models\Inventory\InvPedidoDetalle;
use App\Models\Inventory\InvRecepcion;
use App\Models\Inventory\InvRecepcionDetalle;
use App\Services\Inventory\Pharmacy\InvimaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Servicio de Recepción Técnica de Farmacia.
 *
 * Maneja el proceso de recepción de productos farmacéuticos:
 * - Verificación de lote, fecha vencimiento, CUM/INVIMA
 * - Evaluación de aspecto, embalaje, contenido, cadena de frío
 * - Cálculo de muestra según ISO 2859-1
 * - Registro del concepto (aceptado/rechazado)
 */
class PharmacyReceptionService
{
    public function __construct(
        private readonly PharmacyService $pharmacyService,
        private readonly InvimaService $invimaService
    ) {}

    /**
     * Crear una nueva recepción técnica para una orden de compra.
     */
    public function crearRecepcion(int $compraId, int $userId, array $items = []): array
    {
        $orden = InvOrdenCompra::with('detalles')->find($compraId);
        if (!$orden) {
            return ['success' => false, 'message' => 'Orden de compra no encontrada'];
        }

        DB::beginTransaction();
        try {
            $recepcion = InvRecepcion::create([
                'compra_id' => $compraId,
                'numero_orden_compra' => $orden->numero_orden_compra,
                'oc_indigo' => $orden->oc_indigo,
                'fecha_recepcion' => now(),
                'recibido_por' => $userId,
                'total_items' => count($items),
                'estado' => 'recepcionado',
            ]);

            $detallesCreados = [];
            foreach ($items as $item) {
                $pedidoDetalleId = $item['pedido_detalle_id'] ?? null;
                $codigoProducto = $item['codigo_producto'] ?? '';
                $cantidadRecibida = (float) ($item['cantidad_recibida'] ?? 0);

                // Calcular muestra si tiene cantidad
                $muestra = null;
                if ($cantidadRecibida > 0) {
                    $muestra = $this->pharmacyService->calcularMuestra(
                        (int) $cantidadRecibida,
                        $codigoProducto
                    );
                }

                $detalle = InvRecepcionDetalle::create([
                    'recepcion_id' => $recepcion->id,
                    'pedido_detalle_id' => $pedidoDetalleId,
                    'codigo_producto' => $codigoProducto,
                    'producto_nombre' => $item['producto_nombre'] ?? '',
                    'cantidad_solicitada' => (float) ($item['cantidad_solicitada'] ?? 0),
                    'cantidad_recibida' => $cantidadRecibida,
                    'muestra_poblacion' => $muestra['tamano_muestra'] ?? null,
                    'numero_lote' => $item['numero_lote'] ?? null,
                    'fecha_vencimiento' => $item['fecha_vencimiento'] ?? null,
                    'codigo_sanitario' => $item['codigo_sanitario'] ?? null,
                    'aspecto_cumple' => $item['aspecto_cumple'] ?? null,
                    'embalaje_cumple' => $item['embalaje_cumple'] ?? null,
                    'contenido_cumple' => $item['contenido_cumple'] ?? null,
                    'cadena_frio_temperatura' => $item['cadena_frio_temperatura'] ?? null,
                    'concepto_recepcion' => $item['concepto_recepcion'] ?? null,
                    'es_medicamento_vital' => $item['es_medicamento_vital'] ?? false,
                    'observaciones_recepcion' => $item['observaciones'] ?? null,
                ]);

                // Si se recibió producto, actualizar el pedido_detalle
                if ($cantidadRecibida > 0 && $pedidoDetalleId) {
                    $this->actualizarCantidadRecibida($pedidoDetalleId, $cantidadRecibida, $item);
                }

                $detallesCreados[] = $detalle;
            }

            DB::commit();

            Log::info("[PHARMACY-RECEPTION] Recepción #{$recepcion->id} creada para OC {$orden->numero_orden_compra}", [
                'items' => count($detallesCreados),
                'usuario' => $userId,
            ]);

            return [
                'success' => true,
                'message' => "Recepción creada exitosamente con " . count($detallesCreados) . " items",
                'recepcion_id' => $recepcion->id,
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("[PHARMACY-RECEPTION] Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error al crear recepción: ' . $e->getMessage()];
        }
    }

    /**
     * Actualizar cantidad recibida en el detalle del pedido.
     */
    private function actualizarCantidadRecibida(int $pedidoDetalleId, float $cantidadRecibida, array $item): void
    {
        $pd = InvPedidoDetalle::find($pedidoDetalleId);
        if (!$pd) return;

        $updates = ['cantidad_recibida' => $cantidadRecibida];

        if (!empty($item['numero_lote'])) {
            $updates['numero_lote'] = $item['numero_lote'];
        }
        if (!empty($item['fecha_vencimiento'])) {
            $updates['fecha_vencimiento'] = $item['fecha_vencimiento'];
        }
        if (!empty($item['codigo_sanitario'])) {
            $updates['codigo_sanitario'] = $item['codigo_sanitario'];
            $updates['cum_recibido'] = $pd->codigo_producto;
        }
        if (isset($item['aspecto_cumple'])) {
            $updates['aspecto_cumple'] = $item['aspecto_cumple'];
        }
        if (isset($item['embalaje_cumple'])) {
            $updates['embalaje_cumple'] = $item['embalaje_cumple'];
        }
        if (isset($item['contenido_cumple'])) {
            $updates['contenido_cumple'] = $item['contenido_cumple'];
        }
        if (isset($item['cadena_frio_temperatura'])) {
            $updates['cadena_frio_temperatura'] = $item['cadena_frio_temperatura'];
        }
        if (!empty($item['concepto_recepcion'])) {
            $updates['concepto_recepcion'] = $item['concepto_recepcion'];
        }
        if (isset($item['recibido_por'])) {
            $updates['recibido_por'] = $item['recibido_por'];
        }

        // Determinar estado
        if ($cantidadRecibida >= $pd->cantidad_solicitada && $pd->cantidad_solicitada > 0) {
            $updates['estado'] = 'recibido';
        } elseif ($cantidadRecibida > 0) {
            $updates['estado'] = 'parcial';
        }

        $pd->update($updates);
    }

    /**
     * Validar registro INVIMA de un código sanitario.
     */
    public function validarInvima(string $codigoSanitario): array
    {
        try {
            return $this->invimaService->validateProduct($codigoSanitario);
        } catch (\Exception $e) {
            return [
                'valid' => false,
                'message' => 'Error al validar INVIMA: ' . $e->getMessage(),
            ];
        }
    }
}
