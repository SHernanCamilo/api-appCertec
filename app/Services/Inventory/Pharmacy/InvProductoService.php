<?php

namespace App\Services\Inventory\Pharmacy;

use App\Models\Inventory\InvProducto;
use App\Services\Inventory\FabricInventoryService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class InvProductoService
{
    protected InvimaService $invimaService;
    protected FabricInventoryService $fabricService;

    public function __construct(InvimaService $invimaService, FabricInventoryService $fabricService)
    {
        $this->invimaService = $invimaService;
        $this->fabricService = $fabricService;
    }

    // =========================================================================
    //  LISTAR PRODUCTOS
    // =========================================================================

    /**
     * Obtener todos los productos con filtros opcionales.
     */
    public function getAll(array $filters = []): array
    {
        // Si el origen solicitado es "external", se busca en Microsoft Fabric vía GraphQLClient
        if (($filters['source'] ?? '') === 'external') {
            try {
                return $this->fabricService->getProducts($filters);
            } catch (\Exception $e) {
                Log::error('Error fetching external products: ' . $e->getMessage());
                return ['success' => false, 'message' => 'Error al obtener productos externos', 'data' => []];
            }
        }

        $query = InvProducto::query()->where('activo', true);

        // Filtro de búsqueda por texto
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('nombre', 'LIKE', "%{$search}%")
                  ->orWhere('codigo', 'LIKE', "%{$search}%")
                  ->orWhere('fabricante', 'LIKE', "%{$search}%")
                  ->orWhere('registro_sanitario', 'LIKE', "%{$search}%");
            });
        }

        // Filtro por tipo de producto
        if (!empty($filters['tipo_producto'])) {
            $query->where('tipo_producto', $filters['tipo_producto']);
        }

        // Filtro por estado
        if (!empty($filters['estado'])) {
            $query->where('estado', $filters['estado']);
        }

        $query->orderBy('nombre', 'asc');

        // Paginación
        $limit  = isset($filters['limit']) ? max(1, (int) $filters['limit']) : 25;
        $offset = isset($filters['offset']) ? max(0, (int) $filters['offset']) : 0;

        $total = $query->count();

        $productos = $query->offset($offset)->limit($limit)->get();

        return [
            'success' => true,
            'data'    => $productos,
            'meta'    => [
                'total'  => $total,
                'limit'  => $limit,
                'offset' => $offset,
            ],
        ];
    }

    // =========================================================================
    //  OBTENER POR ID
    // =========================================================================

    /**
     * Obtener un producto por su ID.
     */
    public function getById(int $id): ?InvProducto
    {
        return InvProducto::find($id);
    }

    // =========================================================================
    //  CREAR PRODUCTO
    // =========================================================================

    /**
     * Crear un nuevo producto.
     */
    public function create(array $data): InvProducto
    {
        return InvProducto::create($data);
    }

    // =========================================================================
    //  ACTUALIZAR PRODUCTO
    // =========================================================================

    /**
     * Actualizar un producto existente.
     */
    public function update(int $id, array $data): ?InvProducto
    {
        $producto = InvProducto::find($id);
        if (!$producto) {
            return null;
        }

        $producto->update($data);
        return $producto->fresh();
    }

    // =========================================================================
    //  VALIDACIÓN MASIVA (carga de pedidos)
    // =========================================================================

    /**
     * Valida un lote de productos contra el catálogo de Fabric (VW_Inventory_Productos).
     *
     * Eficiente: consulta el catálogo UNA sola vez (indexado + cacheado en
     * FabricInventoryService) y valida todo el lote en memoria, en lugar de una
     * llamada por producto. Los productos se buscan en Fabric (fuente de verdad),
     * no en la tabla local inv_productos (que puede estar vacía).
     *
     * @param array $rows Filas del Excel: [['product_code'=>..., 'quantity'=>..., 'rotation_type'=>...], ...]
     * @return array{success:bool, data:array, errors:array, warnings:array}
     */
    public function validateBulk(array $rows): array
    {
        $validRotations = ['bajo', 'baja', 'media', 'alta', 'nula'];

        // 1. Recolectar los códigos del lote para una sola consulta a Fabric.
        $codigos = [];
        foreach ($rows as $row) {
            $code = trim((string) ($row['product_code'] ?? ''));
            if ($code !== '') {
                $codigos[] = $code;
            }
        }

        // 2. Traer del catálogo de Fabric solo los productos del lote (1 consulta).
        $catalogo = $this->fabricService->findByCodes($codigos); // [codigoOriginal => filaNormalizada]
        // Reindexar por código en mayúsculas para comparación robusta.
        $indice = [];
        foreach ($catalogo as $prod) {
            $cod = strtoupper(trim((string) ($prod['codigo'] ?? '')));
            if ($cod !== '') {
                $indice[$cod] = $prod;
            }
        }

        $validatedItems = [];
        $errors = [];
        $warnings = [];

        // 3. Validar cada fila contra el índice en memoria.
        foreach ($rows as $index => $row) {
            $rowNum      = $index + 2; // +1 índice base 0, +1 encabezado Excel
            $productCode = trim((string) ($row['product_code'] ?? ''));
            $quantity    = (int) ($row['quantity'] ?? 0);
            $rotation    = strtolower(trim((string) ($row['rotation_type'] ?? 'media')));

            if ($productCode === '') {
                $errors[] = "Fila {$rowNum}: El código de producto está vacío.";
                continue;
            }
            if ($quantity <= 0) {
                $errors[] = "Fila {$rowNum}: La cantidad debe ser mayor a 0 (Producto: {$productCode}).";
                continue;
            }

            $prod = $indice[strtoupper($productCode)] ?? null;
            if (!$prod) {
                $errors[] = "Fila {$rowNum}: Producto no encontrado en el catálogo (código {$productCode}).";
                continue;
            }

            if (!in_array($rotation, $validRotations, true)) {
                $warnings[] = "Fila {$rowNum}: Producto {$productCode} con rotación inválida '{$rotation}'. Se usó 'media'.";
                $rotation = 'media';
            }

            $marca = $prod['marca'] ?? $prod['fabricante'] ?? '';
            if ($marca === '') {
                $warnings[] = "Fila {$rowNum}: Producto {$productCode} sin fabricante.";
            }

            $validatedItems[] = [
                'product_code'  => $prod['codigo'] ?? $productCode,
                'product_name'  => $prod['nombre'] ?? '',
                'quantity'      => $quantity,
                'rotation_type' => $rotation,
                'brand'         => $marca,
                'average_cost'  => (float) ($prod['costo_promedio'] ?? $prod['Costo_promedio'] ?? 0),
                'price'         => (float) ($prod['precio_venta'] ?? $prod['Precio_Venta'] ?? 0),
            ];
        }

        return [
            'success'  => true,
            'data'     => $validatedItems,
            'errors'   => $errors,
            'warnings' => $warnings,
        ];
    }

    // =========================================================================
    //  BUSCAR EN INVIMA
    // =========================================================================

    /**
     * Buscar productos en INVIMA (datos.gov.co).
     */
    public function searchInvima(string $query, string $type = 'auto', int $limit = 50, int $offset = 0): array
    {
        return $this->invimaService->searchProduct($query, $type, $limit, $offset);
    }

    /**
     * Validar un código INVIMA.
     */
    public function validateInvima(string $code, string $type = 'auto'): array
    {
        return $this->invimaService->validateProduct($code, $type);
    }

    /**
     * Buscar Medicamentos Vitales No Disponibles (MVD).
     */
    public function searchMvd(string $ium): array
    {
        return $this->invimaService->searchMvd($ium);
    }

    /**
     * Validar código CUM contra catálogo Fabric (in.Inventory_Productos).
     */
    public function validateCum(string $cumCode): array
    {
        $cumCode = trim($cumCode);
        if ($cumCode === '') {
            return ['success' => false, 'message' => 'Código CUM requerido'];
        }

        $product = $this->fabricService->findByCum($cumCode);
        if (!$product) {
            return [
                'success' => true,
                'exists'  => false,
                'message' => 'CUM no encontrado en la vista de productos',
            ];
        }

        return [
            'success' => true,
            'exists'  => true,
            'data'    => $product,
        ];
    }
}
