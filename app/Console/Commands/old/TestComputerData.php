<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Http\Controllers\GLPI\ComputerDetailController;
use App\Services\GLPI\GLPIService;
use Exception;

class TestComputerData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'glpi:test-computer {id=2173}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Probar obtención de datos detallados de una computadora específica';

    protected $computerDetailController;

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $computerId = $this->argument('id');
        
        $this->info("🔍 Probando obtención de datos para la computadora ID: {$computerId}");
        $this->newLine();

        // Crear instancia del controlador
        $glpiService = app(GLPIService::class);
        $controller = new ComputerDetailController($glpiService);

        try {
            // Test 1: Validar que existe la computadora
            $this->info('✅ Test 1: Validando existencia de la computadora...');
            $validation = $controller->validateComputer($computerId);
            $validationData = json_decode($validation->getContent(), true);
            
            // Echo completo de todos los datos del array de validación
            $this->line("📋 DATOS COMPLETOS DEL ARRAY DE VALIDACIÓN:");
            $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            $this->line(json_encode($validationData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            $this->newLine();
            
            if (!$validationData['success']) {
                $this->error("❌ La computadora con ID {$computerId} no existe o no es accesible");
                $this->error("   Error: " . $validationData['message']);
                return 1;
            }
            
            $this->line("   ✅ Computadora encontrada: " . ($validationData['data']['name'] ?? 'Sin nombre'));
            $this->newLine();

            // Test 2: Obtener datos básicos
            $this->info('📋 Test 2: Obteniendo datos básicos...');
            $basicInfo = $controller->getBasicInfo($computerId);
            $basicData = json_decode($basicInfo->getContent(), true);
            
            if ($basicData['success']) {
                $data = $basicData['data'];
                
                // Echo completo de todos los datos básicos
                $this->line("📋 DATOS BÁSICOS COMPLETOS:");
                $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
                $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
                $this->newLine();
                
                $this->line("   ✅ Datos básicos obtenidos:");
                $this->line("   � Nombre: " . ($data['name'] ?? 'N/A'));
                $this->line("   🏭 Fabricante: " . ($data['manufacturer'] ?? 'N/A'));
                $this->line("   💻 Modelo: " . ($data['model'] ?? 'N/A'));
                $this->line("   📍 Ubicación: " . ($data['location'] ?? 'N/A'));
                $this->line("   👤 Usuario: " . ($data['user'] ?? 'N/A'));
                $this->line("   🔢 Serial: " . ($data['serial'] ?? 'N/A'));
            } else {
                $this->warn("   ⚠️  No se pudieron obtener datos básicos");
                $this->line("📋 ERROR EN DATOS BÁSICOS:");
                $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
                $this->line(json_encode($basicData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            }
            $this->newLine();

            // Test 3: Obtener información de memoria
            $this->info('🧠 Test 3: Obteniendo información de memoria RAM...');
            $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            
            $memoryInfo = $controller->getMemoryInfo($computerId);
            $memoryData = json_decode($memoryInfo->getContent(), true);
            
            // Echo completo de todos los datos de memoria
            $this->line("🧠 DATOS COMPLETOS DE MEMORIA (ARRAY COMPLETO):");
            $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            $this->line(json_encode($memoryData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            $this->newLine();
            
            if ($memoryData['success']) {
                $data = $memoryData['data'];
                
                $this->line("   ✅ Información de memoria obtenida exitosamente:");
                $this->line("   💾 Capacidad total: " . ($data['total_capacity_gb'] ?? 0) . " GB (" . ($data['total_capacity_mb'] ?? 0) . " MB)");
                $this->line("   🔢 Módulos instalados: " . ($data['memory_count'] ?? 0));
                $this->newLine();
                
                if (!empty($data['memories'])) {
                    $this->line("   🔧 DETALLES DE CADA MÓDULO DE MEMORIA:");
                    foreach ($data['memories'] as $index => $memory) {
                        $this->line("     📋 Módulo " . ($index + 1) . ":");
                        $this->line("       • ID: " . ($memory['id'] ?? 'N/A'));
                        $this->line("       • Capacidad: " . ($memory['size_gb'] ?? 0) . " GB (" . ($memory['size_mb'] ?? 0) . " MB)");
                        $this->line("       • Designación: " . ($memory['designation'] ?? 'N/A'));
                        $this->line("       • Generación RAM: " . ($memory['generation'] ?? 'N/A'));
                        $this->line("       • Frecuencia: " . ($memory['frequency'] ?? 'N/A') . " MHz");
                        $this->line("       • Número de serie: " . ($memory['serial'] ?? 'N/A'));
                        $this->line("       • Bus ID: " . ($memory['busID'] ?? 'N/A'));
                        $this->newLine();
                    }
                    
                    // Resumen consolidado
                    $this->line("   📊 RESUMEN CONSOLIDADO:");
                    $totalGB = $data['total_capacity_gb'] ?? 0;
                    $moduleCount = $data['memory_count'] ?? 0;
                    $generations = array_unique(array_filter(array_column($data['memories'], 'generation')));
                    
                    $this->line("     • Total: {$totalGB} GB en {$moduleCount} módulo(s)");
                    $this->line("     • Generaciones: " . (empty($generations) ? 'N/A' : implode(', ', $generations)));
                    
                    // Mostrar configuración típica
                    if ($moduleCount > 0) {
                        $avgSize = round($totalGB / $moduleCount, 2);
                        $this->line("     • Configuración: {$moduleCount} x {$avgSize} GB");
                    }
                } else {
                    $this->warn("     ⚠️  No se encontraron módulos de memoria instalados");
                }
                
            } else {
                $this->error("   ❌ Error al obtener información de memoria:");
                $this->error("     Mensaje: " . ($memoryData['message'] ?? 'Error desconocido'));
                if (isset($memoryData['error'])) {
                    $this->error("     Detalles: " . $memoryData['error']);
                }
                
                $this->newLine();
                $this->warn("   💡 Posibles causas:");
                $this->line("     1. La computadora no tiene memoria RAM registrada en GLPI");
                $this->line("     2. Problemas de permisos para acceder a los datos de hardware");
                $this->line("     3. Error en la conexión con la API de GLPI");
                $this->line("     4. La computadora existe pero no tiene inventario de hardware");
            }
            $this->newLine();

            // Test 4: Obtener información del procesador
            $this->info('⚡ Test 4: Obteniendo información del procesador...');
            $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            $processorInfo = $controller->getProcessorInfo($computerId);

            // Echo completo de todos los datos del procesador
            $this->line("⚡ DATOS COMPLETOS DEL PROCESADOR (ARRAY COMPLETO):");
            $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            $this->line(json_encode($processorInfo->getData(true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            $this->newLine();

            $processorData = json_decode($processorInfo->getContent(), true);
            
            if ($processorData['success'] && !empty($processorData['data'])) {
                $this->line("   ✅ Información del procesador obtenida exitosamente:");
                $this->line("   🔢 Procesadores encontrados: " . count($processorData['data']));
                $this->newLine();
                
                foreach ($processorData['data'] as $index => $data) {
                    $this->line("   🔧 PROCESADOR " . ($index + 1) . ":");
                    $this->line("     • ID: " . ($data['id'] ?? 'N/A'));
                    $this->line("     • Designación: " . ($data['designation'] ?? 'N/A'));
                    $this->line("     • Fabricante: " . ($data['manufacturer'] ?? 'N/A'));
                    $this->line("     • Frecuencia: " . ($data['frequency'] ?? 'N/A') . " MHz");
                    $this->line("     • Núcleos: " . ($data['nbcores'] ?? 'N/A'));
                    $this->line("     • Hilos: " . ($data['nbthreads'] ?? 'N/A'));
                    $this->line("     • Número de serie: " . ($data['serial'] ?? 'N/A'));
                    $this->line("     • Bus ID: " . ($data['busID'] ?? 'N/A'));
                    $this->newLine();
                }
                
                // Resumen consolidado
                if (count($processorData['data']) > 0) {
                    $firstProcessor = $processorData['data'][0];
                    $this->line("   📊 RESUMEN CONSOLIDADO:");
                    $this->line("     • Modelo principal: " . ($firstProcessor['designation'] ?? 'N/A'));
                    $this->line("     • Núcleos totales: " . ($firstProcessor['nbcores'] ?? 'N/A'));
                    $this->line("     • Hilos totales: " . ($firstProcessor['nbthreads'] ?? 'N/A'));
                    $this->line("     • Frecuencia: " . ($firstProcessor['frequency'] ?? 'N/A') . " MHz");
                }
            } else {
                $this->error("   ❌ Error al obtener información del procesador:");
                $this->error("     Mensaje: " . ($processorData['message'] ?? 'Error desconocido'));
                if (isset($processorData['error'])) {
                    $this->error("     Detalles: " . $processorData['error']);
                }
                
                $this->newLine();
                $this->warn("   💡 Posibles causas:");
                $this->line("     1. La computadora no tiene procesador registrado en GLPI");
                $this->line("     2. Problemas de permisos para acceder a los datos de hardware");
                $this->line("     3. Error en la conexión con la API de GLPI");
                $this->line("     4. La computadora existe pero no tiene inventario de procesador");
            }
            $this->newLine();

            // Test 5: Obtener información de discos
            $this->info('💽 Test 5: Obteniendo información de discos...');
            $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            
            $diskInfo = $controller->getDiskInfo($computerId);
            
            // Echo completo de todos los datos de discos
            $this->line("💽 DATOS COMPLETOS DE DISCOS (ARRAY COMPLETO):");
            $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            $this->line(json_encode($diskInfo->getData(true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            $this->newLine();
            
            $diskData = json_decode($diskInfo->getContent(), true);
            
            if ($diskData['success']) {
                $data = $diskData['data'];
                
                $this->line("   ✅ Información de discos obtenida exitosamente:");
                $this->line("   💾 Capacidad total: " . ($data['total_capacity_gb'] ?? 0) . " GB (" . ($data['total_capacity_mb'] ?? 0) . " MB)");
                $this->line("   🔢 Discos instalados: " . ($data['disk_count'] ?? 0));
                $this->newLine();
                
                if (!empty($data['disks'])) {
                    $this->line("   💽 DETALLES DE CADA DISCO:");
                    foreach ($data['disks'] as $index => $disk) {
                        $this->line("     📋 Disco " . ($index + 1) . ":");
                        $this->line("       • ID: " . ($disk['id'] ?? 'N/A'));
                        $this->line("       • Capacidad: " . ($disk['capacity_gb'] ?? 0) . " GB (" . ($disk['capacity_mb'] ?? 0) . " MB)");
                        $this->line("       • Designación: " . ($disk['designation'] ?? 'N/A'));
                        $this->line("       • Fabricante: " . ($disk['manufacturer'] ?? 'N/A'));
                        $this->line("       • Interfaz: " . ($disk['interface'] ?? 'N/A'));
                        $this->line("       • Número de serie: " . ($disk['serial'] ?? 'N/A'));
                        $this->line("       • Bus ID: " . ($disk['busID'] ?? 'N/A'));
                        $this->newLine();
                    }
                    
                    // Resumen consolidado
                    $this->line("   📊 RESUMEN CONSOLIDADO:");
                    $totalGB = $data['total_capacity_gb'] ?? 0;
                    $diskCount = $data['disk_count'] ?? 0;
                    $interfaces = array_unique(array_filter(array_column($data['disks'], 'interface')));
                    
                    $this->line("     • Total: {$totalGB} GB en {$diskCount} disco(s)");
                    $this->line("     • Interfaces: " . (empty($interfaces) ? 'N/A' : implode(', ', $interfaces)));
                    
                    if ($diskCount > 0) {
                        $avgSize = round($totalGB / $diskCount, 2);
                        $this->line("     • Capacidad promedio: {$avgSize} GB por disco");
                    }
                } else {
                    $this->warn("     ⚠️  No se encontraron discos instalados");
                }
                
            } else {
                $this->error("   ❌ Error al obtener información de discos:");
                $this->error("     Mensaje: " . ($diskData['message'] ?? 'Error desconocido'));
                if (isset($diskData['error'])) {
                    $this->error("     Detalles: " . $diskData['error']);
                }
                
                $this->newLine();
                $this->warn("   💡 Posibles causas:");
                $this->line("     1. La computadora no tiene discos registrados en GLPI");
                $this->line("     2. Problemas de permisos para acceder a los datos de hardware");
                $this->line("     3. Error en la conexión con la API de GLPI");
                $this->line("     4. La computadora existe pero no tiene inventario de discos");
            }
            $this->newLine();

            // Test 6: Obtener información financiera
            $this->info('💰 Test 6: Obteniendo información financiera...');
            $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            
            $financialInfo = $controller->getFinancialInfo($computerId);
            
            // Echo completo de todos los datos financieros
            $this->line("💰 DATOS COMPLETOS FINANCIEROS (ARRAY COMPLETO):");
            $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            $this->line(json_encode($financialInfo->getData(true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            $this->newLine();
            
            $financialData = json_decode($financialInfo->getContent(), true);
            
            if ($financialData['success'] && $financialData['data']) {
                $data = $financialData['data'];
                
                $this->line("   ✅ Información financiera obtenida exitosamente:");
                $this->newLine();
                
                $this->line("   💰 DETALLES FINANCIEROS COMPLETOS:");
                $this->line("     • Fecha de compra: " . ($data['buy_date'] ?? 'N/A'));
                $this->line("     • Fecha de uso: " . ($data['use_date'] ?? 'N/A'));
                $this->line("     • Fecha de garantía: " . ($data['warranty_date'] ?? 'N/A'));
                $this->line("     • Duración garantía: " . ($data['warranty_duration'] ?? 'N/A') . " meses");
                $this->line("     • Proveedor: " . ($data['supplier'] ?? 'N/A'));
                $this->line("     • Número de orden: " . ($data['order_number'] ?? 'N/A'));
                $this->line("     • Número de entrega: " . ($data['delivery_number'] ?? 'N/A'));
                $this->line("     • Número inmobiliario: " . ($data['immo_number'] ?? 'N/A'));
                $this->line("     • Valor: " . ($data['value'] ?? 'N/A'));
                $this->line("     • Valor de garantía: " . ($data['warranty_value'] ?? 'N/A'));
                $this->line("     • Tiempo de depreciación: " . ($data['sink_time'] ?? 'N/A'));
                $this->line("     • Tipo de depreciación: " . ($data['sink_type'] ?? 'N/A'));
                $this->line("     • Coeficiente depreciación: " . ($data['sink_coeff'] ?? 'N/A'));
                $this->line("     • Comentarios: " . ($data['comment'] ?? 'N/A'));
                $this->newLine();
                
                // Información de antigüedad
                if (isset($data['age_years'])) {
                    $this->line("   ⏰ ANÁLISIS DE ANTIGÜEDAD:");
                    $this->line("     • Antigüedad: " . $data['age_years'] . " años");
                    if (isset($data['age_months'])) {
                        $this->line("     • Antigüedad detallada: " . $data['age_years'] . " años, " . $data['age_months'] . " meses");
                    }
                    if (isset($data['age_days'])) {
                        $this->line("     • Total de días: " . $data['age_days'] . " días");
                    }
                }
                
                // Resumen consolidado
                $this->line("   📊 RESUMEN CONSOLIDADO:");
                $this->line("     • Estado financiero: " . ($data['buy_date'] ? 'Registrado' : 'Sin registrar'));
                $this->line("     • Garantía: " . ($data['warranty_date'] ? 'Definida' : 'No definida'));
                $this->line("     • Proveedor: " . ($data['supplier'] ? 'Registrado' : 'No registrado'));
                $this->line("     • Valor económico: " . ($data['value'] ? 'Registrado' : 'No registrado'));
                
            } else {
                $this->error("   ❌ Error al obtener información financiera:");
                $this->error("     Mensaje: " . ($financialData['message'] ?? 'Error desconocido'));
                if (isset($financialData['error'])) {
                    $this->error("     Detalles: " . $financialData['error']);
                }
                
                $this->newLine();
                $this->warn("   💡 Posibles causas:");
                $this->line("     1. La computadora no tiene información financiera registrada en GLPI");
                $this->line("     2. Problemas de permisos para acceder a los datos financieros");
                $this->line("     3. Error en la conexión con la API de GLPI");
                $this->line("     4. La computadora existe pero no tiene datos de Infocom");
            }
            $this->newLine();

            // Test 9: Obtener información de tags del equipo
            $this->info('�️  Test 9: Obteniendo información de tags del equipo...');
            $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            
            $tagInfo = $controller->getTagInfo($computerId);
            
            // Echo completo de todos los datos de tags
            $this->line("🏷️  DATOS COMPLETOS DE TAGS (ARRAY COMPLETO):");
            $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            $this->line(json_encode($tagInfo->getData(true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            $this->newLine();
            
            $tagData = json_decode($tagInfo->getContent(), true);
            
            if ($tagData['success']) {
                $data = $tagData['data'];
                
                $this->line("   ✅ Información de tags obtenida exitosamente:");
                $this->newLine();
                
                $this->line("   🏷️  DETALLES DE TAGS DEL EQUIPO:");
                $this->line("     • Tag del equipo: " . ($data['computer_tag'] ?? 'N/A'));
                $this->line("     • Tag de inventario: " . ($data['inventory_tag'] ?? 'N/A'));
                $this->line("     • Tag de activo: " . ($data['asset_tag'] ?? 'N/A'));
                $this->line("     • Tag del agente: " . ($data['agent_tag'] ?? 'N/A'));
                $this->line("     • Número de serie: " . ($data['serial_number'] ?? 'N/A'));
                $this->line("     • Nombre del equipo: " . ($data['name'] ?? 'N/A'));
                $this->newLine();
                
                // Información del agente si está disponible
                if (isset($data['agent_info']) && !empty($data['agent_info'])) {
                    $agent = $data['agent_info'];
                    $this->line("   🤖 INFORMACIÓN DEL AGENTE GLPI:");
                    $this->line("     • ID del agente: " . ($agent['id'] ?? 'N/A'));
                    $this->line("     • Tag del agente: " . ($agent['tag'] ?? 'N/A'));
                    $this->line("     • Nombre del agente: " . ($agent['name'] ?? 'N/A'));
                    $this->line("     • Versión: " . ($agent['version'] ?? 'N/A'));
                    $this->line("     • Último contacto: " . ($agent['last_contact'] ?? 'N/A'));
                    $this->line("     • Estado: " . ($agent['status'] ?? 'N/A'));
                    $this->line("     • User Agent: " . ($agent['useragent'] ?? 'N/A'));
                    $this->newLine();
                } else {
                    $this->line("   🤖 No se encontró información del agente GLPI");
                    $this->newLine();
                }
                
                // Tags adicionales si existen
                if (isset($data['additional_tags']) && !empty($data['additional_tags'])) {
                    $this->line("   🔖 TAGS ADICIONALES:");
                    foreach ($data['additional_tags'] as $index => $tag) {
                        $this->line("     📋 Tag " . ($index + 1) . ":");
                        $this->line("       • ID: " . ($tag['id'] ?? 'N/A'));
                        $this->line("       • Valor: " . ($tag['value'] ?? 'N/A'));
                        $this->line("       • Tipo: " . ($tag['type'] ?? 'N/A'));
                        $this->newLine();
                    }
                } else {
                    $this->line("   📝 No se encontraron tags adicionales específicos");
                }
                
                // Resumen consolidado
                $this->line("   📊 RESUMEN CONSOLIDADO DE IDENTIFICACIÓN:");
                $primaryTag = $data['agent_tag'] ?? $data['computer_tag'] ?? $data['inventory_tag'] ?? $data['name'] ?? 'N/A';
                $this->line("     • Identificador principal: " . $primaryTag);
                $this->line("     • Tag del agente: " . ($data['agent_tag'] ?? 'N/A'));
                $this->line("     • Tag de inventario: " . ($data['inventory_tag'] ?? 'N/A'));
                $this->line("     • Número de serie: " . ($data['serial_number'] ?? 'N/A'));
                $this->line("     • Tags disponibles: " . (
                    ($data['agent_tag'] ? 1 : 0) +
                    ($data['computer_tag'] ? 1 : 0) + 
                    ($data['inventory_tag'] ? 1 : 0) + 
                    (isset($data['additional_tags']) ? count($data['additional_tags']) : 0)
                ));
                
            } else {
                $this->error("   ❌ Error al obtener información de tags:");
                $this->error("     Mensaje: " . ($tagData['message'] ?? 'Error desconocido'));
                if (isset($tagData['error'])) {
                    $this->error("     Detalles: " . $tagData['error']);
                }
                
                $this->newLine();
                $this->warn("   💡 Posibles causas:");
                $this->line("     1. La computadora no tiene tags registrados en GLPI");
                $this->line("     2. Problemas de permisos para acceder a los datos de identificación");
                $this->line("     3. Error en la conexión con la API de GLPI");
                $this->line("     4. La computadora existe pero no tiene información de tags");
            }
            $this->newLine();

            $this->info('🎉 ¡Todos los tests completados exitosamente!');
            $this->info("✅ Los datos de la computadora {$computerId} se obtuvieron correctamente.");
            
            return 0;

        } catch (Exception $e) {
            $this->newLine();
            $this->error('❌ Error durante las pruebas:');
            $this->error("   {$e->getMessage()}");
            $this->newLine();
            
            $this->warn('💡 Posibles soluciones:');
            $this->line('   1. Verificar que la computadora existe en GLPI');
            $this->line('   2. Comprobar permisos de acceso a los datos');
            $this->line('   3. Verificar conectividad con GLPI');
            $this->line('   4. Revisar logs para más detalles');
            
            return 1;
        }
    }
}
