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
     * Buscar productos por múltiples códigos (normalizados para recepción).
     */
    public function findByCodes(array $codes): array
    {
        if (empty($codes)) {
            return [];
        }

        $results = [];
        foreach (array_unique(array_filter(array_map('trim', $codes))) as $code) {
            $product = $this->findByCode($code);
            if ($product) {
                $results[$code] = $this->normalizeProductRow($product);
            }
        }

        return $results;
    }

    /**
     * Buscar producto por código CUM en la vista Fabric Inventory_Productos.
     * Equivalente a legacy Product::findExternalByCum().
     */
    public function findByCum(string $cumCode): ?array
    {
        $cumCode = trim($cumCode);
        if ($cumCode === '') {
            return null;
        }

        $byCode = $this->findByCode($cumCode);
        if ($byCode) {
            return $this->formatCumProduct($this->normalizeProductRow($byCode), $cumCode);
        }

        foreach (['CodigoCUM', 'Codigo_CUM', 'CUM', 'CodCUM', 'Codigo CUM', 'codigo_cum'] as $cumField) {
            $result = $this->gateway->queryAsSystem(self::SCHEMA, self::VIEW_PRODUCTOS, [
                'filters' => [$cumField => $cumCode],
                'limit'   => 1,
            ]);

            if ($result['success'] && !empty($result['data'][0])) {
                return $this->formatCumProduct($this->normalizeProductRow($result['data'][0]), $cumCode);
            }
        }

        $local = \App\Models\Inventory\InvProducto::where('codigo', $cumCode)->where('activo', true)->first();
        if ($local) {
            return [
                'codigo'           => $local->codigo,
                'nombre'           => $local->nombre,
                'product_name'     => $local->nombre,
                'producto_nombre'  => $local->nombre,
                'product_type'     => $local->tipo_producto ?? '',
                'cum_code'         => $cumCode,
                'manufacturer'     => $local->fabricante ?? '',
                'presentation'     => $local->presentacion ?? '',
                'unit_measure'     => $local->unidad_empaque ?? 'UND',
                'risk_type'        => $local->tipo_riesgo ?? '',
                'concentracion'    => $local->concentracion ?? '',
                'unidad_empaque'   => $local->unidad_empaque ?? '',
            ];
        }

        return null;
    }

    /**
     * Normaliza filas de in.Inventory_Productos a claves estándar del módulo.
     */
    public function normalizeProductRow(array $row): array
    {
        return [
            'codigo'         => $this->pickField($row, ['CodProducto', 'Codigo', 'codigo', 'code', 'product_code']),
            'nombre'         => $this->pickField($row, ['Producto', 'Nombre', 'nombre', 'name', 'product_name']),
            'product_type'   => $this->pickField($row, ['TipoProducto', 'Tipo', 'tipo_producto', 'product_type']),
            'cum_code'       => $this->pickField($row, ['CodigoCUM', 'Codigo_CUM', 'CUM', 'CodCUM', 'codigo_cum']),
            'presentation'   => $this->pickField($row, ['Presentacion', 'Presentación', 'presentacion', 'presentation']),
            'concentracion'  => $this->pickField($row, ['Concentracion', 'Concentración', 'concentracion', 'concentration', 'PrincipioActivo', 'principio_activo']),
            'unidad_empaque' => $this->pickField($row, ['UnidadEmpaque', 'Unidad de Empaque', 'unidad_empaque', 'unidadempaque', 'empaque']),
            'risk_type'      => $this->pickField($row, ['TipoRiesgo', 'Tipo_Riesgo', 'tipo_riesgo', 'risk_type', 'Tiporiesgo']),
            'serie'          => $this->pickField($row, ['Serial', 'Serie', 'serie', 'NumeroSerie', 'manejaSerial']),
            'descripcion'    => $this->pickField($row, ['Descripcion', 'Descripción', 'descripcion', 'description']),
            'marca'          => $this->pickField($row, ['Marca', 'marca', 'brand', 'Fabricante', 'fabricante']),
        ];
    }

    private function formatCumProduct(array $normalized, string $cumCode): array
    {
        return array_merge($normalized, [
            'product_name'    => $normalized['nombre'],
            'producto_nombre' => $normalized['nombre'],
            'cum_code'        => $cumCode,
            'manufacturer'    => $normalized['marca'],
            'unit_measure'    => $normalized['unidad_empaque'] ?: 'UND',
        ]);
    }

    private function pickField(array $row, array $candidates): string
    {
        foreach ($candidates as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
                return trim((string) $row[$key]);
            }
        }

        $lower = [];
        foreach ($row as $k => $v) {
            $lower[strtolower((string) $k)] = $v;
        }

        foreach ($candidates as $key) {
            $lk = strtolower($key);
            if (array_key_exists($lk, $lower) && $lower[$lk] !== null && $lower[$lk] !== '') {
                return trim((string) $lower[$lk]);
            }
        }

        return '';
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
     * Obtener órdenes de compra de Indigo desde SQL Server (Azure).
     * Conexión: sqlsrv_indigo → ssindigo.database.windows.net
     * Vista: ViewInternal.Inventory_OrdenesDeCompra_DigiPharma
     *
     * NOTA: Requiere pdo_sqlsrv instalado en la VPS.
     */
    public function getIndigoOrders(array $filters = []): array
    {
        $limit  = min((int) ($filters['limit'] ?? 100), 5000);
        $offset = (int) ($filters['offset'] ?? 0);

        try {
            $query = \Illuminate\Support\Facades\DB::connection('sqlsrv_indigo')
                ->table(\Illuminate\Support\Facades\DB::raw(
                    env('MSSQL_PURCHASEORDER_VIEW', 'ViewInternal.Inventory_OrdenesDeCompra_DigiPharma')
                ));

            if (!empty($filters['numero_orden'])) {
                $query->where('OrdenCompra', $filters['numero_orden']);
            }
            if (!empty($filters['fecha_desde'])) {
                $query->where('Fecha', '>=', $filters['fecha_desde']);
            }
            if (!empty($filters['search'])) {
                $query->where('OrdenCompra', 'LIKE', "%{$filters['search']}%");
            }
            if (!empty($filters['proveedor'])) {
                $query->where('Proveedor', 'LIKE', "%{$filters['proveedor']}%");
            }

            $data = $query->orderByDesc('Fecha')
                ->offset($offset)
                ->limit($limit)
                ->get()
                ->map(fn($row) => (array) $row)
                ->toArray();

            return [
                'success' => true,
                'data'    => $data,
                'meta'    => ['total' => count($data), 'limit' => $limit, 'offset' => $offset],
            ];
        } catch (\Exception $e) {
            Log::error('FabricInventory: Error consultando Indigo SQL Server', [
                'error' => $e->getMessage(),
            ]);
            return [
                'success' => false,
                'message' => 'Error conectando a SQL Server Indigo: ' . $e->getMessage(),
                'data'    => [],
            ];
        }
    }

    /**
     * Obtener proveedores desde SQL Server (vista de Indigo).
     * Vista: ViewInternal.FQ45_V_CXP_Proveedores en ssindigo.database.windows.net
     * Cachea el resultado 30 minutos.
     */
    public function getSuppliers(): array
    {
        $cacheKey = 'inv_suppliers_sqlsrv';
        $cached = \Illuminate\Support\Facades\Cache::get($cacheKey);
        if ($cached) return $cached;

        try {
            $data = \Illuminate\Support\Facades\DB::connection('sqlsrv_indigo')
                ->table(\Illuminate\Support\Facades\DB::raw('ViewInternal.FQ45_V_CXP_Proveedores'))
                ->select('*')
                ->limit(5000)
                ->get()
                ->map(fn($row) => (array) $row)
                ->toArray();

            $response = ['success' => true, 'data' => $data];
            \Illuminate\Support\Facades\Cache::put($cacheKey, $response, 1800);
            return $response;
        } catch (\Exception $e) {
            Log::error('FabricInventory: Error obteniendo proveedores de SQL Server', [
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'data' => [], 'message' => $e->getMessage()];
        }
    }
}
