<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

class ValidarTiempoRespuestaApi extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'api:test-response-time {--endpoint=} {--all} {--timeout=30}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Valida el tiempo de respuesta de las APIs del sistema';

    /**
     * Lista de endpoints críticos para validar
     */
    private $criticalEndpoints = [
        // Autenticación
        'POST /api/auth/login' => [
            'method' => 'POST',
            'data' => ['email' => 'test@example.com', 'password' => 'password'],
            'requires_auth' => false,
            'description' => 'Login de usuario'
        ],
        
        // Usuarios
        'GET /api/users' => [
            'method' => 'GET',
            'data' => null,
            'requires_auth' => true,
            'description' => 'Lista de usuarios'
        ],
        
        // Microsoft Auth
        'GET /api/users/tenant/obtener' => [
            'method' => 'GET',
            'data' => null,
            'requires_auth' => true,
            'description' => 'Usuarios del tenant Microsoft'
        ],
        
        // Empresas
        'GET /api/empresas' => [
            'method' => 'GET',
            'data' => null,
            'requires_auth' => true,
            'description' => 'Lista de empresas'
        ],
        
        // Roles
        'GET /api/roles' => [
            'method' => 'GET',
            'data' => null,
            'requires_auth' => true,
            'description' => 'Lista de roles'
        ],
        
        // Permisos
        'GET /api/permisos' => [
            'method' => 'GET',
            'data' => null,
            'requires_auth' => true,
            'description' => 'Lista de permisos'
        ],
        
        // Sidebar
        'GET /api/auth/sidebar-modules' => [
            'method' => 'GET',
            'data' => null,
            'requires_auth' => true,
            'description' => 'Menú sidebar'
        ],
        
        // Contexto
        'GET /api/contexto' => [
            'method' => 'GET',
            'data' => null,
            'requires_auth' => true,
            'description' => 'Contexto del usuario'
        ],
        
        // Sucursales
        'GET /api/sucursales' => [
            'method' => 'GET',
            'data' => null,
            'requires_auth' => true,
            'description' => 'Lista de sucursales'
        ],
        
        // Sedes
        'GET /api/sedes' => [
            'method' => 'GET',
            'data' => null,
            'requires_auth' => true,
            'description' => 'Lista de sedes'
        ]
    ];

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🚀 Iniciando validación de tiempo de respuesta de APIs');
        $this->info('================================================');

        $timeout = $this->option('timeout');
        $specificEndpoint = $this->option('endpoint');
        $testAll = $this->option('all');

        // Obtener token de autenticación si es necesario
        $authToken = null;
        if ($this->needsAuthentication()) {
            $authToken = $this->getAuthToken();
            if (!$authToken) {
                $this->error('❌ No se pudo obtener token de autenticación');
                return 1;
            }
        }

        $results = [];
        $endpointsToTest = $this->getEndpointsToTest($specificEndpoint, $testAll);

        foreach ($endpointsToTest as $endpoint => $config) {
            $this->line("🔍 Probando: {$config['description']} ({$endpoint})");
            
            $result = $this->testEndpoint($endpoint, $config, $authToken, $timeout);
            $results[] = $result;
            
            $this->displayResult($result);
            $this->line('');
        }

        $this->displaySummary($results);
        
        return 0;
    }

    /**
     * Verifica si algún endpoint necesita autenticación
     */
    private function needsAuthentication()
    {
        foreach ($this->criticalEndpoints as $config) {
            if ($config['requires_auth']) {
                return true;
            }
        }
        return false;
    }

    /**
     * Obtiene token de autenticación para las pruebas
     */
    private function getAuthToken()
    {
        try {
            $this->info('🔑 Obteniendo token de autenticación...');
            
            // Buscar un usuario administrador para las pruebas
            $adminUser = \App\Models\User::whereHas('rolesCustom', function($query) {
                $query->where('name', 'like', '%admin%');
            })->first();

            if (!$adminUser) {
                $adminUser = \App\Models\User::first();
            }

            if (!$adminUser) {
                $this->error('❌ No hay usuarios en la base de datos para generar token');
                return null;
            }

            // Usar JWT Auth para generar token
            $token = auth('api')->login($adminUser);
            
            if (!$token) {
                $this->error('❌ No se pudo generar el token JWT');
                return null;
            }
            
            $this->info("✅ Token JWT obtenido para usuario: {$adminUser->email}");
            return $token;
            
        } catch (\Exception $e) {
            $this->error("❌ Error al obtener token: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Obtiene los endpoints a probar según las opciones
     */
    private function getEndpointsToTest($specificEndpoint, $testAll)
    {
        if ($specificEndpoint) {
            $found = false;
            foreach ($this->criticalEndpoints as $endpoint => $config) {
                if (str_contains($endpoint, $specificEndpoint)) {
                    $found = true;
                    return [$endpoint => $config];
                }
            }
            
            if (!$found) {
                $this->error("❌ Endpoint '{$specificEndpoint}' no encontrado");
                $this->info('Endpoints disponibles:');
                foreach ($this->criticalEndpoints as $endpoint => $config) {
                    $this->line("  - {$endpoint} ({$config['description']})");
                }
                exit(1);
            }
        }

        return $this->criticalEndpoints;
    }

    /**
     * Prueba un endpoint específico
     */
    private function testEndpoint($endpoint, $config, $authToken, $timeout)
    {
        $startTime = microtime(true);
        
        try {
            $baseUrl = rtrim(config('app.url'), '/');
            $path = explode(' ', $endpoint)[1];
            $url = $baseUrl . $path;
            
            $headers = ['Accept' => 'application/json'];
            
            if ($config['requires_auth'] && $authToken) {
                $headers['Authorization'] = "Bearer {$authToken}";
            }

            $response = Http::timeout($timeout)
                ->withHeaders($headers)
                ->send($config['method'], $url, $config['data'] ? ['json' => $config['data']] : []);

            $endTime = microtime(true);
            $responseTime = round(($endTime - $startTime) * 1000, 2); // en milisegundos

            return [
                'endpoint' => $endpoint,
                'description' => $config['description'],
                'method' => $config['method'],
                'url' => $url,
                'status_code' => $response->status(),
                'response_time' => $responseTime,
                'success' => $response->successful(),
                'error' => null,
                'response_size' => strlen($response->body())
            ];

        } catch (\Exception $e) {
            $endTime = microtime(true);
            $responseTime = round(($endTime - $startTime) * 1000, 2);

            return [
                'endpoint' => $endpoint,
                'description' => $config['description'],
                'method' => $config['method'],
                'url' => $url ?? 'N/A',
                'status_code' => 0,
                'response_time' => $responseTime,
                'success' => false,
                'error' => $e->getMessage(),
                'response_size' => 0
            ];
        }
    }

    /**
     * Muestra el resultado de una prueba
     */
    private function displayResult($result)
    {
        $status = $result['success'] ? '✅' : '❌';
        $statusText = $result['success'] ? 'OK' : 'ERROR';
        
        $this->line("   {$status} {$statusText} | {$result['response_time']}ms | HTTP {$result['status_code']} | {$this->formatBytes($result['response_size'])}");
        
        if (!$result['success'] && $result['error']) {
            $this->line("      Error: {$result['error']}");
        }
    }

    /**
     * Muestra resumen de todas las pruebas
     */
    private function displaySummary($results)
    {
        $this->info('📊 RESUMEN DE PRUEBAS');
        $this->info('====================');

        $successful = collect($results)->where('success', true)->count();
        $failed = collect($results)->where('success', false)->count();
        $total = count($results);

        $avgResponseTime = collect($results)->where('success', true)->avg('response_time');
        $maxResponseTime = collect($results)->where('success', true)->max('response_time');
        $minResponseTime = collect($results)->where('success', true)->min('response_time');

        $this->line("Total de endpoints probados: {$total}");
        $this->line("Exitosos: {$successful}");
        $this->line("Fallidos: {$failed}");
        
        if ($successful > 0) {
            $this->line("Tiempo promedio de respuesta: " . round($avgResponseTime, 2) . "ms");
            $this->line("Tiempo máximo de respuesta: " . round($maxResponseTime, 2) . "ms");
            $this->line("Tiempo mínimo de respuesta: " . round($minResponseTime, 2) . "ms");
        }

        $this->line('');

        // Mostrar endpoints lentos (>2000ms)
        $slowEndpoints = collect($results)->where('response_time', '>', 2000);
        if ($slowEndpoints->count() > 0) {
            $this->warn('⚠️  ENDPOINTS LENTOS (>2s):');
            foreach ($slowEndpoints as $result) {
                $this->line("   - {$result['description']}: {$result['response_time']}ms");
            }
            $this->line('');
        }

        // Mostrar endpoints fallidos
        $failedEndpoints = collect($results)->where('success', false);
        if ($failedEndpoints->count() > 0) {
            $this->error('❌ ENDPOINTS FALLIDOS:');
            foreach ($failedEndpoints as $result) {
                $this->line("   - {$result['description']}: {$result['error']}");
            }
        }
    }

    /**
     * Formatea bytes a formato legible
     */
    private function formatBytes($bytes)
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . 'MB';
        } elseif ($bytes >= 1024) {
            return round($bytes / 1024, 2) . 'KB';
        } else {
            return $bytes . 'B';
        }
    }
}
