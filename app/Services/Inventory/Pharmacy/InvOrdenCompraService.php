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
        $estado = strtolower((string) ($filters['estado'] ?? $filters['status'] ?? ''));

        if ($estado !== '') {
            $query->whereRaw('LOWER(estado) = ?', [$estado]);
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
            $orden->estado = $externalData['estado'] ?? 'en_transito';
            
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

        $nuevoEstado = strtoupper(trim($nuevoEstado));
        $estadosPermitidos = [
            'BORRADOR' => 'pendiente',
            'PENDIENTE' => 'pendiente',
            'EN_TRANSITO' => 'en_transito',
            'EN_SITIO' => 'en_sitio',
            'RECIBIDA' => 'recibida',
            'RECIBIDO' => 'recibida',
            'RECIBIDA_TOTAL' => 'recibida',
            'RECIBIDA_PARCIAL' => 'en_sitio',
            'CANCELADA' => 'cancelada',
            'CANCELADO' => 'cancelada',
            'CONFIRMADO' => 'confirmado',
        ];
        
        if (!isset($estadosPermitidos[$nuevoEstado])) {
            return ['success' => false, 'message' => 'Estado no válido'];
        }

        $estadoPersistido = $estadosPermitidos[$nuevoEstado];
        $orden->update(['estado' => $estadoPersistido]);

        return [
            'success' => true,
            'message' => 'Estado actualizado a ' . strtoupper($estadoPersistido),
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
            $sucursalId = isset($data['sucursal_id']) ? (int) $data['sucursal_id'] : null;
            $numeroOrden = $this->sequenceService->generateSequence('INV', $userId, 'INV-ORDEN_COMPRA', $sucursalId);
            
            $orden = InvOrdenCompra::create([
                'numero_orden_compra' => $numeroOrden,
                'fecha_orden'         => $data['fecha_orden'] ?? now()->toDateString(),
                'observaciones'       => $data['observaciones'] ?? null,
                'proveedor_nombre'    => $data['proveedor_nombre'] ?? $data['proveedor'] ?? null,
                'estado'              => 'pendiente',
                'sincronizado_indigo' => 0,
                'sucursal_id'         => $sucursalId,
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
        // (validación de estado arriba; la confirmación sí se permite para OC
        //  sincronizadas porque el flujo de recepción parte de OC de Indigo)
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

    /**
     * Actualizar una orden de compra local.
     *
     * Reglas de negocio (solicitadas):
     *  - Solo se editan órdenes creadas desde el aplicativo (no sincronizadas de Indigo).
     *  - Solo su creador puede editarla.
     *  - Solo mientras está en estado 'pendiente'.
     * Las órdenes sincronizadas se actualizan únicamente por la sincronización de Indigo.
     */
    public function update(int $id, array $data, int $userId): array
    {
        $orden = InvOrdenCompra::with('detalles')->find($id);
        if (!$orden) {
            return ['success' => false, 'message' => 'Orden de compra no encontrada', 'code' => 404];
        }

        if ($orden->es_sincronizada) {
            return [
                'success' => false,
                'code'    => 403,
                'message' => 'Esta orden proviene de Indigo. Solo puede modificarse desde la sincronización, no manualmente.',
            ];
        }

        if ((int) $orden->creado_por !== $userId) {
            return [
                'success' => false,
                'code'    => 403,
                'message' => 'Solo el usuario que creó la orden puede editarla.',
            ];
        }

        if (strtolower((string) $orden->estado) !== 'pendiente') {
            return [
                'success' => false,
                'code'    => 409,
                'message' => 'Solo se pueden editar órdenes en estado pendiente.',
            ];
        }

        DB::beginTransaction();
        try {
            $orden->update([
                'fecha_orden'      => $data['fecha_orden'] ?? $orden->fecha_orden,
                'observaciones'    => $data['observaciones'] ?? $orden->observaciones,
                'proveedor_nombre' => $data['proveedor_nombre'] ?? $data['proveedor'] ?? $orden->proveedor_nombre,
            ]);

            // Reemplazar detalles si vienen en el payload
            if (isset($data['detalles']) && is_array($data['detalles'])) {
                $orden->detalles()->delete();
                foreach ($data['detalles'] as $detalle) {
                    InvOrdenCompraDetalle::create([
                        'compra_id'                  => $orden->id,
                        'pedido_detalle_id'          => $detalle['pedido_detalle_id'] ?? null,
                        'codigo_producto_indigo'     => $detalle['codigo_producto_indigo'] ?? $detalle['codigo_producto'] ?? null,
                        'producto_nombre'            => $detalle['producto_nombre'] ?? null,
                        'proveedor'                  => $detalle['proveedor'] ?? 'N/A',
                        'cantidad_solicitada_compra' => $detalle['cantidad_solicitada_compra'] ?? 0,
                        'precio_unitario_compra'     => $detalle['precio_unitario_compra'] ?? null,
                        'estado'                     => 'pendiente',
                    ]);
                }
            }

            DB::commit();
            return ['success' => true, 'message' => 'Orden de compra actualizada', 'data' => $orden->fresh('detalles')];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error al actualizar OC: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error al actualizar la orden', 'error' => $e->getMessage()];
        }
    }

    /**
     * Eliminar una orden de compra local (mismas reglas que la edición).
     */
    public function delete(int $id, int $userId): array
    {
        $orden = InvOrdenCompra::find($id);
        if (!$orden) {
            return ['success' => false, 'message' => 'Orden de compra no encontrada', 'code' => 404];
        }

        if ($orden->es_sincronizada) {
            return [
                'success' => false,
                'code'    => 403,
                'message' => 'No se pueden eliminar órdenes sincronizadas desde Indigo.',
            ];
        }

        if ((int) $orden->creado_por !== $userId) {
            return ['success' => false, 'code' => 403, 'message' => 'Solo el creador puede eliminar la orden.'];
        }

        if (strtolower((string) $orden->estado) !== 'pendiente') {
            return ['success' => false, 'code' => 409, 'message' => 'Solo se pueden eliminar órdenes en estado pendiente.'];
        }

        DB::beginTransaction();
        try {
            $orden->detalles()->delete();
            $orden->delete();
            DB::commit();
            return ['success' => true, 'message' => 'Orden de compra eliminada'];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error al eliminar OC: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Error al eliminar la orden', 'error' => $e->getMessage()];
        }
    }

    /**
     * Listar las sucursales disponibles para el selector de inventario.
     *
     * El módulo de inventario farmacia opera sobre una sola empresa
     * (Clínica Medilaser). Además, solo tiene sentido ofrecer las sucursales
     * que YA tienen prefijo/secuencia parametrizada, para no mostrar decenas de
     * sucursales duplicadas de otras empresas (problema visto en el selector).
     */
    public function getSucursalesDisponibles(int $userId): array
    {
        $empresaId = (int) config('inventory.empresa_id', 1);

        // Preferir las sucursales que YA tienen secuencia de inventario parametrizada
        // (existe un config_sec_detalles con patrón para esa sucursal). Es la fuente de
        // verdad: son exactamente las sucursales que pueden generar consecutivo.
        $sucursalIdsConSecuencia = DB::table('config_sec_detalles as d')
            ->join('config_sec_secuencias as s', 's.id', '=', 'd.secuencia_id')
            ->join('seg_modulos as m', 'm.id', '=', 's.modulo_id')
            ->where('s.empresa_id', $empresaId)
            ->where('m.codigo', 'INV')
            ->whereNull('d.deleted_at')
            ->where('d.estado', true)
            ->whereNotNull('d.sucursal_id')
            ->pluck('d.sucursal_id')
            ->unique()
            ->values()
            ->all();

        $query = \App\Models\Sucursal::where('id_Empresa', $empresaId);

        if (!empty($sucursalIdsConSecuencia)) {
            $query->whereIn('id', $sucursalIdsConSecuencia);
        } else {
            // Fallback (antes de correr el seeder): sucursales con prefijo definido.
            $query->whereNotNull('prefijo')->whereRaw("TRIM(prefijo) <> ''");
        }

        $sucursales = $query->orderBy('nombre')->get(['id', 'nombre', 'prefijo', 'id_Empresa']);

        // Preseleccionar la sucursal principal del usuario si pertenece a esta empresa.
        $user = \App\Models\User::find($userId);
        $principal = (int) ($user->id_sucursal ?? 0);

        $data = $sucursales->map(function ($s) use ($principal) {
            return [
                'id'        => $s->id,
                'nombre'    => $s->nombre,
                'prefijo'   => $s->prefijo,
                'principal' => (int) $s->id === $principal,
            ];
        })->values();

        return ['success' => true, 'data' => $data];
    }
}
