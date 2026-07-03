<?php

namespace App\Services\Inventory;

use App\Models\Inventory\InvProducto;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class InvProductoService
{
    protected InvimaService $invimaService;
    protected GraphQLClientService $graphClient;

    public function __construct(InvimaService $invimaService, GraphQLClientService $graphClient)
    {
        $this->invimaService = $invimaService;
        $this->graphClient = $graphClient;
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
                return $this->graphClient->getProducts($filters);
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
}
