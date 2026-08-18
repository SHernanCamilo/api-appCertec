<?php

namespace App\Services\Inventory\Pharmacy;

use App\Models\Inventory\InvOrdenCompra;
use App\Models\Inventory\InvOrdenCompraDetalle;
use App\Models\Inventory\External\IndigoOrdenCompra;
use App\Services\Inventory\Pharmacy\InvSequenceService;
use App\Services\Inventory\Pharmacy\InvPedidoService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InvOrdenCompraService
{
    protected InvSequenceService $sequenceService;

    public function __construct(InvSequenceService $sequenceService)
    {
        $this->sequenceService = $sequenceService;
    }
    /**
     * Listar órdenes de compra.
     * Puede listar de la BD local o del ERP externo (Indigo) dependiendo del parámetro $filters['source'].
     */
    public function getAll(array $filters = []): array
    {
        if (($filters['source'] ?? '') === 'external') {
            return $this->getExternalOrders($filters);
        }

        return $this->getLocalOrders($filters);
    }

    /**
     * Obtener órdenes de la base de datos local
     */
    private function getLocalOrders(array $filters = []): array
    {
        $query = InvOrdenCompra::with(['detalles', 'creador']);

        if (!empty($filters['estado'])) {
            $query->where('estado', $filters['estado']);
        }
        if (!empty($filters['proveedor'])) {
            $query->where('proveedor_nombre', 'LIKE', '%' . $filters['proveedor'] . '%');
        }
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('numero_orden_compra', 'LIKE', "%{$search}%")
                  ->orWhere('proveedor_nombre', 'LIKE', "%{$search}%");
            });
        }

        $query->orderBy('id', 'desc');

        $limit  = isset($filters['limit']) ? max(1, (int) $filters['limit']) : 25;
        $offset = isset($filters['offset']) ? max(0, (int) $filters['offset']) : 0;
        
        $total = $query->count();
        $ordenes = $query->offset($offset)->limit($limit)->get();

        return [
            'success' => true,
            'data'    => $ordenes,
            'meta'    => [
                'total'  => $total,
                'limit'  => $limit,
                'offset' => $offset,
                'source' => 'local'
            ],
        ];
    }

    /**
     * Obtener órdenes de la vista SQL Server del ERP Indigo
     */
    private function getExternalOrders(array $filters = []): array
    {
        try {
            $query = IndigoOrdenCompra::query();

            // Filtrado genérico de ejemplo (dependiendo de las columnas reales de la vista)
            if (!empty($filters['search'])) {
                $search = $filters['search'];
                // Asumimos que la vista tiene columnas como "NumeroOrden", "ProveedorNombre" etc.
                // Se debe adaptar según el esquema real de `dbo.Inventory_OrdenesDeCompra`
                $query->where(function ($q) use ($search) {
                    $q->where('numero_orden', 'LIKE', "%{$search}%")
                      ->orWhere('numero_documento', 'LIKE', "%{$search}%")
                      ->orWhere('proveedor', 'LIKE', "%{$search}%");
                });
            }

            $limit  = isset($filters['limit']) ? max(1, (int) $filters['limit']) : 50;
            $offset = isset($filters['offset']) ? max(0, (int) $filters['offset']) : 0;
            
            // Para paginación en SQL Server es necesario un order by
            // Intentaremos ordenar por la primera columna disponible si no hay un id obvio
            $ordenes = $query->take($limit)->skip($offset)->get();
            
            // Como es vista, contarlas todas puede ser costoso, devolvemos cantidad recuperada
            $total = count($ordenes); 

            return [
                'success' => true,
                'data'    => $ordenes,
                'meta'    => [
                    'total'  => $total,
                    'limit'  => $limit,
                    'offset' => $offset,
                    'source' => 'external (Indigo)'
                ],
            ];
        } catch (\Exception $e) {
            Log::error('Error fetching external Indigo orders: ' . $e->getMessage());
            return [
                'success' => false, 
                'message' => 'Error al obtener órdenes del ERP Indigo', 
                'error'   => $e->getMessage(),
                'data'    => []
            ];
        }
    }

    /**
     * Obtener orden local por ID
     */
    public function getById(int $id): ?InvOrdenCompra
    {
        return InvOrdenCompra::with(['detalles', 'creador'])->find($id);
    }

    /**
     * Sincronizar una orden desde el ERP externo a local (opcional)
     * @param array $externalData Datos obtenidos de la vista externa
     */
    public function syncFromExternal(array $externalData, int $userId): array
    {
        DB::beginTransaction();
        try {
            $numeroOrden = $externalData['numero_orden_compra'] ?? $externalData['numero_orden'] ?? null;
            if (!$numeroOrden) {
                return ['success' => false, 'message' => 'Número de orden requerido'];
            }

            // Buscar si ya existe para no duplicar
            $orden = InvOrdenCompra::firstOrNew(['numero_orden_compra' => $numeroOrden]);

            $orden->fecha_orden = $externalData['fecha_orden'] ?? $externalData['fecha_emision'] ?? now();
            $orden->oc_indigo = $externalData['oc_indigo'] ?? null;
            $orden->observaciones = $externalData['observaciones'] ?? null;
            $orden->estado = $externalData['estado'] ?? 'EN_TRANSITO';
            
            if (!$orden->exists) {
                $orden->creado_por = $userId;
            }
            
            $orden->save();

            // Aquí se insertarían detalles si la vista los trae agrupados
            // $orden->detalles()->delete();
            // ... insert detalles ...

            DB::commit();

            return [
                'success' => true,
                'message' => 'Orden de compra sincronizada',
                'data'    => $orden->load('detalles')
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            return [
                'success' => false,
                'message' => 'Error al sincronizar orden',
                'error'   => $e->getMessage()
            ];
        }
    }

    /**
     * Cambiar estado de la orden local
     */
    public function cambiarEstado(int $id, string $nuevoEstado): array
    {
        $orden = InvOrdenCompra::find($id);
        
        if (!$orden) {
            return ['success' => false, 'message' => 'Orden de compra no encontrada'];
        }

        $nuevoEstado = strtoupper($nuevoEstado);
        $estadosPermitidos = ['BORRADOR', 'EN_TRANSITO', 'RECIBIDA_PARCIAL', 'RECIBIDA_TOTAL', 'CANCELADA'];
        
        if (!in_array($nuevoEstado, $estadosPermitidos)) {
            return ['success' => false, 'message' => 'Estado no válido'];
        }

        $orden->update(['estado' => $nuevoEstado]);

        return [
            'success' => true,
            'message' => 'Estado actualizado a ' . $nuevoEstado,
            'data'    => $orden->fresh()
        ];
    }

    /**
     * Crear orden de compra local
     */
    public function create(array $data, int $userId): array
    {
        DB::beginTransaction();
        try {
            $numeroOrden = $this->sequenceService->generateSequence('INVENTARIO', $userId, 'ORDEN_COMPRA');
            
            $orden = InvOrdenCompra::create([
                'numero_orden_compra' => $numeroOrden,
                'fecha_orden'         => $data['fecha_orden'] ?? now()->toDateString(),
                'observaciones'       => $data['observaciones'] ?? null,
                'estado'              => 'pendiente',
                'creado_por'          => $userId,
            ]);

            if (!empty($data['detalles']) && is_array($data['detalles'])) {
                foreach ($data['detalles'] as $detalle) {
                    InvOrdenCompraDetalle::create([
                        'compra_id'                  => $orden->id,
                        'pedido_detalle_id'          => $detalle['pedido_detalle_id'],
                        'proveedor'                  => $detalle['proveedor'] ?? 'N/A',
                        'cantidad_solicitada_compra' => $detalle['cantidad_solicitada_compra'],
                        'precio_unitario_compra'     => $detalle['precio_unitario_compra'] ?? null,
                        'estado'                     => 'pendiente'
                    ]);
                }
            }
            DB::commit();
            return ['success' => true, 'message' => 'Orden de compra creada', 'data' => $orden->load('detalles')];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error al crear OC: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error al crear orden de compra', 'error' => $e->getMessage()];
        }
    }

    /**
     * Confirmar la orden de compra y actualizar los pedidos relacionados
     */
    public function confirmPurchase(int $id, int $userId): array
    {
        $orden = InvOrdenCompra::with('detalles')->find($id);
        
        if (!$orden || $orden->estado !== 'pendiente') {
            return ['success' => false, 'message' => 'Orden no encontrada o no está en estado pendiente'];
        }

        DB::beginTransaction();
        try {
            $orden->update(['estado' => 'confirmado']);
            
            // Restar cantidades a los pedidos vinculados
            $detalles = $orden->detalles->toArray();
            $pedidoService = app(InvPedidoService::class);
            $res = $pedidoService->applyPurchaseToOrders($orden->id, $detalles, $userId);
            
            if (!$res['success']) {
                throw new \Exception($res['message']);
            }
            
            DB::commit();
            return ['success' => true, 'message' => 'Orden confirmada y pedidos actualizados', 'data' => $orden->fresh()];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error al confirmar OC: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error al confirmar la orden', 'error' => $e->getMessage()];
        }
    }
}
