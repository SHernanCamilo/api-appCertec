<?php

namespace App\Services\Inventory;

use App\Models\Inventory\InvPedido;
use App\Models\Inventory\InvPedidoDetalle;
use App\Models\Inventory\InvPedidoTrazabilidad;
use App\Models\Inventory\InvSecuencia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InvPedidoService
{
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
            // Generar número de pedido (Ej: PED-2024-001)
            $anoActual = date('Y');
            $secuencia = InvSecuencia::firstOrCreate(
                ['tipo_documento' => 'PEDIDO', 'ano' => $anoActual],
                ['ultimo_numero' => 0]
            );
            $secuencia->increment('ultimo_numero');
            $numeroPedido = sprintf('PED-%s-%04d', $anoActual, $secuencia->ultimo_numero);

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
}
