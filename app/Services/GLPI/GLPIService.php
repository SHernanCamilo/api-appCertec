<?php

namespace App\Services\GLPI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Exception;

class GLPIService
{
    protected $baseUrl;
    protected $userToken;
    protected $appToken;
    protected $sessionToken;
    protected $timeout;

    public function __construct()
    {
        $this->baseUrl = config('glpi.base_url');
        $this->userToken = config('glpi.user_token');
        $this->appToken = config('glpi.app_token');
        $this->timeout = config('glpi.timeout', 30);
    }

    /**
     * Inicializar sesión con GLPI
     */
    public function initSession(): array
    {
        $response = Http::timeout($this->timeout)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'user_token ' . $this->userToken,
                'App-Token' => $this->appToken,
            ])
            ->get($this->baseUrl . '/initSession');

        if (!$response->successful()) {
            throw new Exception('Error al inicializar sesión GLPI: ' . $response->body());
        }

        $data = $response->json();
        $this->sessionToken = $data['session_token'];
        
        // Guardar token en cache por 8 horas
        Cache::put('glpi_session_token', $this->sessionToken, now()->addHours(8));
        
        Log::info('Sesión GLPI iniciada correctamente');
        
        return $data;
    }

    /**
     * Cerrar sesión con GLPI
     */
    public function killSession(): bool
    {
        $sessionToken = $this->getSessionToken();
        
        $response = Http::timeout($this->timeout)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Session-Token' => $sessionToken,
                'App-Token' => $this->appToken,
            ])
            ->get($this->baseUrl . '/killSession');

        if (!$response->successful()) {
            throw new Exception('Error al cerrar sesión GLPI: ' . $response->body());
        }

        // Limpiar token del cache
        Cache::forget('glpi_session_token');
        $this->sessionToken = null;
        
        Log::info('Sesión GLPI cerrada correctamente');
        
        return true;
    }

    /**
     * Obtener token de sesión (desde cache o crear nuevo)
     */
    public function getSessionToken(): string
    {
        if ($this->sessionToken) {
            return $this->sessionToken;
        }

        $cachedToken = Cache::get('glpi_session_token');
        if ($cachedToken) {
            $this->sessionToken = $cachedToken;
            return $this->sessionToken;
        }

        // Si no hay token, inicializar nueva sesión
        $session = $this->initSession();
        return $session['session_token'];
    }

    /**
     * Realizar petición GET a GLPI
     */
    public function get(string $endpoint, array $params = []): array
    {
        $sessionToken = $this->getSessionToken();
        
        $response = Http::timeout($this->timeout)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Session-Token' => $sessionToken,
                'App-Token' => $this->appToken,
            ])
            ->get($this->baseUrl . $endpoint, $params);

        if (!$response->successful()) {
            // Si es error de autenticación, intentar renovar sesión
            if ($response->status() === 401) {
                Cache::forget('glpi_session_token');
                $this->sessionToken = null;
                $sessionToken = $this->getSessionToken();
                
                // Reintentar la petición
                $response = Http::timeout($this->timeout)
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                        'Session-Token' => $sessionToken,
                        'App-Token' => $this->appToken,
                    ])
                    ->get($this->baseUrl . $endpoint, $params);
            }
            
            if (!$response->successful()) {
                throw new Exception('Error en petición GLPI GET: ' . $response->body());
            }
        }

        return $response->json();
    }

    /**
     * Realizar petición POST a GLPI
     */
    public function post(string $endpoint, array $data = []): array
    {
        $sessionToken = $this->getSessionToken();
        
        $response = Http::timeout($this->timeout)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Session-Token' => $sessionToken,
                'App-Token' => $this->appToken,
            ])
            ->post($this->baseUrl . $endpoint, $data);

        if (!$response->successful()) {
            // Si es error de autenticación, intentar renovar sesión
            if ($response->status() === 401) {
                Cache::forget('glpi_session_token');
                $this->sessionToken = null;
                $sessionToken = $this->getSessionToken();
                
                // Reintentar la petición
                $response = Http::timeout($this->timeout)
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                        'Session-Token' => $sessionToken,
                        'App-Token' => $this->appToken,
                    ])
                    ->post($this->baseUrl . $endpoint, $data);
            }
            
            if (!$response->successful()) {
                throw new Exception('Error en petición GLPI POST: ' . $response->body());
            }
        }

        return $response->json();
    }

    /**
     * Realizar petición PUT a GLPI
     */
    public function put(string $endpoint, array $data = []): array
    {
        $sessionToken = $this->getSessionToken();
        
        $response = Http::timeout($this->timeout)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Session-Token' => $sessionToken,
                'App-Token' => $this->appToken,
            ])
            ->put($this->baseUrl . $endpoint, $data);

        if (!$response->successful()) {
            // Si es error de autenticación, intentar renovar sesión
            if ($response->status() === 401) {
                Cache::forget('glpi_session_token');
                $this->sessionToken = null;
                $sessionToken = $this->getSessionToken();
                
                // Reintentar la petición
                $response = Http::timeout($this->timeout)
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                        'Session-Token' => $sessionToken,
                        'App-Token' => $this->appToken,
                    ])
                    ->put($this->baseUrl . $endpoint, $data);
            }
            
            if (!$response->successful()) {
                throw new Exception('Error en petición GLPI PUT: ' . $response->body());
            }
        }

        return $response->json();
    }

    /**
     * Realizar petición DELETE a GLPI
     */
    public function delete(string $endpoint, array $params = []): array
    {
        $sessionToken = $this->getSessionToken();
        
        $response = Http::timeout($this->timeout)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Session-Token' => $sessionToken,
                'App-Token' => $this->appToken,
            ])
            ->delete($this->baseUrl . $endpoint, $params);

        if (!$response->successful()) {
            // Si es error de autenticación, intentar renovar sesión
            if ($response->status() === 401) {
                Cache::forget('glpi_session_token');
                $this->sessionToken = null;
                $sessionToken = $this->getSessionToken();
                
                // Reintentar la petición
                $response = Http::timeout($this->timeout)
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                        'Session-Token' => $sessionToken,
                        'App-Token' => $this->appToken,
                    ])
                    ->delete($this->baseUrl . $endpoint, $params);
            }
            
            if (!$response->successful()) {
                throw new Exception('Error en petición GLPI DELETE: ' . $response->body());
            }
        }

        return $response->json();
    }

    /**
     * Obtener perfiles del usuario actual
     */
    public function getMyProfiles(): array
    {
        return $this->get('/getMyProfiles');
    }

    /**
     * Obtener perfil activo
     */
    public function getActiveProfile(): array
    {
        return $this->get('/getActiveProfile');
    }

    /**
     * Cambiar perfil activo
     */
    public function changeActiveProfile(int $profilesId): array
    {
        return $this->post('/changeActiveProfile', ['profiles_id' => $profilesId]);
    }

    /**
     * Obtener entidades del usuario actual
     */
    public function getMyEntities(): array
    {
        return $this->get('/getMyEntities');
    }

    /**
     * Cambiar entidad activa
     */
    public function changeActiveEntities(int $entitiesId, bool $isRecursive = false): array
    {
        return $this->post('/changeActiveEntities', [
            'entities_id' => $entitiesId,
            'is_recursive' => $isRecursive
        ]);
    }

    /**
     * Obtener información completa de la sesión
     */
    public function getFullSession(): array
    {
        return $this->get('/getFullSession');
    }

    /**
     * Obtener elementos de un tipo específico
     */
    public function getItems(string $itemType, array $params = []): array
    {
        return $this->get("/{$itemType}", $params);
    }

    /**
     * Obtener un elemento específico
     */
    public function getItem(string $itemType, int $id, array $params = []): array
    {
        return $this->get("/{$itemType}/{$id}", $params);
    }

    /**
     * Crear un nuevo elemento
     */
    public function createItem(string $itemType, array $data): array
    {
        return $this->post("/{$itemType}", ['input' => $data]);
    }

    /**
     * Actualizar un elemento
     */
    public function updateItem(string $itemType, int $id, array $data): array
    {
        $data['id'] = $id;
        return $this->put("/{$itemType}/{$id}", ['input' => $data]);
    }

    /**
     * Eliminar un elemento
     */
    public function deleteItem(string $itemType, int $id, bool $force = false): array
    {
        $params = ['input' => ['id' => $id]];
        if ($force) {
            $params['force_purge'] = true;
        }
        return $this->delete("/{$itemType}/{$id}", $params);
    }

    /**
     * Buscar elementos
     */
    public function search(string $itemType, array $criteria = []): array
    {
        return $this->get("/search/{$itemType}", $criteria);
    }
}