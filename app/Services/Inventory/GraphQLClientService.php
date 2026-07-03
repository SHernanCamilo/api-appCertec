<?php

namespace App\Services\Inventory;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * Cliente para consumir datos de Microsoft Fabric
 * vía el servicio Graph-Fabric (FastAPI + Ariadne / REST).
 */
class GraphQLClientService
{
    private string $baseUrl;
    private int $timeout;
    private string $apiKey;

    public function __construct()
    {
        $this->baseUrl = rtrim(env('GRAPHQL_URL', 'http://127.0.0.1:8001'), '/');
        $this->timeout = (int) env('GRAPHQL_TIMEOUT', 5);
        $this->apiKey  = env('GRAPHQL_API_KEY', '');
    }

    /**
     * Obtener productos con paginación
     */
    public function getProducts(array $filters = []): array
    {
        $limit  = min((int) ($filters['limit'] ?? 200), 1000);
        $offset = (int) ($filters['offset'] ?? 0);
        $search = $filters['search'] ?? '';

        $params = ['limit' => $limit, 'offset' => $offset];
        if ($search !== '') {
            $params['nombre'] = $search;
        }
        
        if (!empty($filters['estado'])) {
            $params['estado'] = $filters['estado'];
        }

        $response = $this->restGet('/api/inventory/productos', $params);

        if (!$response || !isset($response['items'])) {
            throw new Exception('Graph-Fabric: Error al obtener productos');
        }

        return [
            'success' => true,
            'data'    => $response['items'],
            'meta'    => [
                'total'  => $response['total'] ?? count($response['items']),
                'limit'  => $limit,
                'offset' => $offset
            ]
        ];
    }

    /**
     * Buscar producto por código exacto
     */
    public function findByCode(string $code): ?array
    {
        $params = ['codigo' => $code, 'limit' => 1];
        $response = $this->restGet('/api/inventory/productos', $params);

        if (!$response || empty($response['items'])) {
            return null;
        }

        return $response['items'][0];
    }

    /**
     * Buscar productos por múltiples códigos en lote
     */
    public function findByCodes(array $codes): array
    {
        if (empty($codes)) return [];
        
        $codes = array_values(array_unique(array_filter(array_map('trim', $codes))));
        if (empty($codes)) return [];

        $response = $this->restPost('/api/inventory/productos/batch', ['codigos' => array_slice($codes, 0, 1000)]);
        
        if ($response && isset($response['items'])) {
            $map = [];
            foreach ($response['items'] as $item) {
                $code = $item['codigo'] ?? '';
                if ($code !== '') {
                    $map[$code] = $item;
                }
            }
            return $map;
        }

        return [];
    }

    /**
     * Obtener almacenes disponibles
     */
    public function getWarehouses(array $filters = []): array
    {
        $limit = min((int) ($filters['limit'] ?? 100), 1000);
        $response = $this->restGet('/api/inventory/almacenes-disponibles', ['limit' => $limit]);

        if (!$response || !isset($response['items'])) {
            throw new Exception('Graph-Fabric: Error al obtener almacenes');
        }

        return [
            'success' => true,
            'data'    => $response['items'],
            'meta'    => [
                'total' => $response['total'] ?? count($response['items'])
            ]
        ];
    }

    /**
     * Obtener inventario detallado por almacén
     */
    public function getInventory(array $filters = []): array
    {
        $limit  = min((int) ($filters['limit'] ?? 100), 1000);
        $offset = (int) ($filters['offset'] ?? 0);
        $params = ['limit' => $limit, 'offset' => $offset];

        if (!empty($filters['codigo'])) {
            $params['codigo'] = $filters['codigo'];
        }

        $response = $this->restGet('/api/inventory/almacenes', $params);

        if (!$response || !isset($response['items'])) {
            throw new Exception('Graph-Fabric: Error al obtener inventario');
        }

        return [
            'success' => true,
            'data'    => $response['items'],
            'meta'    => [
                'total'  => $response['total'] ?? count($response['items']),
                'limit'  => $limit,
                'offset' => $offset
            ]
        ];
    }

    /**
     * Petición GET al servicio Graph-Fabric
     */
    private function restGet(string $path, array $params = []): ?array
    {
        try {
            $req = Http::timeout($this->timeout)->acceptJson();
            if ($this->apiKey !== '') {
                $req->withHeaders(['X-API-Key' => $this->apiKey]);
            }

            $response = $req->get($this->baseUrl . $path, $params);
            
            if ($response->failed()) {
                Log::error('Graph-Fabric GET error: ' . $response->status(), ['url' => $this->baseUrl . $path, 'params' => $params]);
                return null;
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::error('Graph-Fabric GET Exception: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Petición POST al servicio Graph-Fabric
     */
    private function restPost(string $path, array $body): ?array
    {
        try {
            $req = Http::timeout($this->timeout)->acceptJson();
            if ($this->apiKey !== '') {
                $req->withHeaders(['X-API-Key' => $this->apiKey]);
            }

            $response = $req->post($this->baseUrl . $path, $body);
            
            if ($response->failed()) {
                Log::error('Graph-Fabric POST error: ' . $response->status(), ['url' => $this->baseUrl . $path]);
                return null;
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::error('Graph-Fabric POST Exception: ' . $e->getMessage());
            return null;
        }
    }
}
