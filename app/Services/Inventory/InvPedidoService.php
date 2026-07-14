<?php

namespace App\Services\Inventory;

use App\Models\Inventory\InvPedido;
use App\Models\Inventory\InvPedidoDetalle;
use App\Models\Inventory\InvPedidoTrazabilidad;
use App\Services\Inventory\InvSequenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InvPedidoService
{
    protected InvSequenceService $sequenceService;

    public function __construct(InvSequenceService $sequenceService)
    {
        $this->sequenceService = $sequenceService;
    }

    /**
     * Obtener todos los pedidos con sus detalles y filtros
     */
    public function getAll(array $filters = []): array
    {
        $query = InvPedido::with(['detalles', 'solicitante', 'trazabilidad.usuario']);

        if (!empty($filters['estado'])) {
            $query->where('estado', $filters['estado']);
        }

        if (!empty($filters['proveedor'])) {
            $query->where('proveedor', 'LIKE', '%' . $filters['proveedor'] . '%');
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('numero_pedido', 'LIKE', "%{$search}%")
                  ->orWhere('proveedor', 'LIKE', "%{$search}%");
            });
        }

        $query->orderBy('id', 'desc');

        $limit  = isset($filters['limit']) ? max(1, (int) $filters['limit']) : 25;
        $offset = isset($filters['offset']) ? max(0, (int) $filters['offset']) : 0;
        
        $total = $query->count();
        $pedidos = $query->offset($offset)->limit($limit)->get();

        return [
            'success' => true,
            'data'    => $pedidos,
            'meta'    => [
                'total'  => $total,
                'limit'  => $limit,
                'offset' => $offset,
            ],
        ];
    }

    /**
     * Obtener un pedido específico con sus detalles
     */
    public function getById(int $id): ?InvPedido
    {
        return InvPedido::with(['detalles', 'solicitante', 'trazabilidad.usuario'])->find($id);
    }

    /**
     * Crear un nuevo pedido con sus detalles
     */
    public function create(array $data, int $userId): array
    {
        DB::beginTransaction();
        try {
            // Generar número de pedido (Ej: FLA-2026-001) usando InvSequenceService (wrapper de SecuenciaNumericaService)
            $numeroPedido = $this->sequenceService->generateSequence('INVENTARIO', $userId, 'PEDIDO');

            // Crear el pedido cabecera
            $pedido = InvPedido::create([
                'numero_pedido'  => $numeroPedido,
                'proveedor'      => $data['proveedor'] ?? null,
                'fecha_pedido'   => $data['fecha_pedido'] ?? now()->toDateString(),
                'fecha_esperada' => $data['fecha_esperada'] ?? null,
                'estado'         => 'BORRADOR', // Estado inicial
                'observaciones'  => $data['observaciones'] ?? null,
                'solicitado_por' => $userId,
                'total_articulos'=> count($data['detalles'] ?? [])
            ]);

            // Crear detalles
            if (!empty($data['detalles']) && is_array($data['detalles'])) {
                foreach ($data['detalles'] as $detalle) {
                    InvPedidoDetalle::create([
                        'pedido_id'           => $pedido->id,
                        'codigo_producto'     => $detalle['codigo_producto'] ?? null,
                        'producto_nombre'     => $detalle['producto_nombre'] ?? null,
                        'producto_tipo'       => $detalle['producto_tipo'] ?? null,
                        'cantidad_solicitada' => $detalle['cantidad_solicitada'] ?? 0,
                        'precio_unitario'     => $detalle['precio_unitario'] ?? 0,
                        'estado'              => 'PENDIENTE'
                    ]);
                }
            }

            DB::commit();

            // Trazabilidad
            InvPedidoTrazabilidad::create([
                'pedido_id' => $pedido->id,
                'estado' => 'BORRADOR',
                'comentarios' => 'Creación de pedido',
                'cambiado_por' => $userId
            ]);

            return [
                'success' => true,
                'message' => 'Pedido creado exitosamente',
                'data'    => $pedido->load(['detalles', 'trazabilidad.usuario'])
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error al crear pedido: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error al crear el pedido',
                'error'   => $e->getMessage()
            ];
        }
    }

    /**
     * Actualizar pedido (solo si está en borrador)
     */
    public function update(int $id, array $data): array
    {
        $pedido = InvPedido::find($id);
        
        if (!$pedido) {
            return ['success' => false, 'message' => 'Pedido no encontrado'];
        }

        if ($pedido->estado !== 'BORRADOR') {
            return ['success' => false, 'message' => 'Solo se pueden editar pedidos en estado BORRADOR'];
        }

        DB::beginTransaction();
        try {
            $pedido->update([
                'proveedor'      => $data['proveedor'] ?? $pedido->proveedor,
                'fecha_esperada' => $data['fecha_esperada'] ?? $pedido->fecha_esperada,
                'observaciones'  => $data['observaciones'] ?? $pedido->observaciones,
            ]);

            // Si se envían detalles, reemplazarlos
            if (isset($data['detalles']) && is_array($data['detalles'])) {
                $pedido->detalles()->delete(); // Borrar actuales
                $pedido->update(['total_articulos' => count($data['detalles'])]);
                
                foreach ($data['detalles'] as $detalle) {
                    InvPedidoDetalle::create([
                        'pedido_id'           => $pedido->id,
                        'codigo_producto'     => $detalle['codigo_producto'] ?? null,
                        'producto_nombre'     => $detalle['producto_nombre'] ?? null,
                        'producto_tipo'       => $detalle['producto_tipo'] ?? null,
                        'cantidad_solicitada' => $detalle['cantidad_solicitada'] ?? 0,
                        'precio_unitario'     => $detalle['precio_unitario'] ?? 0,
                        'estado'              => 'PENDIENTE'
                    ]);
                }
            }

            DB::commit();

            return [
                'success' => true,
                'message' => 'Pedido actualizado exitosamente',
                'data'    => $pedido->load('detalles')
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error al actualizar pedido: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error al actualizar el pedido',
                'error'   => $e->getMessage()
            ];
        }
    }

    /**
     * Cambiar estado del pedido
     */
    public function cambiarEstado(int $id, string $nuevoEstado, int $userId): array
    {
        $pedido = InvPedido::find($id);
        
        if (!$pedido) {
            return ['success' => false, 'message' => 'Pedido no encontrado'];
        }

        $nuevoEstado = strtoupper($nuevoEstado);
        $estadosPermitidos = ['BORRADOR', 'SOLICITADO', 'APROBADO', 'EN_TRANSITO', 'RECIBIDO', 'CANCELADO'];
        
        if (!in_array($nuevoEstado, $estadosPermitidos)) {
            return ['success' => false, 'message' => 'Estado no válido'];
        }

        // Lógica para registrar quién hace el cambio
        $updates = ['estado' => $nuevoEstado];
        
        if ($nuevoEstado === 'APROBADO') {
            $updates['aprobado_por'] = $userId;
        } elseif ($nuevoEstado === 'CANCELADO') {
            $updates['cancelado_por'] = $userId;
        } elseif ($nuevoEstado === 'RECIBIDO') {
            $updates['recibido_por'] = $userId;
            $updates['fecha_recibido'] = now()->toDateString();
        }

        $pedido->update($updates);

        InvPedidoTrazabilidad::create([
            'pedido_id' => $pedido->id,
            'estado' => $nuevoEstado,
            'comentarios' => 'Cambio de estado a ' . $nuevoEstado,
            'cambiado_por' => $userId
        ]);

        return [
            'success' => true,
            'message' => 'Estado del pedido actualizado a ' . $nuevoEstado,
            'data'    => $pedido->fresh(['detalles', 'trazabilidad.usuario'])
        ];
    }

    /**
     * Eliminar (cancelar) un pedido
     */
    public function destroy(int $id, int $userId): array
    {
        return $this->cambiarEstado($id, 'CANCELADO', $userId);
    }

    /**
     * Confirmar un pedido (pasa a SOLICITADO para que compras lo vea)
     */
    public function confirmOrder(int $id, int $userId): array
    {
        $pedido = InvPedido::find($id);
        
        if (!$pedido) {
            return ['success' => false, 'message' => 'Pedido no encontrado'];
        }

        if ($pedido->estado !== 'BORRADOR') {
            return ['success' => false, 'message' => 'Solo se pueden confirmar pedidos en estado BORRADOR'];
        }

        return $this->cambiarEstado($id, 'SOLICITADO', $userId);
    }

    /**
     * Aprobar un pedido formalmente
     */
    public function approveOrder(int $id, int $userId): array
    {
        $pedido = InvPedido::find($id);
        
        if (!$pedido) {
            return ['success' => false, 'message' => 'Pedido no encontrado'];
        }

        if ($pedido->estado !== 'SOLICITADO') {
            return ['success' => false, 'message' => 'Solo se pueden aprobar pedidos en estado SOLICITADO'];
        }

        return $this->cambiarEstado($id, 'APROBADO', $userId);
    }

    /**
     * Restar cantidades compradas de las cantidades solicitadas del pedido.
     * Actualiza el estado de las líneas del pedido y, si aplica, el estado general.
     */
    public function applyPurchaseToOrders(int $compraId, array $detallesAComprar, int $userId): array
    {
        DB::beginTransaction();
        try {
            foreach ($detallesAComprar as $item) {
                if (!isset($item['pedido_detalle_id']) || !isset($item['cantidad_solicitada_compra'])) {
                    continue;
                }

                $detallePedido = InvPedidoDetalle::lockForUpdate()->find($item['pedido_detalle_id']);
                if (!$detallePedido) {
                    throw new \Exception("Detalle de pedido {$item['pedido_detalle_id']} no encontrado.");
                }

                // Acumular cantidades compradas previamente más la nueva
                // NOTA: Como en la BD original no existía un campo 'cantidad_comprada' en inv_pedido_detalles,
                // debemos calcularlo sumando los detalles de compras asociados o agregarlo.
                // Aquí calculamos sumando las OCs que no estén canceladas:
                $compradoAnteriormente = DB::table('inv_orden_compra_detalles')
                    ->join('inv_ordenes_compra', 'inv_orden_compra_detalles.compra_id', '=', 'inv_ordenes_compra.id')
                    ->where('inv_orden_compra_detalles.pedido_detalle_id', $detallePedido->id)
                    ->where('inv_orden_compra_detalles.compra_id', '!=', $compraId)
                    ->where('inv_ordenes_compra.estado', '!=', 'cancelada')
                    ->sum('inv_orden_compra_detalles.cantidad_solicitada_compra');

                $totalComprado = $compradoAnteriormente + $item['cantidad_solicitada_compra'];

                if ($totalComprado >= $detallePedido->cantidad_solicitada) {
                    $detallePedido->estado = 'COMPLETO';
                } elseif ($totalComprado > 0) {
                    $detallePedido->estado = 'PARCIAL';
                }
                $detallePedido->save();

                // Revisar el estado general del pedido asociado a este detalle
                $this->evaluarEstadoGeneralPedido($detallePedido->pedido_id, $userId);
            }

            DB::commit();
            return ['success' => true];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error en applyPurchaseToOrders: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Evalúa si todas las líneas del pedido están completas o parciales 
     * para cambiar el estado del pedido general.
     */
    private function evaluarEstadoGeneralPedido(int $pedidoId, int $userId): void
    {
        $detalles = InvPedidoDetalle::where('pedido_id', $pedidoId)->get();
        $total = $detalles->count();
        $completos = $detalles->where('estado', 'COMPLETO')->count();
        $parciales = $detalles->where('estado', 'PARCIAL')->count();

        $pedido = InvPedido::find($pedidoId);
        if (!$pedido || in_array($pedido->estado, ['CANCELADO', 'RECIBIDO'])) {
            return;
        }

        $nuevoEstado = $pedido->estado;
        if ($completos == $total) {
            $nuevoEstado = 'EN_TRANSITO';
        } elseif ($completos > 0 || $parciales > 0) {
            $nuevoEstado = 'EN_TRANSITO'; // O un estado intermedio
        }

        if ($nuevoEstado !== $pedido->estado) {
            $this->cambiarEstado($pedidoId, $nuevoEstado, $userId);
        }
    }
}
