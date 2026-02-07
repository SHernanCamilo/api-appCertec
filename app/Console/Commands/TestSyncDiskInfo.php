<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\GLPI\GLPIService;
use App\Services\GLPI\GLPIComputerService;
use App\Http\Controllers\GLPI\ComputerDetailController;
use App\Models\MatrizObsolescencia\MatzobsActivosC;
use App\Models\MatrizObsolescencia\MatzobsActivosD;

class TestSyncDiskInfo extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'glpi:test-sync-disk {computer-id=2173}';

    /**
     * The console command description.
     */
    protected $description = 'Probar la sincronización de información de discos para un equipo específico';

    protected $glpiService;
    protected $glpiComputerService;

    public function __construct(GLPIService $glpiService, GLPIComputerService $glpiComputerService)
    {
        parent::__construct();
        $this->glpiService = $glpiService;
        $this->glpiComputerService = $glpiComputerService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $computerId = $this->argument('computer-id');
        
        $this->info("🔍 Probando sincronización de información de discos para equipo ID: {$computerId}");
        $this->newLine();

        try {
            // 1. Obtener información del equipo desde GLPI
            $this->info('📋 Paso 1: Obteniendo información del equipo desde GLPI...');
            
            $computer = $this->glpiComputerService->getComputer($computerId, [
                'expand_dropdowns' => true,
                'with_devices' => true,
                'with_infocoms' => true
            ]);
            
            if (empty($computer)) {
                $this->error("❌ No se encontró el equipo {$computerId} en GLPI");
                return 1;
            }
            
            $this->line("   ✅ Equipo encontrado: " . ($computer['name'] ?? 'Sin nombre'));
            $this->newLine();

            // 2. Probar extracción de información de discos usando el método mejorado
            $this->info('💽 Paso 2: Probando extracción de información de discos...');
            
            // Simular las funciones del comando de sincronización
            $diskType = $this->extractDiskType($computer);
            $diskSize = $this->extractDiskSize($computer);
            $diskInterface = $this->extractDiskInterface($computer);
            
            $this->line("   📊 RESULTADOS DE EXTRACCIÓN:");
            $this->line("   • Tipo de disco: " . ($diskType ?? 'No detectado'));
            $this->line("   • Tamaño de disco: " . ($diskSize ? $diskSize . ' MB (' . round($diskSize / 1024, 2) . ' GB)' : 'No detectado'));
            $this->line("   • Interfaz de conexión: " . ($diskInterface ?? 'No detectado'));
            $this->newLine();

            // 3. Comparar con el método del ComputerDetailController
            $this->info('🔬 Paso 3: Comparando con ComputerDetailController...');
            
            $computerDetailController = new ComputerDetailController($this->glpiService);
            $diskInfoResponse = $computerDetailController->getDiskInfo($computerId);
            
            if ($diskInfoResponse->getStatusCode() === 200) {
                $diskData = json_decode($diskInfoResponse->getContent(), true);
                
                if ($diskData['success'] && !empty($diskData['data']['disks'])) {
                    $this->line("   📋 DATOS DEL CONTROLLER:");
                    $this->line("   • Total de discos: " . count($diskData['data']['disks']));
                    $this->line("   • Capacidad total: " . $diskData['data']['total_capacity_gb'] . ' GB (' . $diskData['data']['total_capacity_mb'] . ' MB)');
                    
                    foreach ($diskData['data']['disks'] as $index => $disk) {
                        $this->line("   • Disco " . ($index + 1) . ":");
                        $this->line("     - Designación: " . ($disk['designation'] ?? 'N/A'));
                        $this->line("     - Interfaz: " . ($disk['interface'] ?? 'N/A'));
                        $this->line("     - Capacidad: " . $disk['capacity_gb'] . ' GB');
                        $this->line("     - Fabricante: " . ($disk['manufacturer'] ?? 'N/A'));
                    }
                } else {
                    $this->warn("   ⚠️  No se encontraron discos en el controller");
                }
            } else {
                $this->error("   ❌ Error en ComputerDetailController");
            }
            $this->newLine();

            // 4. Verificar si el equipo ya existe en la base de datos local
            $this->info('🗄️  Paso 4: Verificando base de datos local...');
            
            $existingC = MatzobsActivosC::where('id_activo_glpi', $computerId)->first();
            
            if ($existingC) {
                $this->line("   ✅ Equipo encontrado en BD local:");
                $this->line("   • ID local: " . $existingC->id);
                $this->line("   • Nombre: " . $existingC->nombre_equipo);
                $this->line("   • Agente: " . $existingC->agente);
                $this->line("   • Última sincronización: " . ($existingC->date_u_sincronizacion ?? 'Nunca'));
                
                if ($existingC->detalles) {
                    $this->line("   📋 DETALLES ACTUALES EN BD:");
                    $this->line("   • Tipo disco: " . ($existingC->detalles->tipo_disco ?? 'No registrado'));
                    $this->line("   • Tamaño disco: " . ($existingC->detalles->tamano_disco ? $existingC->detalles->tamano_disco . ' MB' : 'No registrado'));
                    $this->line("   • Interfaz conexión: " . ($existingC->detalles->interfaz_conexion ?? 'No registrado'));
                } else {
                    $this->warn("   ⚠️  No se encontraron detalles técnicos en BD");
                }
            } else {
                $this->line("   ℹ️  Equipo no existe en BD local (se crearía en sincronización)");
            }
            $this->newLine();

            // 5. Simular actualización de datos
            $this->info('🔄 Paso 5: Simulando actualización de datos...');
            
            $this->line("   📊 DATOS QUE SE SINCRONIZARÍAN:");
            $this->line("   • Tipo disco: " . ($diskType ?? 'NULL') . 
                       ($existingC && $existingC->detalles && $existingC->detalles->tipo_disco !== $diskType ? 
                        " (cambiaría de: " . ($existingC->detalles->tipo_disco ?? 'NULL') . ")" : ""));
            
            $this->line("   • Tamaño disco: " . ($diskSize ? $diskSize . ' MB' : 'NULL') . 
                       ($existingC && $existingC->detalles && $existingC->detalles->tamano_disco !== $diskSize ? 
                        " (cambiaría de: " . ($existingC->detalles->tamano_disco ?? 'NULL') . " MB)" : ""));
            
            $this->line("   • Interfaz conexión: " . ($diskInterface ?? 'NULL') . 
                       ($existingC && $existingC->detalles && $existingC->detalles->interfaz_conexion !== $diskInterface ? 
                        " (cambiaría de: " . ($existingC->detalles->interfaz_conexion ?? 'NULL') . ")" : ""));
            
            $this->newLine();

            // 6. Verificar si hay cambios
            $hasChanges = false;
            if ($existingC && $existingC->detalles) {
                $hasChanges = (
                    $existingC->detalles->tipo_disco !== $diskType ||
                    $existingC->detalles->tamano_disco !== $diskSize ||
                    $existingC->detalles->interfaz_conexion !== $diskInterface
                );
            } else {
                $hasChanges = true; // Nuevo registro o sin detalles
            }

            if ($hasChanges) {
                $this->info('✅ HAY CAMBIOS - El equipo se actualizaría en una sincronización');
            } else {
                $this->info('ℹ️  SIN CAMBIOS - El equipo no necesita actualización');
            }

            $this->newLine();
            $this->info('🎉 Prueba completada exitosamente');
            
            // 7. Mostrar comando para sincronizar este equipo específico
            $this->info('💡 Para sincronizar este equipo específicamente, ejecuta:');
            $this->line("   php artisan glpi:sync-activos --single-asset={$computerId}");

            return 0;

        } catch (\Exception $e) {
            $this->newLine();
            $this->error('❌ Error durante la prueba:');
            $this->error("   {$e->getMessage()}");
            $this->newLine();
            
            $this->warn('💡 Posibles soluciones:');
            $this->line('   1. Verificar que el equipo existe en GLPI');
            $this->line('   2. Comprobar permisos de acceso a los datos');
            $this->line('   3. Verificar conectividad con GLPI');
            $this->line('   4. Revisar logs para más detalles');
            
            return 1;
        }
    }

    /**
     * Simular la función extractDiskType del comando de sincronización
     */
    private function extractDiskType($computer)
    {
        try {
            $computerDetailController = new ComputerDetailController($this->glpiService);
            $diskInfoResponse = $computerDetailController->getDiskInfo($computer['id']);
            
            if ($diskInfoResponse->getStatusCode() === 200) {
                $diskData = json_decode($diskInfoResponse->getContent(), true);
                
                if ($diskData['success'] && !empty($diskData['data']['disks'])) {
                    $diskTypes = [];
                    foreach ($diskData['data']['disks'] as $disk) {
                        $interface = $disk['interface'] ?? '';
                        $designation = $disk['designation'] ?? '';
                        
                        if (stripos($interface, 'NVME') !== false || stripos($designation, 'NVME') !== false) {
                            $diskTypes[] = 'SSD NVMe';
                        } elseif (stripos($interface, 'SATA') !== false || stripos($designation, 'SSD') !== false) {
                            $diskTypes[] = 'SSD SATA';
                        } elseif (stripos($interface, 'SATA') !== false || stripos($designation, 'HDD') !== false) {
                            $diskTypes[] = 'HDD SATA';
                        } elseif (!empty($interface)) {
                            $diskTypes[] = $interface;
                        } else {
                            $diskTypes[] = 'Desconocido';
                        }
                    }
                    
                    if (!empty($diskTypes)) {
                        $uniqueTypes = array_unique($diskTypes);
                        return implode(', ', $uniqueTypes);
                    }
                }
            }
            
            return 'Desconocido';
            
        } catch (\Exception $e) {
            return 'Error: ' . $e->getMessage();
        }
    }

    /**
     * Simular la función extractDiskSize del comando de sincronización
     */
    private function extractDiskSize($computer)
    {
        try {
            $computerDetailController = new ComputerDetailController($this->glpiService);
            $diskInfoResponse = $computerDetailController->getDiskInfo($computer['id']);
            
            if ($diskInfoResponse->getStatusCode() === 200) {
                $diskData = json_decode($diskInfoResponse->getContent(), true);
                
                if ($diskData['success'] && !empty($diskData['data']['total_capacity_mb'])) {
                    return (int) $diskData['data']['total_capacity_mb'];
                }
            }
            
            return null;
            
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Simular la función extractDiskInterface del comando de sincronización
     */
    private function extractDiskInterface($computer)
    {
        try {
            $computerDetailController = new ComputerDetailController($this->glpiService);
            $diskInfoResponse = $computerDetailController->getDiskInfo($computer['id']);
            
            if ($diskInfoResponse->getStatusCode() === 200) {
                $diskData = json_decode($diskInfoResponse->getContent(), true);
                
                if ($diskData['success'] && !empty($diskData['data']['disks'])) {
                    $interfaces = [];
                    foreach ($diskData['data']['disks'] as $disk) {
                        if (!empty($disk['interface'])) {
                            $interfaces[] = $disk['interface'];
                        }
                    }
                    
                    if (!empty($interfaces)) {
                        $uniqueInterfaces = array_unique($interfaces);
                        return implode(', ', $uniqueInterfaces);
                    }
                }
            }
            
            return null;
            
        } catch (\Exception $e) {
            return 'Error: ' . $e->getMessage();
        }
    }
}