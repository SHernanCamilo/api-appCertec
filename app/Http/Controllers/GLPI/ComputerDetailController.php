<?php

namespace App\Http\Controllers\GLPI;

use App\Http\Controllers\Controller;
use App\Services\GLPI\GLPIService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class ComputerDetailController extends Controller
{
    protected $glpiService;
    protected $sessionToken;

    public function __construct(GLPIService $glpiService)
    {
        $this->glpiService = $glpiService;
    }

    /**
     * Inicializar sesión GLPI y obtener token
     */
    private function initGLPISession(): ?string
    {
        try {
            if ($this->sessionToken) {
                return $this->sessionToken;
            }

            $baseUrl = config('glpi.base_url');
            $userToken = config('glpi.user_token');
            $appToken = config('glpi.app_token');

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'user_token ' . $userToken,
                'App-Token' => $appToken,
            ])->get("{$baseUrl}/initSession");

            if (!$response->successful()) {
                Log::error("Error inicializando sesión GLPI: " . $response->body());
                return null;
            }

            $sessionData = $response->json();
            $this->sessionToken = $sessionData['session_token'] ?? null;
            
            return $this->sessionToken;

        } catch (Exception $e) {
            Log::error("Excepción inicializando sesión GLPI: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Obtener datos básicos de la computadora
     */
    public function getBasicInfo($computerId): JsonResponse
    {
        try {
            $computer = $this->glpiService->getItem('Computer', $computerId, [
                'expand_dropdowns' => true,
                'get_hateoas' => true
            ]);

            $basicInfo = [
                'id' => $computer['id'] ?? null,
                'name' => $computer['name'] ?? null,
                'serial' => $computer['serial'] ?? null,
                'otherserial' => $computer['otherserial'] ?? null,
                'location' => $computer['locations_name'] ?? null,
                'location_id' => $computer['locations_id'] ?? null,
                'manufacturer' => $computer['manufacturers_name'] ?? null,
                'manufacturer_id' => $computer['manufacturers_id'] ?? null,
                'model' => $computer['computermodels_name'] ?? null,
                'model_id' => $computer['computermodels_id'] ?? null,
                'type' => $computer['computertypes_name'] ?? null,
                'type_id' => $computer['computertypes_id'] ?? null,
                'state' => $computer['states_name'] ?? null,
                'state_id' => $computer['states_id'] ?? null,
                'user' => $computer['users_name'] ?? null,
                'user_id' => $computer['users_id'] ?? null,
                'group' => $computer['groups_name'] ?? null,
                'group_id' => $computer['groups_id'] ?? null,
                'date_creation' => $computer['date_creation'] ?? null,
                'date_mod' => $computer['date_mod'] ?? null,
                'comment' => $computer['comment'] ?? null
            ];

            return response()->json([
                'success' => true,
                'data' => $basicInfo
            ]);

        } catch (Exception $e) {
            Log::error("Error obteniendo datos básicos de PC {$computerId}: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener datos básicos de la computadora',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener información de memoria RAM usando llamadas HTTP directas
     */
    public function getMemoryInfo($computerId): JsonResponse
    {
        try {
            // Inicializar sesión GLPI si no existe
            $sessionToken = $this->initGLPISession();
            if (!$sessionToken) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error al inicializar sesión GLPI'
                ], 500);
            }

            // Headers para las peticiones
            $headers = [
                'Content-Type' => 'application/json',
                'Session-Token' => $sessionToken,
                'App-Token' => config('glpi.app_token'),
            ];

            $baseUrl = config('glpi.base_url');

            // Paso 1: Obtener Item_DeviceMemory de la computadora
            $response = Http::withHeaders($headers)->get("{$baseUrl}/Computer/{$computerId}/Item_DeviceMemory");
            
            if (!$response->successful()) {
                Log::error("Error obteniendo Item_DeviceMemory para PC {$computerId}: " . $response->body());
                return response()->json([
                    'success' => false,
                    'message' => 'Error al obtener información de memoria desde GLPI',
                    'error' => $response->body()
                ], 500);
            }

            $memoryItems = $response->json();
            $memoryData = [];
            $totalCapacity = 0;

            if (!empty($memoryItems)) {
                foreach ($memoryItems as $memoryItem) {
                    $devicememories_id = $memoryItem['devicememories_id'] ?? null;
                    $size = $memoryItem['size'] ?? 0;
                    $frequency = $memoryItem['frequency'] ?? null;
                    $serial = $memoryItem['serial'] ?? null;
                    $busID = $memoryItem['busID'] ?? null;
                    
                    $totalCapacity += $size;
                    
                    $memoryInfo = [
                        'id' => $memoryItem['id'] ?? null,
                        'size_mb' => $size,
                        'size_gb' => round($size / 1024, 2),
                        'frequency' => $frequency,
                        'serial' => $serial,
                        'busID' => $busID,
                        'designation' => null,
                        'generation' => null
                    ];

                    // Paso 2: Obtener DeviceMemory para conseguir designation y devicememorytypes_id
                    if ($devicememories_id) {
                        $deviceResponse = Http::withHeaders($headers)->get("{$baseUrl}/DeviceMemory/{$devicememories_id}");
                        
                        if ($deviceResponse->successful()) {
                            $deviceMemory = $deviceResponse->json();
                            $memoryInfo['designation'] = $deviceMemory['designation'] ?? null;
                            $devicememorytypes_id = $deviceMemory['devicememorytypes_id'] ?? null;
                            
                            // Paso 3: Obtener DeviceMemoryType para conseguir la generación
                            if ($devicememorytypes_id) {
                                $typeResponse = Http::withHeaders($headers)->get("{$baseUrl}/DeviceMemoryType/{$devicememorytypes_id}");
                                
                                if ($typeResponse->successful()) {
                                    $memoryType = $typeResponse->json();
                                    $memoryInfo['generation'] = $memoryType['name'] ?? null;
                                }
                            }
                        }
                    }
                    
                    $memoryData[] = $memoryInfo;
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'memories' => $memoryData,
                    'total_capacity_mb' => $totalCapacity,
                    'total_capacity_gb' => round($totalCapacity / 1024, 2),
                    'memory_count' => count($memoryData)
                ]
            ]);

        } catch (Exception $e) {
            Log::error("Error obteniendo memoria de PC {$computerId}: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener información de memoria',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener información del procesador usando llamadas HTTP directas
     */
    public function getProcessorInfo($computerId): JsonResponse
    {
        try {
            // Inicializar sesión GLPI si no existe
            $sessionToken = $this->initGLPISession();
            if (!$sessionToken) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error al inicializar sesión GLPI'
                ], 500);
            }

            // Headers para las peticiones
            $headers = [
                'Content-Type' => 'application/json',
                'Session-Token' => $sessionToken,
                'App-Token' => config('glpi.app_token'),
            ];

            $baseUrl = config('glpi.base_url');

            // Paso 1: Obtener Item_DeviceProcessor de la computadora
            $response = Http::withHeaders($headers)->get("{$baseUrl}/Computer/{$computerId}/Item_DeviceProcessor");
            
            if (!$response->successful()) {
                Log::error("Error obteniendo Item_DeviceProcessor para PC {$computerId}: " . $response->body());
                return response()->json([
                    'success' => false,
                    'message' => 'Error al obtener información del procesador desde GLPI',
                    'error' => $response->body()
                ], 500);
            }

            $processorItems = $response->json();
            $processorData = [];

            if (!empty($processorItems)) {
                foreach ($processorItems as $processorItem) {
                    $deviceprocessors_id = $processorItem['deviceprocessors_id'] ?? null;
                    $frequency = $processorItem['frequency'] ?? null;
                    $nbcores = $processorItem['nbcores'] ?? null;
                    $nbthreads = $processorItem['nbthreads'] ?? null;
                    $serial = $processorItem['serial'] ?? null;
                    $busID = $processorItem['busID'] ?? null;
                    
                    $processorInfo = [
                        'id' => $processorItem['id'] ?? null,
                        'frequency' => $frequency,
                        'nbcores' => $nbcores,
                        'nbthreads' => $nbthreads,
                        'serial' => $serial,
                        'busID' => $busID,
                        'designation' => null,
                        'manufacturer' => null
                    ];

                    // Paso 2: Obtener DeviceProcessor para conseguir designation y manufacturer
                    if ($deviceprocessors_id) {
                        $deviceResponse = Http::withHeaders($headers)->get("{$baseUrl}/DeviceProcessor/{$deviceprocessors_id}");
                        
                        if ($deviceResponse->successful()) {
                            $deviceProcessor = $deviceResponse->json();
                            $processorInfo['designation'] = $deviceProcessor['designation'] ?? null;
                            $processorInfo['manufacturer'] = $deviceProcessor['manufacturers_name'] ?? null;
                        }
                    }
                    
                    $processorData[] = $processorInfo;
                }
            }

            return response()->json([
                'success' => true,
                'data' => $processorData
            ]);

        } catch (Exception $e) {
            Log::error("Error obteniendo procesador de PC {$computerId}: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener información del procesador',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener información de discos duros usando llamadas HTTP directas
     */
    public function getDiskInfo($computerId): JsonResponse
    {
        try {
            // Inicializar sesión GLPI si no existe
            $sessionToken = $this->initGLPISession();
            if (!$sessionToken) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error al inicializar sesión GLPI'
                ], 500);
            }

            // Headers para las peticiones
            $headers = [
                'Content-Type' => 'application/json',
                'Session-Token' => $sessionToken,
                'App-Token' => config('glpi.app_token'),
            ];

            $baseUrl = config('glpi.base_url');

            // Paso 1: Obtener Item_DeviceHardDrive de la computadora
            $response = Http::withHeaders($headers)->get("{$baseUrl}/Computer/{$computerId}/Item_DeviceHardDrive");
            
            if (!$response->successful()) {
                Log::error("Error obteniendo Item_DeviceHardDrive para PC {$computerId}: " . $response->body());
                return response()->json([
                    'success' => false,
                    'message' => 'Error al obtener información de discos desde GLPI',
                    'error' => $response->body()
                ], 500);
            }

            $diskItems = $response->json();
            $diskData = [];
            $totalCapacity = 0;

            if (!empty($diskItems)) {
                foreach ($diskItems as $diskItem) {
                    $deviceharddrives_id = $diskItem['deviceharddrives_id'] ?? null;
                    $capacity = $diskItem['capacity'] ?? 0;
                    $serial = $diskItem['serial'] ?? null;
                    $busID = $diskItem['busID'] ?? null;
                    
                    $totalCapacity += $capacity;
                    
                    $diskInfo = [
                        'id' => $diskItem['id'] ?? null,
                        'capacity_mb' => $capacity,
                        'capacity_gb' => round($capacity / 1024, 2),
                        'serial' => $serial,
                        'busID' => $busID,
                        'designation' => null,
                        'manufacturer' => null,
                        'interface' => null
                    ];

                    // Paso 2: Obtener DeviceHardDrive para conseguir designation, manufacturer e interfacetypes_id
                    if ($deviceharddrives_id) {
                        $deviceResponse = Http::withHeaders($headers)->get("{$baseUrl}/DeviceHardDrive/{$deviceharddrives_id}");
                        
                        if ($deviceResponse->successful()) {
                            $deviceHardDrive = $deviceResponse->json();
                            $diskInfo['designation'] = $deviceHardDrive['designation'] ?? null;
                            $diskInfo['manufacturer'] = $deviceHardDrive['manufacturers_name'] ?? null;
                            $interfacetypes_id = $deviceHardDrive['interfacetypes_id'] ?? null;
                            
                            // Paso 3: Obtener InterfaceType para conseguir el nombre de la interfaz
                            if ($interfacetypes_id) {
                                $interfaceResponse = Http::withHeaders($headers)->get("{$baseUrl}/InterfaceType/{$interfacetypes_id}");
                                
                                if ($interfaceResponse->successful()) {
                                    $interfaceType = $interfaceResponse->json();
                                    $diskInfo['interface'] = $interfaceType['name'] ?? null;
                                }
                            }
                        }
                    }
                    
                    $diskData[] = $diskInfo;
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'disks' => $diskData,
                    'total_capacity_mb' => $totalCapacity,
                    'total_capacity_gb' => round($totalCapacity / 1024, 2),
                    'disk_count' => count($diskData)
                ]
            ]);

        } catch (Exception $e) {
            Log::error("Error obteniendo discos de PC {$computerId}: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener información de discos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener información del sistema operativo
     */
    public function getOperatingSystemInfo($computerId): JsonResponse
    {
        try {
            $computer = $this->glpiService->getItem('Computer', $computerId, [
                'expand_dropdowns' => true
            ]);

            $osInfo = [
                'operating_system' => $computer['operatingsystems_name'] ?? null,
                'os_version' => $computer['operatingsystemversions_name'] ?? null,
                'os_service_pack' => $computer['operatingsystemservicepacks_name'] ?? null,
                'os_architecture' => $computer['operatingsystemarchitectures_name'] ?? null,
                'os_kernel_version' => $computer['operatingsystemkernelversions_name'] ?? null,
                'os_edition' => $computer['operatingsystemeditions_name'] ?? null,
                'license_number' => $computer['licenseid'] ?? null,
                'license_expiration' => $computer['license_expiration'] ?? null
            ];

            return response()->json([
                'success' => true,
                'data' => $osInfo
            ]);

        } catch (Exception $e) {
            Log::error("Error obteniendo SO de PC {$computerId}: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener información del sistema operativo',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener información financiera (Infocom)
     */
    public function getFinancialInfo($computerId): JsonResponse
    {
        try {
            $infocom = $this->glpiService->get('/Infocom', [
                'searchText' => ['items_id' => $computerId, 'itemtype' => 'Computer'],
                'expand_dropdowns' => true
            ]);

            $financialData = null;

            if (!empty($infocom) && isset($infocom[0])) {
                $info = $infocom[0];
                $financialData = [
                    'buy_date' => $info['buy_date'] ?? null,
                    'use_date' => $info['use_date'] ?? null,
                    'warranty_date' => $info['warranty_date'] ?? null,
                    'warranty_duration' => $info['warranty_duration'] ?? null,
                    'supplier' => $info['suppliers_name'] ?? null,
                    'order_number' => $info['order_number'] ?? null,
                    'delivery_number' => $info['delivery_number'] ?? null,
                    'immo_number' => $info['immo_number'] ?? null,
                    'value' => $info['value'] ?? null,
                    'warranty_value' => $info['warranty_value'] ?? null,
                    'sink_time' => $info['sink_time'] ?? null,
                    'sink_type' => $info['sink_type'] ?? null,
                    'sink_coeff' => $info['sink_coeff'] ?? null,
                    'comment' => $info['comment'] ?? null
                ];

                // Calcular edad y vida útil
                if ($financialData['buy_date']) {
                    $buyDate = new \DateTime($financialData['buy_date']);
                    $now = new \DateTime();
                    $age = $buyDate->diff($now);
                    $financialData['age_years'] = $age->y;
                    $financialData['age_months'] = $age->m;
                    $financialData['age_days'] = $age->days;
                }
            }

            return response()->json([
                'success' => true,
                'data' => $financialData
            ]);

        } catch (Exception $e) {
            Log::error("Error obteniendo info financiera de PC {$computerId}: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener información financiera',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener información completa de la computadora
     */
    public function getCompleteInfo($computerId): JsonResponse
    {
        try {
            // Obtener todos los datos en paralelo
            $basicInfo = $this->getBasicInfoData($computerId);
            $memoryInfo = $this->getMemoryInfoData($computerId);
            $processorInfo = $this->getProcessorInfoData($computerId);
            $diskInfo = $this->getDiskInfoData($computerId);
            $osInfo = $this->getOperatingSystemInfoData($computerId);
            $financialInfo = $this->getFinancialInfoData($computerId);

            // Calcular puntuación de obsolescencia
            $obsolescenceScore = $this->calculateObsolescenceScore($basicInfo, $financialInfo, $memoryInfo, $processorInfo);

            return response()->json([
                'success' => true,
                'data' => [
                    'computer_id' => $computerId,
                    'basic_info' => $basicInfo,
                    'memory_info' => $memoryInfo,
                    'processor_info' => $processorInfo,
                    'disk_info' => $diskInfo,
                    'operating_system' => $osInfo,
                    'financial_info' => $financialInfo,
                    'obsolescence_analysis' => $obsolescenceScore,
                    'last_updated' => now()->toISOString()
                ]
            ]);

        } catch (Exception $e) {
            Log::error("Error obteniendo información completa de PC {$computerId}: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener información completa de la computadora',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener información de tags del equipo usando llamadas HTTP directas
     */
    public function getTagInfo($computerId): JsonResponse
    {
        try {
            // Inicializar sesión GLPI si no existe
            $sessionToken = $this->initGLPISession();
            if (!$sessionToken) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error al inicializar sesión GLPI'
                ], 500);
            }

            // Headers para las peticiones
            $headers = [
                'Content-Type' => 'application/json',
                'Session-Token' => $sessionToken,
                'App-Token' => config('glpi.app_token'),
            ];

            $baseUrl = config('glpi.base_url');

            // Obtener información básica del equipo que incluye otherserial y comment (posibles tags)
            $response = Http::withHeaders($headers)->get("{$baseUrl}/Computer/{$computerId}?expand_dropdowns=true");
            
            if (!$response->successful()) {
                Log::error("Error obteniendo información básica para tags de PC {$computerId}: " . $response->body());
                return response()->json([
                    'success' => false,
                    'message' => 'Error al obtener información de tags desde GLPI',
                    'error' => $response->body()
                ], 500);
            }

            $computerData = $response->json();
            
            $tagInfo = [
                'computer_tag' => $computerData['otherserial'] ?? null, // Tag principal del equipo
                'inventory_tag' => $computerData['comment'] ?? null,    // Tag de inventario
                'asset_tag' => $computerData['otherserial'] ?? null,    // Tag de activo
                'serial_number' => $computerData['serial'] ?? null,     // Número de serie
                'name' => $computerData['name'] ?? null,                // Nombre del equipo
                'agent_tag' => null,                                    // Tag del agente
                'agent_info' => null                                    // Información completa del agente
            ];

            // Obtener información del agente usando el endpoint /Agent/ con items_id
            try {
                $agentResponse = Http::withHeaders($headers)->get("{$baseUrl}/Agent", [
                    'searchText' => ['items_id' => $computerId, 'itemtype' => 'Computer'],
                    'expand_dropdowns' => true
                ]);
                
                if ($agentResponse->successful()) {
                    $agents = $agentResponse->json();
                    if (!empty($agents) && isset($agents[0])) {
                        $agent = $agents[0];
                        $tagInfo['agent_tag'] = $agent['tag'] ?? null;
                        $tagInfo['agent_info'] = [
                            'id' => $agent['id'] ?? null,
                            'tag' => $agent['tag'] ?? null,
                            'name' => $agent['name'] ?? null,
                            'version' => $agent['version'] ?? null,
                            'last_contact' => $agent['last_contact'] ?? null,
                            'status' => $agent['status'] ?? null,
                            'useragent' => $agent['useragent'] ?? null
                        ];
                    }
                }
            } catch (Exception $e) {
                Log::info("Error obteniendo información del agente para PC {$computerId}: " . $e->getMessage());
            }

            // Intentar obtener tags adicionales si existen endpoints específicos
            try {
                $tagResponse = Http::withHeaders($headers)->get("{$baseUrl}/Computer/{$computerId}/Item_DeviceTag");
                
                if ($tagResponse->successful()) {
                    $tags = $tagResponse->json();
                    if (!empty($tags)) {
                        $tagInfo['additional_tags'] = $tags;
                    }
                }
            } catch (Exception $e) {
                // Si no existe el endpoint de tags, continuamos con la información básica
                Log::info("Endpoint de tags adicionales no disponible para PC {$computerId}");
            }

            return response()->json([
                'success' => true,
                'data' => $tagInfo
            ]);

        } catch (Exception $e) {
            Log::error("Error obteniendo tags de PC {$computerId}: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener información de tags',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function validateComputer($computerId): JsonResponse
    {
        try {
            $computer = $this->glpiService->getItem('Computer', $computerId);
            
            if (empty($computer) || isset($computer['ERROR'])) {
                return response()->json([
                    'success' => false,
                    'message' => "No se encontró la computadora con ID {$computerId}",
                    'exists' => false
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => "Computadora encontrada",
                'exists' => true,
                'data' => [
                    'id' => $computer['id'],
                    'name' => $computer['name'] ?? 'Sin nombre',
                    'is_deleted' => $computer['is_deleted'] ?? false,
                    'is_template' => $computer['is_template'] ?? false
                ]
            ]);

        } catch (Exception $e) {
            Log::error("Error validando PC {$computerId}: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error al validar la computadora',
                'error' => $e->getMessage(),
                'exists' => false
            ], 500);
        }
    }

    // Métodos privados para obtener datos sin respuesta HTTP
    private function getBasicInfoData($computerId): array
    {
        try {
            $computer = $this->glpiService->getItem('Computer', $computerId, [
                'expand_dropdowns' => true
            ]);

            return [
                'id' => $computer['id'] ?? null,
                'name' => $computer['name'] ?? null,
                'serial' => $computer['serial'] ?? null,
                'otherserial' => $computer['otherserial'] ?? null,
                'location' => $computer['locations_name'] ?? null,
                'manufacturer' => $computer['manufacturers_name'] ?? null,
                'model' => $computer['computermodels_name'] ?? null,
                'type' => $computer['computertypes_name'] ?? null,
                'state' => $computer['states_name'] ?? null,
                'user' => $computer['users_name'] ?? null,
                'group' => $computer['groups_name'] ?? null,
                'date_creation' => $computer['date_creation'] ?? null,
                'date_mod' => $computer['date_mod'] ?? null
            ];
        } catch (Exception $e) {
            return [];
        }
    }

    private function getMemoryInfoData($computerId): array
    {
        try {
            // Inicializar sesión GLPI si no existe
            $sessionToken = $this->initGLPISession();
            if (!$sessionToken) {
                return ['total_gb' => 0, 'modules' => []];
            }

            // Headers para las peticiones
            $headers = [
                'Content-Type' => 'application/json',
                'Session-Token' => $sessionToken,
                'App-Token' => config('glpi.app_token'),
            ];

            $baseUrl = config('glpi.base_url');

            // Obtener Item_DeviceMemory de la computadora
            $response = Http::withHeaders($headers)->get("{$baseUrl}/Computer/{$computerId}/Item_DeviceMemory");
            
            if (!$response->successful()) {
                return ['total_gb' => 0, 'modules' => []];
            }

            $memoryItems = $response->json();
            $totalCapacity = 0;
            $memoryData = [];

            if (!empty($memoryItems)) {
                foreach ($memoryItems as $memoryItem) {
                    $devicememories_id = $memoryItem['devicememories_id'] ?? null;
                    $size = $memoryItem['size'] ?? 0;
                    $totalCapacity += $size;
                    
                    $generation = null;
                    
                    // Obtener la generación siguiendo la cadena de endpoints
                    if ($devicememories_id) {
                        $deviceResponse = Http::withHeaders($headers)->get("{$baseUrl}/DeviceMemory/{$devicememories_id}");
                        
                        if ($deviceResponse->successful()) {
                            $deviceMemory = $deviceResponse->json();
                            $devicememorytypes_id = $deviceMemory['devicememorytypes_id'] ?? null;
                            
                            if ($devicememorytypes_id) {
                                $typeResponse = Http::withHeaders($headers)->get("{$baseUrl}/DeviceMemoryType/{$devicememorytypes_id}");
                                
                                if ($typeResponse->successful()) {
                                    $memoryType = $typeResponse->json();
                                    $generation = $memoryType['name'] ?? null;
                                }
                            }
                        }
                    }
                    
                    $memoryData[] = [
                        'size_gb' => round($size / 1024, 2),
                        'type' => $generation
                    ];
                }
            }

            return [
                'total_gb' => round($totalCapacity / 1024, 2),
                'modules' => $memoryData
            ];
        } catch (Exception $e) {
            return ['total_gb' => 0, 'modules' => []];
        }
    }

    private function getProcessorInfoData($computerId): array
    {
        try {
            // Inicializar sesión GLPI si no existe
            $sessionToken = $this->initGLPISession();
            if (!$sessionToken) {
                return [];
            }

            // Headers para las peticiones
            $headers = [
                'Content-Type' => 'application/json',
                'Session-Token' => $sessionToken,
                'App-Token' => config('glpi.app_token'),
            ];

            $baseUrl = config('glpi.base_url');

            // Obtener Item_DeviceProcessor de la computadora
            $response = Http::withHeaders($headers)->get("{$baseUrl}/Computer/{$computerId}/Item_DeviceProcessor");
            
            if (!$response->successful()) {
                return [];
            }

            $processorItems = $response->json();

            if (!empty($processorItems) && isset($processorItems[0])) {
                $processorItem = $processorItems[0];
                $deviceprocessors_id = $processorItem['deviceprocessors_id'] ?? null;
                
                $processorInfo = [
                    'designation' => null,
                    'frequency' => $processorItem['frequency'] ?? null,
                    'cores' => $processorItem['nbcores'] ?? null
                ];

                // Obtener DeviceProcessor para conseguir designation
                if ($deviceprocessors_id) {
                    $deviceResponse = Http::withHeaders($headers)->get("{$baseUrl}/DeviceProcessor/{$deviceprocessors_id}");
                    
                    if ($deviceResponse->successful()) {
                        $deviceProcessor = $deviceResponse->json();
                        $processorInfo['designation'] = $deviceProcessor['designation'] ?? null;
                    }
                }

                return $processorInfo;
            }

            return [];
        } catch (Exception $e) {
            return [];
        }
    }

    private function getDiskInfoData($computerId): array
    {
        try {
            // Inicializar sesión GLPI si no existe
            $sessionToken = $this->initGLPISession();
            if (!$sessionToken) {
                return ['total_gb' => 0];
            }

            // Headers para las peticiones
            $headers = [
                'Content-Type' => 'application/json',
                'Session-Token' => $sessionToken,
                'App-Token' => config('glpi.app_token'),
            ];

            $baseUrl = config('glpi.base_url');

            // Obtener Item_DeviceHardDrive de la computadora
            $response = Http::withHeaders($headers)->get("{$baseUrl}/Computer/{$computerId}/Item_DeviceHardDrive");
            
            if (!$response->successful()) {
                return ['total_gb' => 0];
            }

            $diskItems = $response->json();
            $totalCapacity = 0;

            if (!empty($diskItems)) {
                foreach ($diskItems as $diskItem) {
                    $capacity = $diskItem['capacity'] ?? 0;
                    $totalCapacity += $capacity;
                }
            }

            return ['total_gb' => round($totalCapacity / 1024, 2)];
        } catch (Exception $e) {
            return ['total_gb' => 0];
        }
    }

    private function getOperatingSystemInfoData($computerId): array
    {
        try {
            $computer = $this->glpiService->getItem('Computer', $computerId, [
                'expand_dropdowns' => true
            ]);

            return [
                'name' => $computer['operatingsystems_name'] ?? null,
                'version' => $computer['operatingsystemversions_name'] ?? null
            ];
        } catch (Exception $e) {
            return [];
        }
    }

    private function getFinancialInfoData($computerId): array
    {
        try {
            $infocom = $this->glpiService->get('/Infocom', [
                'searchText' => ['items_id' => $computerId, 'itemtype' => 'Computer'],
                'expand_dropdowns' => true
            ]);

            if (!empty($infocom) && isset($infocom[0])) {
                $info = $infocom[0];
                $data = [
                    'buy_date' => $info['buy_date'] ?? null,
                    'warranty_date' => $info['warranty_date'] ?? null
                ];

                if ($data['buy_date']) {
                    $buyDate = new \DateTime($data['buy_date']);
                    $now = new \DateTime();
                    $age = $buyDate->diff($now);
                    $data['age_years'] = $age->y;
                }

                return $data;
            }

            return [];
        } catch (Exception $e) {
            return [];
        }
    }

    private function calculateObsolescenceScore($basicInfo, $financialInfo, $memoryInfo, $processorInfo): array
    {
        $score = 0;
        $factors = [];

        // Factor edad (40% del peso)
        if (isset($financialInfo['age_years'])) {
            $age = $financialInfo['age_years'];
            if ($age <= 2) {
                $ageScore = 100;
                $ageStatus = 'Óptimo';
            } elseif ($age <= 4) {
                $ageScore = 75;
                $ageStatus = 'Funcional';
            } elseif ($age <= 6) {
                $ageScore = 50;
                $ageStatus = 'Potencialmente Obsoleto';
            } else {
                $ageScore = 25;
                $ageStatus = 'Obsoleto';
            }
            $factors['age'] = ['score' => $ageScore, 'status' => $ageStatus, 'years' => $age];
            $score += $ageScore * 0.4;
        }

        // Factor memoria RAM (30% del peso)
        if (isset($memoryInfo['total_gb'])) {
            $ram = $memoryInfo['total_gb'];
            if ($ram >= 16) {
                $ramScore = 100;
                $ramStatus = 'Excelente';
            } elseif ($ram >= 8) {
                $ramScore = 75;
                $ramStatus = 'Bueno';
            } elseif ($ram >= 4) {
                $ramScore = 50;
                $ramStatus = 'Suficiente';
            } else {
                $ramScore = 25;
                $ramStatus = 'Insuficiente';
            }
            $factors['memory'] = ['score' => $ramScore, 'status' => $ramStatus, 'gb' => $ram];
            $score += $ramScore * 0.3;
        }

        // Factor procesador (30% del peso)
        if (isset($processorInfo['cores'])) {
            $cores = $processorInfo['cores'];
            if ($cores >= 8) {
                $cpuScore = 100;
                $cpuStatus = 'Excelente';
            } elseif ($cores >= 4) {
                $cpuScore = 75;
                $cpuStatus = 'Bueno';
            } elseif ($cores >= 2) {
                $cpuScore = 50;
                $cpuStatus = 'Suficiente';
            } else {
                $cpuScore = 25;
                $cpuStatus = 'Insuficiente';
            }
            $factors['processor'] = ['score' => $cpuScore, 'status' => $cpuStatus, 'cores' => $cores];
            $score += $cpuScore * 0.3;
        }

        // Determinar estado general
        if ($score >= 80) {
            $overallStatus = 'Óptimo';
            $color = '#198754';
        } elseif ($score >= 60) {
            $overallStatus = 'Funcional';
            $color = '#0dcaf0';
        } elseif ($score >= 40) {
            $overallStatus = 'Potencialmente Obsoleto';
            $color = '#ffc107';
        } else {
            $overallStatus = 'Obsoleto';
            $color = '#dc3545';
        }

        return [
            'overall_score' => round($score, 2),
            'overall_status' => $overallStatus,
            'color' => $color,
            'factors' => $factors,
            'recommendation' => $this->getRecommendation($overallStatus, $factors)
        ];
    }

    private function getRecommendation($status, $factors): string
    {
        switch ($status) {
            case 'Óptimo':
                return 'El equipo está en excelentes condiciones y no requiere actualización inmediata.';
            case 'Funcional':
                return 'El equipo funciona correctamente pero considere planificar una actualización en el mediano plazo.';
            case 'Potencialmente Obsoleto':
                return 'El equipo muestra signos de obsolescencia. Considere actualizar componentes o reemplazar el equipo.';
            case 'Obsoleto':
                return 'El equipo está obsoleto y debe ser reemplazado prioritariamente.';
            default:
                return 'No se pudo determinar el estado del equipo.';
        }
    }
}