<?php

namespace App\Services\Inventory;

use App\Services\Fabric\GraphFabricGatewayService;
use Illuminate\Support\Facades\Log;

/**
 * Servicio de Inventario que consume datos de Microsoft Fabric
 * a través del GraphFabricGatewayService (reutiliza la infraestructura existente).
 *
 * Vistas de Fabric usadas:
 *   - in.VW_Inventory_Productos (configurable) → Catálogo de productos farmacia
 *   - in.INVENTORY_ALMACENES          → Inventario por almacén
 *   - in.Inventory_OrdenesDeCompra_DigiPharma → Órdenes de compra (Indigo)
 */
class FabricInventoryService
{
    private const SCHEMA = 'in';
    private const VIEW_ALMACENES = 'INVENTORY_ALMACENES';
    private const VIEW_ORDENES_INDIGO = 'Inventory_OrdenesDeCompra_DigiPharma';

    /** Columnas de código probadas en orden (VW_Inventory_Productos usa Codigo). */
    private const CODE_FILTER_KEYS = [
        'Codigo', 'CodProducto', 'CodigoProducto', 'codigo', 'codigo_producto', 'Codigo_Producto',
    ];

    public function __construct(
        private readonly GraphFabricGatewayService $gateway
    ) {}

    private function getProductsSchema(): string
    {
        return (string) config('fabric.inventory_products_schema', self::SCHEMA);
    }

    private function getProductsView(): string
    {
        return (string) config('fabric.inventory_products_view', 'VW_Inventory_Productos');
    }

    /**
     * Consulta Fabric probando varias columnas de código (vistas legacy vs VW_*).
     */
    private function queryProductByCode(string $code, ?int $limit = 1): ?array
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        foreach (self::CODE_FILTER_KEYS as $filterKey) {
            $result = $this->gateway->queryAsSystem($this->getProductsSchema(), $this->getProductsView(), [
                'filters' => [$filterKey => $code],
                'limit'   => $limit ?? 1,
            ]);

            if ($result['success'] && !empty($result['data'][0])) {
                return $result['data'][0];
            }
        }

