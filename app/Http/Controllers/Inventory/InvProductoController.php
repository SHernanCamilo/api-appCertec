<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Services\Inventory\InvProductoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class InvProductoController extends Controller
{
    protected InvProductoService $service;

    public function __construct(InvProductoService $service)
    {
        $this->service = $service;
    }

    // =========================================================================
    //  CRUD BÁSICO
    // =========================================================================

    /**
     * Listar productos con filtros opcionales.
     * GET /api/inventario/productos
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $filters = [
                'search'        => $request->query('search'),
                'tipo_producto' => $request->query('tipo_producto'),
                'estado'        => $request->query('estado'),
                'source'        => $request->query('source'),
                'limit'         => $request->query('limit', 25),
                'offset'        => $request->query('offset', 0),
            ];

            $result = $this->service->getAll($filters);

            return response()->json($result, 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener los productos',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mostrar un producto por ID.
     * GET /api/inventario/productos/{id}
     */
    public function show(string $id): JsonResponse
    {
        try {
            $producto = $this->service->getById((int) $id);

            if (!$producto) {
                return response()->json([
                    'success' => false,
                    'message' => 'Producto no encontrado',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data'    => $producto,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener el producto',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Crear un nuevo producto.
     * POST /api/inventario/productos
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'codigo'              => 'required|string|max:50|unique:inv_productos,codigo',
            'nombre'              => 'required|string|max:255',
            'tipo_producto'       => 'nullable|string|max:100',
            'codigo_agrupador'    => 'nullable|string|max:50',
            'agrupador'           => 'nullable|string|max:255',
            'fabricante'          => 'nullable|string|max:255',
            'unidad_empaque'      => 'nullable|string|max:100',
            'costo_promedio'      => 'nullable|numeric',
            'ultimo_costo'        => 'nullable|numeric',
            'precio_venta'        => 'nullable|numeric',
            'estado'              => 'nullable|string|max:50',
            'tipo_riesgo'         => 'nullable|string|max:100',
            'concentracion'       => 'nullable|string|max:255',
            'registro_sanitario'  => 'nullable|string|max:100',
            'presentacion'        => 'nullable|string|max:255',
        ], [
            'codigo.required' => 'El código del producto es obligatorio',
            'codigo.unique'   => 'Ya existe un producto con ese código',
            'nombre.required' => 'El nombre del producto es obligatorio',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $producto = $this->service->create($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Producto creado exitosamente',
                'data'    => $producto,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear el producto',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Actualizar un producto existente.
     * PUT /api/inventario/productos/{id}
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'codigo'              => 'sometimes|required|string|max:50|unique:inv_productos,codigo,' . $id,
            'nombre'              => 'sometimes|required|string|max:255',
            'tipo_producto'       => 'nullable|string|max:100',
            'codigo_agrupador'    => 'nullable|string|max:50',
            'agrupador'           => 'nullable|string|max:255',
            'fabricante'          => 'nullable|string|max:255',
            'unidad_empaque'      => 'nullable|string|max:100',
            'costo_promedio'      => 'nullable|numeric',
            'ultimo_costo'        => 'nullable|numeric',
            'precio_venta'        => 'nullable|numeric',
            'estado'              => 'nullable|string|max:50',
            'tipo_riesgo'         => 'nullable|string|max:100',
            'concentracion'       => 'nullable|string|max:255',
            'registro_sanitario'  => 'nullable|string|max:100',
            'presentacion'        => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $producto = $this->service->update((int) $id, $request->all());

            if (!$producto) {
                return response()->json([
                    'success' => false,
                    'message' => 'Producto no encontrado',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Producto actualizado exitosamente',
                'data'    => $producto,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar el producto',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Eliminar (soft) un producto (desactivar).
     * DELETE /api/inventario/productos/{id}
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $producto = $this->service->getById((int) $id);

            if (!$producto) {
                return response()->json([
                    'success' => false,
                    'message' => 'Producto no encontrado',
                ], 404);
            }

            $producto->update(['activo' => false]);

            return response()->json([
                'success' => true,
                'message' => 'Producto desactivado exitosamente',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al desactivar el producto',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // =========================================================================
    //  INVIMA (datos.gov.co)
    // =========================================================================

    /**
     * Buscar productos en INVIMA.
     * GET /api/inventario/invima/buscar?q=...&type=auto&limit=50&offset=0
     */
    public function searchInvima(Request $request): JsonResponse
    {
        $query = $request->query('q', '');
        if (empty($query)) {
            return response()->json([
                'success' => false,
                'message' => 'Parámetro de búsqueda (q) requerido',
            ], 422);
        }

        try {
            $result = $this->service->searchInvima(
                $query,
                $request->query('type', 'auto'),
                (int) $request->query('limit', 50),
                (int) $request->query('offset', 0)
            );

            return response()->json($result, 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al consultar INVIMA',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Validar un código INVIMA.
     * GET /api/inventario/invima/validar/{code}
     */
    public function validateInvima(string $code, Request $request): JsonResponse
    {
        try {
            $result = $this->service->validateInvima(
                $code,
                $request->query('type', 'auto')
            );

            return response()->json($result, 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al validar INVIMA',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Buscar Medicamentos Vitales No Disponibles (MVD).
     * GET /api/inventario/invima/mvd?ium=...
     */
    public function searchMvd(Request $request): JsonResponse
    {
        $ium = $request->query('ium', '');
        if (empty($ium)) {
            return response()->json([
                'success' => false,
                'message' => 'Parámetro IUM requerido',
            ], 422);
        }

        try {
            $result = $this->service->searchMvd($ium);
            return response()->json($result, 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al consultar MVD',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Validar carga masiva de productos desde Excel
     * POST /api/inventario/productos/bulk-validate
     */
    public function validateBulkProducts(Request $request): JsonResponse
    {
        $rows = $request->all();
        if (!is_array($rows)) {
            return response()->json(['success' => false, 'message' => 'El cuerpo debe ser un array JSON'], 400);
        }

        $validatedItems = [];
        $errors = [];
        $warnings = [];

        foreach ($rows as $index => $row) {
            $rowNum = $index + 2; // +1 for 0-index, +1 for excel header
            $productCode = $row['product_code'] ?? null;
            $quantity = (int)($row['quantity'] ?? 0);
            $rotation = strtolower(trim($row['rotation_type'] ?? 'media'));

            if (!$productCode) {
                $errors[] = "Fila $rowNum: El código de producto está vacío.";
                continue;
            }

            if ($quantity <= 0) {
                $errors[] = "Fila $rowNum: La cantidad debe ser mayor a 0 (Producto: $productCode).";
                continue;
            }

            // Buscar en BD
            $producto = \App\Models\Inventory\InvProducto::where('codigo', $productCode)->first();
            if (!$producto) {
                $errors[] = "Fila $rowNum: Producto no encontrado con el código $productCode.";
                continue;
            }

            // Validar rotación
            $validRotations = ['bajo', 'media', 'alta', 'nula', 'baja'];
            if (!in_array($rotation, $validRotations)) {
                $warnings[] = "Fila $rowNum: Producto $productCode con rotación inválida '$rotation'.";
                $rotation = 'media';
            }
            if (!$producto->fabricante) {
                $warnings[] = "Fila $rowNum: Producto $productCode sin fabricante.";
            }

            // Mapear item para frontend
            $validatedItems[] = [
                'product_code' => $producto->codigo,
                'product_name' => $producto->nombre,
                'quantity' => $quantity,
                'rotation_type' => $rotation,
                'brand' => $producto->fabricante,
                'average_cost' => (float)$producto->costo_promedio,
                'price' => (float)$producto->precio_venta,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $validatedItems,
            'errors' => $errors,
            'warnings' => $warnings
        ], 200);
    }
}
