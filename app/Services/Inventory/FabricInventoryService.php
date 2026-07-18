<?php

namespace App\Services\Inventory;

use App\Services\Fabric\GraphFabricGatewayService;
use Illuminate\Support\Facades\Log;

/**
 * Servicio de Inventario que consume datos de Microsoft Fabric
 * a través del GraphFabricGatewayService (reutiliza la infraestructura existente).
 *
 * Vistas de Fabric usadas:
 *   - in.Inventory_Productos          → Catálogo de productos
 *   - in.INVENTORY_ALMACENES          → Inventario por almacén
 *   - in.Inventory_OrdenesDeCompra_DigiPharma → Órdenes de compra (Indigo)
 */
class FabricInventoryService
{
    private const SCHEMA = 'in';
    private const VIEW_PRODUCTOS = 'Inventory_Productos';
    private const VIEW_ALMACENES = 'INVENTORY_ALMACENES';
    private const VIEW_ORDENES_INDIGO = 'Inventory_OrdenesDeCompra_DigiPharma';

    public function __construct(
        private readonly GraphFabricGatewayService $gateway
    ) {}

    /**
     * Obtener productos desde Fabric con paginación y filtros.
     */
    public function getProducts(array $filters = []): array
    {
        $limit  = min((int) ($filters['limit'] ?? 200), 5000);
        $offset = (int) ($filters['offset'] ?? 0);

        $fabricFilters = [];
        if (!empty($filters['search'])) {
            $fabricFilters['Producto'] = "%{$filters['search']}%";
        }
        if (!empty($filters['codigo'])) {
            $fabricFilters['CodProducto'] = $filters['codigo'];
        }
        if (!empty($filters['estado'])) {
            $fabricFilters['Estado'] = $filters['estado'];
        }

        $result = $this->gateway->queryAsSystem(self::SCHEMA, self::VIEW_PRODUCTOS, [
            'filters'  => $fabricFilters,
            'limit'    => $limit,
            'offset'   => $offset,
            'sort_col' => $filters['sort_col'] ?? 'Producto',
            'sort_dir' => $filters['sort_dir'] ?? 'asc',
        ]);

        if (!$result['success']) {
            Log::error('FabricInventory: Error obteniendo productos', ['error' => $result['message'] ?? '']);
            return ['success' => false, 'message' => $result['message'] ?? 'Error al obtener productos', 'data' => []];
        }

        return [
            'success' => true,
            'data'    => $result['data'] ?? [],
            'meta'    => $result['meta'] ?? ['total' => count($result['data'] ?? []), 'limit' => $limit, 'offset' => $offset],
        ];
    }

    /**
     * Buscar producto por código exacto.
     */
    public function findByCode(string $code): ?array
    {
        $result = $this->gateway->queryAsSystem(self::SCHEMA, self::VIEW_PRODUCTOS, [
            'filters' => ['CodProducto' => $code],
            'limit'   => 1,
        ]);

        if (!$result['success'] || empty($result['data'])) {
            return null;
        }

        return $result['data'][0];
    }

    /**
     * Buscar productos por múltiples códigos.
     */
    public function findByCodes(array $codes): array
    {
        if (empty($codes)) return [];

        $results = [];
        // Fabric no soporta IN(), hacemos batches con filtro LIKE por cada código
        // Para optimizar, podemos hacer una sola query con filtro especial o usar el endpoint aggregate
        foreach (array_chunk($codes, 50) as $batch) {
            foreach ($batch as $code) {
                $product = $this->findByCode($code);
                if ($product) {
                    $results[$code] = $product;
                }
            }
        }

        return $results;
    }

    /**
     * Obtener almacenes disponibles.
     */
    public function getWarehouses(array $filters = []): array
    {
        $limit = min((int) ($filters['limit'] ?? 100), 5000);

        $result = $this->gateway->queryAsSystem(self::SCHEMA, self::VIEW_ALMACENES, [
            'filters' => $filters['filters'] ?? [],
            'limit'   => $limit,
            'offset'  => (int) ($filters['offset'] ?? 0),
        ]);

        if (!$result['success']) {
            return ['success' => false, 'message' => 'Error al obtener almacenes', 'data' => []];
        }

        return [
            'success' => true,
            'data'    => $result['data'] ?? [],
            'meta'    => $result['meta'] ?? [],
        ];
    }

    /**
     * Obtener órdenes de compra de Indigo desde Fabric.
     * Vista: in.Inventory_OrdenesDeCompra_DigiPharma
     */
    public function getIndigoOrders(array $filters = []): array
    {
        $limit  = min((int) ($filters['limit'] ?? 100), 5000);
        $offset = (int) ($filters['offset'] ?? 0);

        $fabricFilters = [];
        if (!empty($filters['fecha_desde'])) {
            $fabricFilters['Fecha'] = ">={$filters['fecha_desde']}";
        }
        if (!empty($filters['search'])) {
            $fabricFilters['OrdenCompra'] = "%{$filters['search']}%";
        }
        if (!empty($filters['proveedor'])) {
            $fabricFilters['Proveedor'] = "%{$filters['proveedor']}%";
        }

        $result = $this->gateway->queryAsSystem(self::SCHEMA, self::VIEW_ORDENES_INDIGO, [
            'filters'  => $fabricFilters,
            'limit'    => $limit,
            'offset'   => $offset,
            'sort_col' => 'Fecha',
            'sort_dir' => 'desc',
        ]);

        if (!$result['success']) {
            Log::error('FabricInventory: Error obteniendo órdenes Indigo', ['error' => $result['message'] ?? '']);
            return ['success' => false, 'message' => $result['message'] ?? 'Error consultando Indigo', 'data' => []];
        }

        return [
            'success' => true,
            'data'    => $result['data'] ?? [],
            'meta'    => $result['meta'] ?? ['total' => count($result['data'] ?? []), 'limit' => $limit, 'offset' => $offset],
        ];
    }

    /**
     * Obtener proveedores desde Fabric (vista de Indigo).
     * Cachea el resultado 30 minutos.
     */
    public function getSuppliers(): array
    {
        $cacheKey = 'inv_suppliers_fabric';
        $cached = \Illuminate\Support\Facades\Cache::get($cacheKey);
        if ($cached) return $cached;

        // Los proveedores están en la vista de órdenes — extraemos únicos
        $result = $this->gateway->queryAsSystem(self::SCHEMA, self::VIEW_ORDENES_INDIGO, [
            'columns' => ['Proveedor', 'NitProveedor'],
            'limit'   => 5000,
        ]);

        if (!$result['success']) {
            return ['success' => false, 'data' => []];
        }

        // Deduplicar por NIT
        $suppliers = collect($result['data'] ?? [])
            ->filter(fn($r) => !empty($r['Proveedor']))
            ->unique('NitProveedor')
            ->values()
            ->toArray();

        $response = ['success' => true, 'data' => $suppliers];
        \Illuminate\Support\Facades\Cache::put($cacheKey, $response, 1800); // 30 min

        return $response;
    }
}