        return null;
    }

    private function findLocalNormalized(string $code): ?array
    {
        $local = \App\Models\Inventory\InvProducto::where('codigo', $code)->where('activo', true)->first();
        if (!$local) {
            return null;
        }

        return [
            'codigo'         => $local->codigo,
            'nombre'         => $local->nombre,
            'product_type'   => $local->tipo_producto ?? '',
            'presentation'   => $local->presentacion ?? '',
            'concentracion'  => $local->concentracion ?? '',
            'unidad_empaque' => $local->unidad_empaque ?? '',
            'risk_type'      => $local->tipo_riesgo ?? '',
            'marca'          => $local->fabricante ?? '',
            'serie'          => '',
            'descripcion'    => $local->nombre ?? '',
        ];
    }

    /**
     * Obtener productos desde Fabric con paginación y filtros.
     */
    public function getProducts(array $filters = []): array
    {
        $limit  = min((int) ($filters['limit'] ?? 200), 5000);
        $offset = (int) ($filters['offset'] ?? 0);

        if (!empty($filters['codigo'])) {
            // findByCode prueba Codigo, CodProducto, etc.
            $product = $this->queryProductByCode((string) $filters['codigo']);
            if ($product) {
                return [
                    'success' => true,
                    'data'    => [$this->normalizeProductRow($product)],
                    'meta'    => ['total' => 1, 'limit' => 1, 'offset' => 0],
                ];
            }
            $local = $this->findLocalNormalized((string) $filters['codigo']);
            if ($local) {
                return [
                    'success' => true,
                    'data'    => [$local],
                    'meta'    => ['total' => 1, 'limit' => 1, 'offset' => 0, 'source' => 'inv_productos'],
                ];
            }
        }

        $fabricFilters = [];
        if (!empty($filters['search'])) {
            $fabricFilters['Producto'] = "%{$filters['search']}%";
        }
        if (!empty($filters['estado'])) {
            $fabricFilters['Estado'] = $filters['estado'];
        }

        $result = $this->gateway->queryAsSystem($this->getProductsSchema(), $this->getProductsView(), [
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
        return $this->queryProductByCode($code) ?? $this->findLocalProductRaw($code);
    }

    /** Fila cruda local para compatibilidad con findByCum. */
    private function findLocalProductRaw(string $code): ?array
    {
        $normalized = $this->findLocalNormalized($code);
        if (!$normalized) {
            return null;
        }

        return array_merge($normalized, [
            'Codigo'   => $normalized['codigo'],
            'Producto' => $normalized['nombre'],
        ]);
    }

    /**
     * Buscar productos por múltiples códigos (normalizados para recepción).
     *
     * Eficiente: usa el catálogo indexado en caché (una sola consulta a Fabric por
     * ventana de caché) en lugar de una llamada por código. Para lotes de N productos
     * pasa de N llamadas HTTP a Fabric a 0-1 llamadas.
     */
    public function findByCodes(array $codes): array
    {
        $codes = array_values(array_unique(array_filter(array_map('trim', $codes))));
        if (empty($codes)) {
            return [];
        }

        // Estrategia según el tamaño del lote:
        //  - Lote pequeño (≤ UMBRAL): consulta individual por código (más barato que
        //    descargar el catálogo completo de ~35k productos).
        //  - Lote grande: descarga el catálogo una sola vez y filtra en memoria.
        $umbral = 12;

        if (count($codes) <= $umbral) {
            $results = [];
            foreach ($codes as $code) {
                $product = $this->findByCode($code);
                if ($product) {
                    $results[$code] = isset($product['codigo'])
                        ? $product
                        : $this->normalizeProductRow($product);
                }
            }
            return $results;
        }

        // Lote grande: usar el catálogo completo indexado (1 sola descarga).
        $catalogo = $this->getCatalogIndexedByCode();

        $results = [];
        foreach ($codes as $code) {
            $key = strtoupper($code);
            if (isset($catalogo[$key])) {
                $results[$code] = $catalogo[$key];
            }
        }

        return $results;
    }

    /** Catálogo indexado, cacheado EN MEMORIA por la duración del request. */
    private ?array $catalogoEnMemoria = null;

    /**
     * Devuelve el catálogo de productos de Fabric indexado por código (mayúsculas),
     * ya normalizado. Se cachea en memoria durante el request (no en BD, porque son
     * ~35k productos que exceden el límite del store de caché).
     *
     * Pruebas locales: traer 20k productos ~2s vs ~1.3s por código individual, así
     * que una sola descarga del catálogo valida lotes enteros casi al instante.
     *
     * @return array<string, array>  [CODIGO_UPPER => fila normalizada]
     */
    public function getCatalogIndexedByCode(): array
    {
        if ($this->catalogoEnMemoria !== null) {
            return $this->catalogoEnMemoria;
        }

        $indexado = [];
        $chunk    = 20000;
        $offset   = 0;
        $columnas = ['Codigo', 'Nombre', 'Tipo_producto', 'Codigo_CUM', 'Fabricante',
                     'Presentation', 'Concentracion', 'TipoRiesgo', 'Unidad_de_empaque',
                     'Costo_promedio', 'Precio_Venta', 'Estado', 'RegistroSanitario', 'Serial'];

        // Paginar por si el catálogo supera el máximo por request del Graph.
        do {
            $res = $this->gateway->queryAsSystem($this->getProductsSchema(), $this->getProductsView(), [
                'columns' => $columnas,
                'limit'   => $chunk,
                'offset'  => $offset,
            ]);

            $filas = $res['success'] ? ($res['data'] ?? []) : [];
            foreach ($filas as $row) {
                $norm = $this->normalizeProductRow($row);
                $cod  = strtoupper(trim((string) $norm['codigo']));
                if ($cod !== '') {
                    $indexado[$cod] = $norm;
                }
            }

            $recibidas = count($filas);
            $offset += $recibidas;
        } while ($recibidas === $chunk && $offset < 200000); // tope de seguridad

        return $this->catalogoEnMemoria = $indexado;
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

        foreach (['CodigoCUM', 'Codigo_CUM', 'CUM', 'CodCUM', 'Codigo CUM', 'codigo_cum', 'CodigoCum'] as $cumField) {
            $result = $this->gateway->queryAsSystem($this->getProductsSchema(), $this->getProductsView(), [
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
            'codigo'         => $this->pickField($row, ['Codigo', 'CodProducto', 'CodigoProducto', 'codigo', 'code', 'product_code']),
            'nombre'         => $this->pickField($row, ['Producto', 'Nombre', 'nombre', 'name', 'product_name', 'Descripcion']),
            'product_type'   => $this->pickField($row, ['TipoProducto', 'Tipo', 'tipo_producto', 'product_type', 'Tipo_Producto']),
            'cum_code'       => $this->pickField($row, ['CodigoCUM', 'Codigo_CUM', 'CUM', 'CodCUM', 'codigo_cum', 'CodigoCum']),
            'presentation'   => $this->pickField($row, ['Presentacion', 'Presentación', 'presentacion', 'presentation', 'FormaFarmaceutica', 'forma_farmaceutica']),
            'concentracion'  => $this->pickField($row, ['Concentracion', 'Concentración', 'concentracion', 'concentration', 'PrincipioActivo', 'principio_activo', 'Principio_Activo']),
            'unidad_empaque' => $this->pickField($row, ['UnidadEmpaque', 'Unidad de Empaque', 'unidad_empaque', 'unidadempaque', 'empaque', 'Unidad_Empaque', 'UnidadMedida']),
            'risk_type'      => $this->pickField($row, ['TipoRiesgo', 'Tipo_Riesgo', 'tipo_riesgo', 'risk_type', 'Tiporiesgo', 'Clasificacion']),
            'serie'          => $this->pickField($row, ['Serial', 'Serie', 'serie', 'NumeroSerie', 'manejaSerial', 'ManejaSerial']),
            'descripcion'    => $this->pickField($row, ['Descripcion', 'Descripción', 'descripcion', 'description', 'Desc_Producto']),
            'marca'          => $this->pickField($row, ['Marca', 'marca', 'brand', 'Fabricante', 'fabricante', 'Laboratorio']),
            'costo_promedio' => $this->pickField($row, ['Costo_promedio', 'CostoPromedio', 'costo_promedio', 'average_cost']),
            'precio_venta'   => $this->pickField($row, ['Precio_Venta', 'PrecioVenta', 'precio_venta', 'price', 'Precio']),
            'registro_sanitario' => $this->pickField($row, ['RegistroSanitario', 'Registro_Sanitario', 'registro_sanitario']),
            'estado'         => $this->pickField($row, ['Estado', 'estado', 'status']),
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
