<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\GLPI\GLPIService;
use App\Services\GLPI\GLPIComputerService;
use App\Services\MatrizObsolescenciaCalculatorService;
use App\Models\MatrizObsolescencia\MatzobsActivosC;
use App\Models\MatrizObsolescencia\MatzobsActivosD;
use App\Models\MatrizObsAgente;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SincronizarActivosGlpi extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'glpi:sync-activos 
                           {--batch=50 : Número de activos a procesar por lote}
                           {--offset=0 : Desde qué registro comenzar}
                           {--limit=2500 : Límite total de registros a procesar}
                           {--force : Forzar actualización de registros existentes}
                           {--check-deleted : Verificar activos eliminados en GLPI}
                           {--sync-days=7 : Días para considerar si un activo necesita sincronización}
                           {--single-asset= : ID específico de activo GLPI para sincronizar solo ese activo}
                           {--full-sync : Realizar sincronización completa de todos los activos}
                           {--skip-calculations : Omitir cálculos automáticos después de la sincronización}
                           {--calculate-only : Solo calcular valores, no sincronizar desde GLPI}
                           {--sync-id= : ID de sincronización para actualizar progreso en cache}';

    /**
     * The console command description.
     */
    protected $description = 'Sincroniza activos de GLPI con la base de datos local. Soporta sincronización completa, por días, o de un activo específico';

    protected $glpiService;
    protected $glpiComputerService;
    protected $calculatorService;
    protected $syncId = null; // ID de sincronización para progreso
    
    // Cache para evitar llamadas duplicadas
    protected $deviceMemoryTypeCache = [];
    protected $deviceProcessorCache = [];
    protected $agentCache = [];
    protected $agentParamsCache = []; // Cache para parámetros de agentes (empresa, sede, sucursal)
    
    // Contadores para throttling
    protected $apiCallCount = 0;
    protected $lastApiCallTime = null;
    
    // Configuración de throttling optimizada
    protected $maxApiCallsPerSecond = 30;
    protected $pauseBetweenBatches = 0.5; // segundos (reducido)
    protected $pauseBetweenApiCalls = 50000; // microsegundos (0.05 segundos - reducido)
    protected $maxMemoryUsagePercent = 80; // Porcentaje máximo de memoria antes de limpiar caches

    public function __construct(GLPIService $glpiService, GLPIComputerService $glpiComputerService, MatrizObsolescenciaCalculatorService $calculatorService)
    {
        parent::__construct();
        $this->glpiService = $glpiService;
        $this->glpiComputerService = $glpiComputerService;
        $this->calculatorService = $calculatorService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $startTime = now();
        $batchSize = (int) $this->option('batch');
        $offset = (int) $this->option('offset');
        $limit = (int) $this->option('limit');
        $force = $this->option('force');
        $checkDeleted = $this->option('check-deleted');
        $syncDays = (int) $this->option('sync-days');
        $singleAsset = $this->option('single-asset');
        $fullSync = $this->option('full-sync');
        $skipCalculations = $this->option('skip-calculations');
        $calculateOnly = $this->option('calculate-only');
        $this->syncId = $this->option('sync-id'); // Capturar sync-id para progreso

        // Si solo se van a calcular valores, ejecutar el comando de cálculo
        if ($calculateOnly) {
            $this->info("🧮 Modo solo cálculo activado - ejecutando cálculos sin sincronización");
            return $this->call('matriz:calcular-valores', [
                '--batch' => $batchSize,
                '--force' => $force
            ]);
        }

        // Determinar el modo de sincronización
        $syncMode = $this->determineSyncMode($singleAsset, $fullSync, $force);
        
        // Log inicio del proceso
        Log::channel('glpi_sync')->info('=== INICIO SINCRONIZACIÓN GLPI ===', [
            'sync_mode' => $syncMode,
            'batch_size' => $batchSize,
            'offset' => $offset,
            'limit' => $limit,
            'force' => $force,
            'check_deleted' => $checkDeleted,
            'sync_days' => $syncDays,
            'single_asset' => $singleAsset,
            'full_sync' => $fullSync,
            'skip_calculations' => $skipCalculations,
            'calculate_only' => $calculateOnly,
            'sync_id' => $this->syncId,
            'start_time' => $startTime
        ]);

        $this->info("🚀 Iniciando sincronización de activos GLPI");
        $this->info("📊 Configuración:");
        $this->info("   - Modo: {$syncMode}");
        
        if ($singleAsset) {
            $this->info("   - Activo específico: {$singleAsset}");
            return $this->handleSingleAssetSync($singleAsset, $skipCalculations);
        }
        
        $this->info("   - Lotes de: {$batchSize}");
        $this->info("   - Offset inicial: {$offset}");
        $this->info("   - Límite total: {$limit}");
        $this->info("   - Forzar actualización: " . ($force ? 'Sí' : 'No'));
        $this->info("   - Verificar eliminados: " . ($checkDeleted ? 'Sí' : 'No'));
        $this->info("   - Días de sincronización: {$syncDays}");
        $this->info("   - Sincronización completa: " . ($fullSync ? 'Sí' : 'No'));
        $this->info("   - Omitir cálculos: " . ($skipCalculations ? 'Sí' : 'No'));
        if ($this->syncId) {
            $this->info("   - Sync ID: {$this->syncId}");
        }

        $processedCount = 0;
        $currentOffset = $offset;
        $totalStats = [
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'agents_found' => 0,      // TAGs encontrados en matzobs_agentes
            'agents_not_found' => 0   // TAGs no encontrados (valores por defecto)
        ];

        // Verificar conexión con GLPI
        if (!$this->testGlpiConnection()) {
            $this->error("❌ No se pudo conectar con GLPI. Abortando sincronización.");
            $this->markSyncError("No se pudo conectar con GLPI");
            return 1;
        }

        // Si es sincronización completa, obtener el total de activos en GLPI
        if ($fullSync) {
            $totalAssets = $this->getTotalAssetsCount();
            if ($totalAssets > 0) {
                $limit = $totalAssets;
                $this->info("� Total de activos en GLPI: {$totalAssets}");
            }
        }

        // Actualizar progreso inicial
        $this->updateProgress(0, $limit, $totalStats);

        // Procesar lotes
        while ($processedCount < $limit) {
            $remainingItems = $limit - $processedCount;
            $currentBatchSize = min($batchSize, $remainingItems);

            $this->info("\n📦 Procesando lote: offset {$currentOffset}, tamaño {$currentBatchSize}");
            
            $progressBar = $this->output->createProgressBar($currentBatchSize);
            $progressBar->setFormat('verbose');

            try {
                $result = $this->processBatch($currentOffset, $currentBatchSize, $force, $syncDays, $progressBar, $skipCalculations);
                
                $progressBar->finish();
                $this->newLine();
                
                $this->info("✅ Lote procesado:");
                $this->info("   - Procesados: {$result['processed']}");
                $this->info("   - Nuevos: {$result['created']}");
                $this->info("   - Actualizados: {$result['updated']}");
                $this->info("   - Omitidos: {$result['skipped']}");
                if ($result['errors'] > 0) {
                    $this->warn("   - Errores: {$result['errors']}");
                }

                // Acumular estadísticas
                foreach ($result as $key => $value) {
                    $totalStats[$key] += $value;
                }

                // Actualizar progreso con el número real de procesados
                $processedCount += $result['processed'];
                $this->updateProgress($processedCount, $limit, $totalStats);

                // Si no se procesó ningún registro, probablemente llegamos al final
                if ($result['processed'] === 0) {
                    $this->info("✅ No hay más registros para procesar.");
                    break;
                }
                
                // Si se procesaron menos registros de los esperados, podría ser el último lote
                if ($result['processed'] < $currentBatchSize) {
                    $this->info("ℹ️  Último lote procesado (se obtuvieron {$result['processed']} de {$currentBatchSize} esperados).");
                    // No hacer break aquí, continuar hasta alcanzar el límite
                }

            } catch (\Exception $e) {
                $progressBar->finish();
                $this->newLine();
                
                $this->error("❌ Error procesando lote: " . $e->getMessage());
                Log::channel('glpi_sync')->error("Error procesando lote", [
                    'offset' => $currentOffset,
                    'batch_size' => $currentBatchSize,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                
                $totalStats['errors']++;
                
                if (!$this->confirm('¿Continuar con el siguiente lote?')) {
                    break;
                }
            }

            // Actualizar offset para el siguiente lote
            $currentOffset += $currentBatchSize;

            // Pausa entre lotes para no sobrecargar la API
            if ($processedCount < $limit) {
                $this->info("⏳ Pausa de {$this->pauseBetweenBatches} segundos entre lotes...");
                sleep($this->pauseBetweenBatches);
                
                // Verificar uso de memoria y limpiar si es necesario
                $this->checkMemoryUsage();
                
                // Limpiar caches cada 5 lotes para liberar memoria
                if (($processedCount / $batchSize) % 5 === 0) {
                    $this->info("🧹 Limpiando caches periódicamente...");
                    $this->clearCaches();
                }
            }
        }

        // Ejecutar cálculos finales si no se omitieron
        if (!$skipCalculations && ($totalStats['created'] > 0 || $totalStats['updated'] > 0)) {
            $this->info("\n🧮 Ejecutando cálculos automáticos para activos sincronizados...");
            
            try {
                // Obtener IDs de activos que fueron creados o actualizados
                $recentlyUpdated = MatzobsActivosC::where('date_u_sincronizacion', '>=', $startTime)
                    ->pluck('id')
                    ->toArray();
                
                if (!empty($recentlyUpdated)) {
                    $calculationResult = $this->calculatorService->calcularValoresLote($recentlyUpdated, $batchSize);
                    
                    $this->info("✅ Cálculos completados:");
                    $this->info("   - Activos calculados: {$calculationResult['exitosos']}");
                    if ($calculationResult['errores'] > 0) {
                        $this->warn("   - Errores en cálculos: {$calculationResult['errores']}");
                    }
                    
                    $totalStats['calculations'] = $calculationResult;
                }
            } catch (\Exception $e) {
                $this->warn("⚠️  Error ejecutando cálculos automáticos: " . $e->getMessage());
                Log::channel('glpi_sync')->warning("Error en cálculos automáticos", [
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Ejecutar cálculos finales si no se omitieron
        if (!$skipCalculations && ($totalStats['created'] > 0 || $totalStats['updated'] > 0)) {
            $this->info("\n🧮 Ejecutando cálculos automáticos para activos sincronizados...");
            
            try {
                // Obtener IDs de activos que fueron creados o actualizados
                $recentlyUpdated = MatzobsActivosC::where('date_u_sincronizacion', '>=', $startTime)
                    ->pluck('id')
                    ->toArray();
                
                if (!empty($recentlyUpdated)) {
                    $calculationResult = $this->calculatorService->calcularValoresLote($recentlyUpdated, $batchSize);
                    
                    $this->info("✅ Cálculos completados:");
                    $this->info("   - Activos calculados: {$calculationResult['exitosos']}");
                    if ($calculationResult['errores'] > 0) {
                        $this->warn("   - Errores en cálculos: {$calculationResult['errores']}");
                    }
                    
                    $totalStats['calculations'] = $calculationResult;
                }
            } catch (\Exception $e) {
                $this->warn("⚠️  Error ejecutando cálculos automáticos: " . $e->getMessage());
                Log::channel('glpi_sync')->warning("Error en cálculos automáticos", [
                    'error' => $e->getMessage()
                ]);
            }
        }

        if ($checkDeleted || $fullSync) {
            $this->info("\n🔍 Verificando activos eliminados en GLPI...");
            $deletedCount = $this->checkDeletedAssets();
            $this->info("🗑️  Activos marcados como eliminados: {$deletedCount}");
            $totalStats['deleted'] = $deletedCount;
        }

        $endTime = now();
        $duration = $endTime->diffInSeconds($startTime);

        // Resumen final
        $memoryPeak = memory_get_peak_usage(true);
        $this->info("\n🎉 Sincronización completada!");
        $this->info("📈 Estadísticas finales:");
        $this->info("   - Total procesados: {$totalStats['processed']}");
        $this->info("   - Nuevos creados: {$totalStats['created']}");
        $this->info("   - Actualizados: {$totalStats['updated']}");
        $this->info("   - Omitidos: {$totalStats['skipped']}");
        if (isset($totalStats['deleted'])) {
            $this->info("   - Eliminados: {$totalStats['deleted']}");
        }
        if ($totalStats['errors'] > 0) {
            $this->warn("   - Errores: {$totalStats['errors']}");
        }
        $this->info("\n📋 Asignación de Agentes:");
        $this->info("   - TAGs encontrados en BD: {$totalStats['agents_found']}");
        if ($totalStats['agents_not_found'] > 0) {
            $this->warn("   - TAGs no encontrados (valores por defecto): {$totalStats['agents_not_found']}");
        }
        $this->info("\n⏱️  Tiempo total: {$duration} segundos");
        $this->info("💾 Memoria pico: " . round($memoryPeak / 1024 / 1024, 2) . " MB");
        $this->info("🔄 Total llamadas API: {$this->apiCallCount}");

        // Marcar sincronización como completada
        $this->markSyncCompleted($totalStats);

        // Log final
        Log::channel('glpi_sync')->info('=== FIN SINCRONIZACIÓN GLPI ===', [
            'sync_mode' => $syncMode,
            'stats' => $totalStats,
            'duration_seconds' => $duration,
            'memory_peak_mb' => round($memoryPeak / 1024 / 1024, 2),
            'total_api_calls' => $this->apiCallCount,
            'end_time' => $endTime,
            'sync_id' => $this->syncId
        ]);

        return 0;
    }

    private function determineSyncMode($singleAsset, $fullSync, $force)
    {
        if ($singleAsset) {
            return 'Activo específico';
        } elseif ($fullSync) {
            return 'Sincronización completa';
        } elseif ($force) {
            return 'Forzada';
        } else {
            return 'Por días de sincronización';
        }
    }
    
    /**
     * Controla el throttling de llamadas API para evitar sobrecarga
     */
    private function throttleApiCall()
    {
        $this->apiCallCount++;
        
        // Pausa entre cada llamada API (reducida)
        if ($this->lastApiCallTime !== null) {
            $timeSinceLastCall = microtime(true) - $this->lastApiCallTime;
            $minTimeBetweenCalls = $this->pauseBetweenApiCalls / 1000000;
            
            if ($timeSinceLastCall < $minTimeBetweenCalls) {
                $sleepTime = ($minTimeBetweenCalls - $timeSinceLastCall) * 1000000;
                usleep((int) $sleepTime);
            }
        }
        
        $this->lastApiCallTime = microtime(true);
        
        // Pausa más larga cada 100 llamadas (aumentado de 50)
        if ($this->apiCallCount % 100 === 0) {
            $this->info("⏸️  Pausa de seguridad después de {$this->apiCallCount} llamadas API...");
            sleep(1); // Reducido de 2 a 1 segundo
        }
    }
    
    /**
     * Ejecuta una llamada API con reintentos y backoff exponencial
     */
    private function apiCallWithRetry(callable $callback, $maxRetries = 3, $initialDelay = 1)
    {
        $attempt = 0;
        $lastException = null;
        
        while ($attempt < $maxRetries) {
            try {
                $this->throttleApiCall();
                return $callback();
            } catch (\Exception $e) {
                $lastException = $e;
                $attempt++;
                
                if ($attempt < $maxRetries) {
                    $delay = $initialDelay * pow(2, $attempt - 1); // Backoff exponencial
                    Log::channel('glpi_sync')->warning("Error en llamada API, reintentando en {$delay}s (intento {$attempt}/{$maxRetries})", [
                        'error' => $e->getMessage()
                    ]);
                    sleep($delay);
                } else {
                    Log::channel('glpi_sync')->error("Error en llamada API después de {$maxRetries} intentos", [
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
        
        throw $lastException;
    }
    
    /**
     * Limpia los caches para liberar memoria
     */
    private function clearCaches()
    {
        $memoryBefore = memory_get_usage(true);
        
        $this->deviceMemoryTypeCache = [];
        $this->deviceProcessorCache = [];
        $this->agentCache = [];
        $this->agentParamsCache = [];
        
        // Forzar garbage collection
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
        
        $memoryAfter = memory_get_usage(true);
        $memoryFreed = $memoryBefore - $memoryAfter;
        
        Log::channel('glpi_sync')->info("Caches limpiados", [
            'memory_freed_mb' => round($memoryFreed / 1024 / 1024, 2),
            'memory_current_mb' => round($memoryAfter / 1024 / 1024, 2)
        ]);
    }
    
    /**
     * Verifica el uso de memoria y limpia caches si es necesario
     */
    private function checkMemoryUsage()
    {
        $memoryLimit = ini_get('memory_limit');
        $memoryLimitBytes = $this->convertToBytes($memoryLimit);
        $memoryUsage = memory_get_usage(true);
        $memoryPercent = ($memoryUsage / $memoryLimitBytes) * 100;
        
        if ($memoryPercent >= $this->maxMemoryUsagePercent) {
            $this->warn("⚠️  Uso de memoria alto ({$memoryPercent}%), limpiando caches...");
            Log::channel('glpi_sync')->warning("Uso de memoria alto, limpiando caches", [
                'memory_percent' => round($memoryPercent, 2),
                'memory_usage_mb' => round($memoryUsage / 1024 / 1024, 2),
                'memory_limit' => $memoryLimit
            ]);
            $this->clearCaches();
        }
    }
    
    /**
     * Convierte una cadena de memoria a bytes
     */
    private function convertToBytes($value)
    {
        $value = trim($value);
        $last = strtolower($value[strlen($value) - 1]);
        $value = (int) $value;
        
        switch ($last) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }
        
        return $value;
    }

    private function getTotalAssetsCount()
    {
        try {
            // Obtener el total de computadoras en GLPI
            $searchResult = $this->apiCallWithRetry(function() {
                return $this->glpiComputerService->searchComputers([
                    'criteria' => [
                        [
                            'field' => 1, // Nombre
                            'searchtype' => 'contains',
                            'value' => ''
                        ]
                    ],
                    'range' => '0-0' // Solo queremos el total, no los datos
                ]);
            });
            
            return $searchResult['totalcount'] ?? 0;
        } catch (\Exception $e) {
            Log::channel('glpi_sync')->warning("Error obteniendo total de activos", [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    private function handleSingleAssetSync($assetId, $skipCalculations = false)
    {
        try {
            $this->info("🔍 Sincronizando activo específico: {$assetId}");
            
            // Obtener el activo específico de GLPI
            $computer = $this->apiCallWithRetry(function() use ($assetId) {
                return $this->glpiComputerService->getComputer($assetId, [
                    'expand_dropdowns' => true,
                    'with_devices' => true,
                    'with_infocoms' => true
                ]);
            });
            
            if (empty($computer)) {
                $this->error("❌ No se encontró el activo {$assetId} en GLPI");
                return 1;
            }
            
            // Obtener dispositivos adicionales si no están incluidos
            if (!isset($computer['devices']) || empty($computer['devices'])) {
                $computer['devices'] = $this->apiCallWithRetry(function() use ($computer) {
                    return $this->glpiComputerService->getComputerDevices($computer['id']);
                });
            }
            
            $result = $this->syncComputer($computer, true, 0); // Forzar actualización para activo específico
            
            $this->info("✅ Sincronización de activo específico completada:");
            $this->info("   - Resultado: " . ucfirst($result));
            
            // Ejecutar cálculos si no se omitieron y el activo fue creado o actualizado
            if (!$skipCalculations && ($result === 'created' || $result === 'updated')) {
                $this->info("\n🧮 Ejecutando cálculos automáticos...");
                
                try {
                    // Encontrar el activo local por ID de GLPI
                    $activoLocal = MatzobsActivosC::where('id_activo_glpi', $assetId)->first();
                    
                    if ($activoLocal) {
                        $calculationResult = $this->calculatorService->calcularValoresActivo($activoLocal->id);
                        
                        if ($calculationResult) {
                            $this->info("✅ Cálculos completados exitosamente");
                        } else {
                            $this->warn("⚠️  Error en los cálculos automáticos");
                        }
                    }
                } catch (\Exception $e) {
                    $this->warn("⚠️  Error ejecutando cálculos: " . $e->getMessage());
                }
            }
            
            Log::channel('glpi_sync')->info("Sincronización de activo específico completada", [
                'asset_id' => $assetId,
                'result' => $result
            ]);
            
            return 0;
            
        } catch (\Exception $e) {
            $this->error("❌ Error sincronizando activo {$assetId}: " . $e->getMessage());
            Log::channel('glpi_sync')->error("Error sincronizando activo específico", [
                'asset_id' => $assetId,
                'error' => $e->getMessage()
            ]);
            return 1;
        }
    }

    private function testGlpiConnection()
    {
        try {
            $this->info("🔗 Verificando conexión con GLPI...");
            
            // Intentar obtener información de sesión
            $session = $this->apiCallWithRetry(function() {
                return $this->glpiService->getFullSession();
            });
            
            if ($session) {
                $this->info("✅ Conexión con GLPI establecida correctamente");
                Log::channel('glpi_sync')->info('Conexión GLPI exitosa', ['session' => $session]);
                return true;
            }
            
            return false;
            
        } catch (\Exception $e) {
            $this->error("❌ Error conectando con GLPI: " . $e->getMessage());
            Log::channel('glpi_sync')->error('Error conexión GLPI', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    private function processBatch($offset, $batchSize, $force, $syncDays, $progressBar, $skipCalculations = false)
    {
        Log::channel('glpi_sync')->info("Iniciando lote", [
            'offset' => $offset,
            'batch_size' => $batchSize,
            'sync_days' => $syncDays
        ]);

        // Obtener activos de GLPI usando el servicio existente con retry
        $computers = $this->apiCallWithRetry(function() use ($offset, $batchSize) {
            return $this->glpiComputerService->getAllComputers([
                'range' => "{$offset}-" . ($offset + $batchSize - 1),
                'expand_dropdowns' => true,
                'with_devices' => true,
                'with_softwares' => false, // No necesitamos software para matriz de obsolescencia
                'with_connections' => false,
                'with_networkports' => false,
                'with_infocoms' => true // Información financiera para fechas
            ]);
        });

        $stats = [
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'agents_found' => 0,
            'agents_not_found' => 0
        ];

        // Si computers no es array, convertirlo
        if (!is_array($computers)) {
            $computers = [$computers];
        }

        foreach ($computers as $computer) {
            try {
                // Obtener dispositivos adicionales si no están incluidos
                if (!isset($computer['devices']) || empty($computer['devices'])) {
                    $computer['devices'] = $this->apiCallWithRetry(function() use ($computer) {
                        return $this->glpiComputerService->getComputerDevices($computer['id']);
                    });
                }
                
                $result = $this->syncComputer($computer, $force, $syncDays, $stats);
                $stats[$result]++;
                $stats['processed']++;

                $progressBar->advance();

            } catch (\Exception $e) {
                $stats['errors']++;
                $stats['processed']++;
                
                $this->newLine();
                $this->error("❌ Error procesando equipo ID {$computer['id']}: " . $e->getMessage());
                
                Log::channel('glpi_sync')->error("Error procesando equipo", [
                    'computer_id' => $computer['id'],
                    'computer_name' => $computer['name'] ?? 'Sin nombre',
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                
                $progressBar->advance();
            }
        }

        Log::channel('glpi_sync')->info("Lote completado", [
            'offset' => $offset,
            'stats' => $stats
        ]);

        return $stats;
    }

    private function syncComputer($computer, $force, $syncDays = 7, &$stats = null)
    {
        DB::beginTransaction();

        try {
            // Verificar si ya existe
            $existingC = MatzobsActivosC::where('id_activo_glpi', $computer['id'])->first();

            // Extraer datos para tabla C (datos generales)
            $activoC = $this->mapActivoC($computer, $stats);
            
            // Extraer datos para tabla D (detalles técnicos)
            $activoD = $this->mapActivoD($computer);

            if ($existingC) {
                // Si existe, verificar si necesita actualización
                $needsUpdate = $force; // Si force=true, siempre actualizar
                
                if (!$needsUpdate) {
                    // Verificar si los datos han cambiado comparando campos clave
                    $needsUpdate = (
                        $existingC->nombre_equipo !== $activoC['nombre_equipo'] ||
                        $existingC->agente !== $activoC['agente'] ||
                        $existingC->serial !== $activoC['serial'] ||
                        $existingC->ubicacion !== $activoC['ubicacion'] ||
                        // Verificar si han pasado más días de los configurados desde la última sincronización
                        !$existingC->date_u_sincronizacion || 
                        $existingC->date_u_sincronizacion->diffInDays(now()) >= $syncDays
                    );
                }
                
                if (!$needsUpdate) {
                    DB::rollback();
                    Log::channel('glpi_sync')->debug("Activo omitido - sin cambios recientes", [
                        'id_glpi' => $computer['id'],
                        'nombre' => $computer['name'],
                        'ultima_sync' => $existingC->date_u_sincronizacion,
                        'dias_desde_sync' => $existingC->date_u_sincronizacion ? $existingC->date_u_sincronizacion->diffInDays(now()) : 'nunca',
                        'sync_days_config' => $syncDays
                    ]);
                    return 'skipped';
                }
                
                // Actualizar registros existentes
                $existingC->update($activoC);
                
                if ($existingC->detalles) {
                    $existingC->detalles->update($activoD);
                } else {
                    $activoD['activo_c_id'] = $existingC->id;
                    MatzobsActivosD::create($activoD);
                }
                
                Log::channel('glpi_sync')->debug("Activo actualizado", [
                    'id_glpi' => $computer['id'],
                    'nombre' => $computer['name'],
                    'forced' => $force
                ]);
                
                DB::commit();
                return 'updated';
            } else {
                // Crear nuevos registros
                $newActivoC = MatzobsActivosC::create($activoC);
                
                $activoD['activo_c_id'] = $newActivoC->id;
                MatzobsActivosD::create($activoD);
                
                Log::channel('glpi_sync')->debug("Activo creado", [
                    'id_glpi' => $computer['id'],
                    'nombre' => $computer['name'],
                    'id_local' => $newActivoC->id
                ]);
                
                DB::commit();
                return 'created';
            }

        } catch (\Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    private function mapActivoC($computer, &$stats = null)
    {
        // Extraer el TAG del agente
        $agentTag = $this->extractAgentTag($computer);
        
        // Buscar parámetros del agente (empresa, sede, sucursal) en la tabla matzobs_agentes
        $agentParams = $this->getAgentParameters($agentTag, $stats);
        
        // Extraer ubicación y convertir "0" a null
        $ubicacion = $computer['locations_id'] ?? $computer['locations_id_name'] ?? $computer['location'] ?? null;
        if ($ubicacion === '0' || $ubicacion === 0 || empty($ubicacion)) {
            $ubicacion = null;
        }
        
        return [
            'id_activo_glpi' => $computer['id'],
            'nombre_equipo' => $computer['name'] ?? 'Sin nombre',
            'id_empresa' => $agentParams['id_empresa'],
            'id_sede' => $agentParams['id_sede'],
            'id_sucursal' => $agentParams['id_sucursal'],
            'agente' => $agentTag,
            'placa' => $computer['comment'] ?? null,
            'serial' => $computer['serial'] ?? null,
            'ubicacion' => $ubicacion,
            'puntaje' => 0.00, // Valor inicial
            'usuario_modificacion' => 'GLPI_SYNC', // Identificar que fue sincronizado
            'date_u_sincronizacion' => now()
        ];
    }
    
    /**
     * Obtiene los parámetros del agente (empresa, sede, sucursal) desde la tabla matzobs_agentes
     * Si no encuentra el TAG, usa valores por defecto
     */
    private function getAgentParameters($tag, &$stats = null)
    {
        // Verificar cache primero
        if (isset($this->agentParamsCache[$tag])) {
            $cached = $this->agentParamsCache[$tag];
            // Actualizar estadísticas si se proporcionan
            if ($stats !== null && isset($cached['found'])) {
                if ($cached['found']) {
                    $stats['agents_found']++;
                } else {
                    $stats['agents_not_found']++;
                }
            }
            return $cached;
        }
        
        try {
            // Buscar el agente en la tabla matzobs_agentes
            $agente = MatrizObsAgente::where('tag', $tag)->first();
            
            if ($agente) {
                $params = [
                    'id_empresa' => $agente->id_empresa,
                    'id_sede' => $agente->id_sede,
                    'id_sucursal' => $agente->id_sucursal,
                    'found' => true
                ];
                
                // Guardar en cache
                $this->agentParamsCache[$tag] = $params;
                
                // Actualizar estadísticas
                if ($stats !== null) {
                    $stats['agents_found']++;
                }
                
                Log::channel('glpi_sync')->debug("Parámetros de agente encontrados para TAG '{$tag}'", [
                    'tag' => $tag,
                    'id_empresa' => $params['id_empresa'],
                    'id_sede' => $params['id_sede'],
                    'id_sucursal' => $params['id_sucursal']
                ]);
                
                return $params;
            } else {
                // TAG no encontrado en la tabla, usar valores por defecto
                $defaultParams = [
                    'id_empresa' => null,
                    'id_sede' => null,
                    'id_sucursal' => null,
                    'found' => false
                ];
                
                // Guardar en cache para no volver a buscar
                $this->agentParamsCache[$tag] = $defaultParams;
                
                // Actualizar estadísticas
                if ($stats !== null) {
                    $stats['agents_not_found']++;
                }
                
                Log::channel('glpi_sync')->warning("TAG '{$tag}' no encontrado en matzobs_agentes, usando valores por defecto", [
                    'tag' => $tag,
                    'default_empresa' => 1,
                    'default_sede' => 1
                ]);
                
                return $defaultParams;
            }
        } catch (\Exception $e) {
            Log::channel('glpi_sync')->error("Error buscando parámetros de agente para TAG '{$tag}'", [
                'tag' => $tag,
                'error' => $e->getMessage()
            ]);
            
            // Actualizar estadísticas
            if ($stats !== null) {
                $stats['agents_not_found']++;
            }
            
            // En caso de error, retornar valores por defecto
            return [
                'id_empresa' => 1,
                'id_sede' => 1,
                'id_sucursal' => null,
                'found' => false
            ];
        }
    }

    private function mapActivoD($computer)
    {
        return [
            'marca' => $this->extractManufacturer($computer),
            'tipo' => $this->extractType($computer),
            'referencia' => $this->extractModel($computer),
            'tamano_ram' => $this->extractRamSize($computer),
            'generacion_ram' => $this->extractRamGeneration($computer),
            'procesador' => $this->extractProcessor($computer),
            'numero_procesador' => $this->extractProcessorNumber($computer),
            'tipo_disco' => $this->extractDiskType($computer),
            'tamano_disco' => $this->extractDiskSize($computer),
            'interfaz_conexion' => $this->extractDiskInterface($computer)
        ];
    }

    private function extractAgentTag($computer)
    {
        // Verificar cache primero
        $computerId = $computer['id'];
        if (isset($this->agentCache[$computerId])) {
            return $this->agentCache[$computerId];
        }
        
        // Intentar obtener información del agente directamente desde GLPI
        try {
            // Método 1: Obtener agente directamente por ID de computadora (más eficiente)
            $agentEndpoint = "/Computer/{$computerId}/Agent";
            $agentData = $this->apiCallWithRetry(function() use ($agentEndpoint) {
                return $this->glpiService->get($agentEndpoint);
            });
            
            if (!empty($agentData) && is_array($agentData)) {
                $agents = is_array($agentData[0]) ? $agentData : [$agentData];
                
                foreach ($agents as $agent) {
                    if (isset($agent['tag']) && !empty($agent['tag'])) {
                        $tag = $agent['tag'];
                        $this->agentCache[$computerId] = $tag; // Guardar en cache
                        
                        Log::channel('glpi_sync')->debug("Tag del agente encontrado para equipo {$computerId}", [
                            'agent_tag' => $tag,
                            'agent_id' => $agent['id'] ?? null
                        ]);
                        return $tag;
                    }
                }
            }
            
            // Método 2: Buscar en la información extendida del computer
            if (isset($computer['_agents']) && is_array($computer['_agents'])) {
                foreach ($computer['_agents'] as $agent) {
                    if (isset($agent['tag']) && !empty($agent['tag'])) {
                        $tag = $agent['tag'];
                        $this->agentCache[$computerId] = $tag;
                        
                        Log::channel('glpi_sync')->debug("Tag del agente encontrado en _agents para equipo {$computerId}", [
                            'agent_tag' => $tag,
                            'agent_id' => $agent['id'] ?? null
                        ]);
                        return $tag;
                    }
                }
            }
            
        } catch (\Exception $e) {
            Log::channel('glpi_sync')->warning("Error obteniendo agente para equipo {$computerId}", [
                'error' => $e->getMessage()
            ]);
        }
        
        // Fallback: buscar en campos específicos del computer que podrían contener el tag
        // Priorizar otherserial que suele contener información del agente
        if (isset($computer['otherserial']) && 
            !empty($computer['otherserial']) && 
            $computer['otherserial'] !== 'No Asset Information' &&
            $computer['otherserial'] !== '0') {
            $tag = $computer['otherserial'];
            $this->agentCache[$computerId] = $tag;
            
            Log::channel('glpi_sync')->debug("Tag obtenido de otherserial para equipo {$computerId}", [
                'tag' => $tag
            ]);
            return $tag;
        }
        
        // Buscar en contact_num que a veces contiene el tag
        if (isset($computer['contact_num']) && !empty($computer['contact_num'])) {
            $tag = $computer['contact_num'];
            $this->agentCache[$computerId] = $tag;
            
            Log::channel('glpi_sync')->debug("Tag obtenido de contact_num para equipo {$computerId}", [
                'tag' => $tag
            ]);
            return $tag;
        }
        
        // Buscar en comment solo si parece un tag (no una descripción larga)
        if (isset($computer['comment']) && 
            !empty($computer['comment']) && 
            strlen($computer['comment']) < 50 && // Tags suelen ser cortos
            !str_contains(strtolower($computer['comment']), 'descripcion') &&
            !str_contains(strtolower($computer['comment']), 'equipo')) {
            $tag = $computer['comment'];
            $this->agentCache[$computerId] = $tag;
            
            Log::channel('glpi_sync')->debug("Tag obtenido de comment para equipo {$computerId}", [
                'tag' => $tag
            ]);
            return $tag;
        }
        
        // NO usar UUID como fallback ya que no es un tag
        // En su lugar, generar un tag basado en el nombre del equipo si está disponible
        if (isset($computer['name']) && !empty($computer['name'])) {
            $generatedTag = 'AUTO_' . strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $computer['name']));
            $this->agentCache[$computerId] = $generatedTag;
            
            Log::channel('glpi_sync')->debug("Tag generado basado en nombre para equipo {$computerId}", [
                'tag' => $generatedTag,
                'computer_name' => $computer['name']
            ]);
            return $generatedTag;
        }
        
        // Último fallback: generar tag basado en ID
        $fallbackTag = 'GLPI_' . $computerId;
        $this->agentCache[$computerId] = $fallbackTag;
        
        Log::channel('glpi_sync')->debug("Tag fallback generado para equipo {$computerId}", [
            'tag' => $fallbackTag
        ]);
        return $fallbackTag;
    }

    private function extractManufacturer($computer)
    {
        return $computer['manufacturers_id'] ?? $computer['manufacturers_id_name'] ?? null;
    }

    private function extractType($computer)
    {
        $rawType = $computer['computertypes_id'] ?? $computer['computertypes_id_name'] ?? null;
        return $this->mapComputerType($rawType);
    }

    /**
     * Mapea los tipos de equipos de GLPI a los nombres personalizados
     * 
     * @param string|null $glpiType Tipo de equipo desde GLPI
     * @return string|null Tipo mapeado
     */
    private function mapComputerType($glpiType)
    {
        if (empty($glpiType)) {
            return null;
        }

        // Mapeo de tipos de GLPI a nombres personalizados
        $typeMapping = [
            'All in One' => 'All in One',
            'Desktop' => 'Escritorio',
            'Notebook' => 'Laptop',
            'Mini Pc' => 'Tiny',
            'Tower' => 'Workstation'
        ];

        // Buscar coincidencia exacta (case-insensitive)
        foreach ($typeMapping as $glpiName => $customName) {
            if (strcasecmp($glpiType, $glpiName) === 0) {
                Log::channel('glpi_sync')->debug("Tipo de equipo mapeado", [
                    'glpi_type' => $glpiType,
                    'mapped_type' => $customName
                ]);
                return $customName;
            }
        }

        // Si no hay coincidencia, retornar el tipo original
        Log::channel('glpi_sync')->debug("Tipo de equipo sin mapeo, usando original", [
            'glpi_type' => $glpiType
        ]);
        return $glpiType;
    }

    private function extractModel($computer)
    {
        return $computer['computermodels_id'] ?? $computer['computermodels_id_name'] ?? null;
    }

    private function extractRamSize($computer)
    {
        // Buscar en dispositivos de memoria usando el servicio existente
        if (isset($computer['devices']['Item_DeviceMemory'])) {
            $totalRamMiB = 0;
            foreach ($computer['devices']['Item_DeviceMemory'] as $memory) {
                // Extraer capacidad de memoria (puede estar en diferentes campos)
                // El valor viene en MiB (Mebibytes)
                $capacity = $memory['capacity'] ?? $memory['size'] ?? $memory['size_default'] ?? 0;
                $totalRamMiB += (int) $capacity;
            }
            
            if ($totalRamMiB > 0) {
                // Convertir de MiB a GB sin redondear a valores estándar (valor exacto)
                $totalRamGB = (int) round($totalRamMiB / 1000);
                
                Log::channel('glpi_sync')->debug("Conversión RAM MiB a GB (exacto)", [
                    'computer_id' => $computer['id'],
                    'ram_mib' => $totalRamMiB,
                    'ram_gb' => $totalRamGB
                ]);
                
                return $totalRamGB;
            }
            
            return null;
        }
        
        // Fallback: buscar en otros campos posibles
        if (isset($computer['ram'])) {
            $ramMiB = (int) $computer['ram'];
            // Convertir de MiB a GB sin redondear
            $ramGB = (int) round($ramMiB / 1000);
            return $ramGB;
        }
        
        return null;
    }
    
    /**
     * Redondear RAM a valores estándar (4, 8, 16, 32, 64, 128, etc.)
     * NOTA: Este método se mantiene para MaxRAM si es necesario
     */
    private function redondearRamEstandar($ramGB)
    {
        // Valores estándar de RAM en GB
        $valoresEstandar = [2, 4, 8, 12, 16, 24, 32, 48, 64, 96, 128, 192, 256, 384, 512];
        
        // Si la RAM es menor a 2 GB, redondear al entero más cercano
        if ($ramGB < 2) {
            return max(1, (int) round($ramGB));
        }
        
        // Buscar el valor estándar más cercano
        $diferenciaMenor = PHP_INT_MAX;
        $valorMasCercano = $valoresEstandar[0];
        
        foreach ($valoresEstandar as $valorEstandar) {
            $diferencia = abs($ramGB - $valorEstandar);
            if ($diferencia < $diferenciaMenor) {
                $diferenciaMenor = $diferencia;
                $valorMasCercano = $valorEstandar;
            }
        }
        
        return $valorMasCercano;
    }

    private function extractRamGeneration($computer)
    {
        // Método 1: Buscar en devices de memoria (si ya viene en la respuesta)
        if (isset($computer['devices']['Item_DeviceMemory']) && !empty($computer['devices']['Item_DeviceMemory'])) {
            foreach ($computer['devices']['Item_DeviceMemory'] as $memory) {
                // Buscar generation directamente
                if (isset($memory['generation']) && !empty($memory['generation'])) {
                    Log::channel('glpi_sync')->debug("Generación RAM encontrada en devices para equipo {$computer['id']}", [
                        'generation' => $memory['generation']
                    ]);
                    return $memory['generation'];
                }
                
                // Fallback: buscar en devicememorytypes_id_name
                if (isset($memory['devicememorytypes_id_name']) && !empty($memory['devicememorytypes_id_name'])) {
                    Log::channel('glpi_sync')->debug("Generación RAM encontrada en devicememorytypes_id_name para equipo {$computer['id']}", [
                        'generation' => $memory['devicememorytypes_id_name']
                    ]);
                    return $memory['devicememorytypes_id_name'];
                }
            }
        }
        
        // Método 2: Usar llamadas HTTP directas con cache
        try {
            // Obtener Item_DeviceMemory de la computadora
            $memoryItems = $this->apiCallWithRetry(function() use ($computer) {
                return $this->glpiService->get("/Computer/{$computer['id']}/Item_DeviceMemory");
            });
            
            if (!empty($memoryItems) && is_array($memoryItems)) {
                foreach ($memoryItems as $memoryItem) {
                    $devicememories_id = $memoryItem['devicememories_id'] ?? null;
                    
                    if ($devicememories_id) {
                        // Obtener DeviceMemory para conseguir devicememorytypes_id
                        $deviceMemory = $this->apiCallWithRetry(function() use ($devicememories_id) {
                            return $this->glpiService->getItem('DeviceMemory', $devicememories_id);
                        });
                        
                        if (!empty($deviceMemory)) {
                            $devicememorytypes_id = $deviceMemory['devicememorytypes_id'] ?? null;
                            
                            if ($devicememorytypes_id) {
                                // Verificar cache primero
                                if (isset($this->deviceMemoryTypeCache[$devicememorytypes_id])) {
                                    $generation = $this->deviceMemoryTypeCache[$devicememorytypes_id];
                                    Log::channel('glpi_sync')->debug("Generación RAM encontrada en cache para equipo {$computer['id']}", [
                                        'generation' => $generation,
                                        'devicememorytypes_id' => $devicememorytypes_id
                                    ]);
                                    return $generation;
                                }
                                
                                // Obtener DeviceMemoryType para conseguir la generación
                                $memoryType = $this->apiCallWithRetry(function() use ($devicememorytypes_id) {
                                    return $this->glpiService->getItem('DeviceMemoryType', $devicememorytypes_id);
                                });
                                
                                if (!empty($memoryType) && isset($memoryType['name'])) {
                                    $generation = $memoryType['name'];
                                    
                                    // Guardar en cache
                                    $this->deviceMemoryTypeCache[$devicememorytypes_id] = $generation;
                                    
                                    Log::channel('glpi_sync')->debug("Generación RAM encontrada via API para equipo {$computer['id']}", [
                                        'generation' => $generation,
                                        'devicememorytypes_id' => $devicememorytypes_id
                                    ]);
                                    return $generation;
                                }
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            Log::channel('glpi_sync')->warning("Error obteniendo generación RAM via API para equipo {$computer['id']}", [
                'error' => $e->getMessage()
            ]);
        }
        
        Log::channel('glpi_sync')->debug("No se encontró generación RAM para equipo {$computer['id']}");
        return null;
    }

    private function extractProcessor($computer)
    {
        // Método 1: Buscar en devices de procesador (si ya viene en la respuesta)
        if (isset($computer['devices']['Item_DeviceProcessor']) && !empty($computer['devices']['Item_DeviceProcessor'])) {
            foreach ($computer['devices']['Item_DeviceProcessor'] as $processor) {
                // Buscar designation directamente
                if (isset($processor['designation']) && !empty($processor['designation'])) {
                    Log::channel('glpi_sync')->debug("Procesador encontrado en devices para equipo {$computer['id']}", [
                        'designation' => $processor['designation']
                    ]);
                    return $processor['designation'];
                }
                
                // Fallback: buscar en name
                if (isset($processor['name']) && !empty($processor['name'])) {
                    Log::channel('glpi_sync')->debug("Procesador encontrado en name para equipo {$computer['id']}", [
                        'name' => $processor['name']
                    ]);
                    return $processor['name'];
                }
            }
        }
        
        // Método 2: Usar llamadas HTTP directas con cache
        try {
            // Obtener Item_DeviceProcessor de la computadora
            $processorItems = $this->apiCallWithRetry(function() use ($computer) {
                return $this->glpiService->get("/Computer/{$computer['id']}/Item_DeviceProcessor");
            });
            
            if (!empty($processorItems) && is_array($processorItems)) {
                foreach ($processorItems as $processorItem) {
                    $deviceprocessors_id = $processorItem['deviceprocessors_id'] ?? null;
                    
                    if ($deviceprocessors_id) {
                        // Verificar cache primero
                        if (isset($this->deviceProcessorCache[$deviceprocessors_id])) {
                            $designation = $this->deviceProcessorCache[$deviceprocessors_id];
                            Log::channel('glpi_sync')->debug("Procesador encontrado en cache para equipo {$computer['id']}", [
                                'designation' => $designation,
                                'deviceprocessors_id' => $deviceprocessors_id
                            ]);
                            return $designation;
                        }
                        
                        // Obtener DeviceProcessor para conseguir designation
                        $deviceProcessor = $this->apiCallWithRetry(function() use ($deviceprocessors_id) {
                            return $this->glpiService->getItem('DeviceProcessor', $deviceprocessors_id);
                        });
                        
                        if (!empty($deviceProcessor)) {
                            $designation = $deviceProcessor['designation'] ?? null;
                            
                            if ($designation) {
                                // Guardar en cache
                                $this->deviceProcessorCache[$deviceprocessors_id] = $designation;
                                
                                Log::channel('glpi_sync')->debug("Procesador encontrado via API para equipo {$computer['id']}", [
                                    'designation' => $designation,
                                    'deviceprocessors_id' => $deviceprocessors_id
                                ]);
                                return $designation;
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            Log::channel('glpi_sync')->warning("Error obteniendo procesador via API para equipo {$computer['id']}", [
                'error' => $e->getMessage()
            ]);
        }
        
        Log::channel('glpi_sync')->debug("No se encontró información de procesador para equipo {$computer['id']}");
        return null;
    }

    private function extractProcessorNumber($computer)
    {
        // Método 1: Contar procesadores en devices
        if (isset($computer['devices']['Item_DeviceProcessor']) && !empty($computer['devices']['Item_DeviceProcessor'])) {
            $count = count($computer['devices']['Item_DeviceProcessor']);
            Log::channel('glpi_sync')->debug("Número de procesadores encontrado en devices para equipo {$computer['id']}", [
                'processor_count' => $count
            ]);
            return $count;
        }
        
        // Método 2: Intentar obtener información usando el servicio
        try {
            $processorItems = $this->apiCallWithRetry(function() use ($computer) {
                return $this->glpiService->get("/Computer/{$computer['id']}/Item_DeviceProcessor");
            });
            
            if (!empty($processorItems) && is_array($processorItems)) {
                $count = count($processorItems);
                Log::channel('glpi_sync')->debug("Número de procesadores encontrado via API para equipo {$computer['id']}", [
                    'processor_count' => $count
                ]);
                return $count;
            }
        } catch (\Exception $e) {
            Log::channel('glpi_sync')->warning("Error obteniendo número de procesadores via API para equipo {$computer['id']}", [
                'error' => $e->getMessage()
            ]);
        }
        
        // Fallback: asumir 1 procesador si no se encuentra información
        Log::channel('glpi_sync')->debug("No se encontró información de número de procesadores para equipo {$computer['id']}, asumiendo 1");
        return 1;
    }

    private function extractDiskType($computer)
    {
        try {
            // Método 1: Usar el ComputerDetailController para obtener información completa de discos
            $computerDetailController = new \App\Http\Controllers\GLPI\ComputerDetailController($this->glpiService);
            $diskInfoResponse = $computerDetailController->getDiskInfo($computer['id']);
            
            if ($diskInfoResponse->getStatusCode() === 200) {
                $diskData = json_decode($diskInfoResponse->getContent(), true);
                
                if ($diskData['success'] && !empty($diskData['data']['disks'])) {
                    // Determinar el tipo de disco basado en la interfaz y designación
                    $diskTypes = [];
                    foreach ($diskData['data']['disks'] as $disk) {
                        $interface = $disk['interface'] ?? '';
                        $designation = $disk['designation'] ?? '';
                        
                        // Ignorar discos con interfaz SD o USB
                        if (stripos($interface, 'SD') !== false || stripos($interface, 'USB') !== false) {
                            Log::channel('glpi_sync')->debug("Disco ignorado (interfaz SD/USB)", [
                                'computer_id' => $computer['id'],
                                'interface' => $interface,
                                'designation' => $designation
                            ]);
                            continue;
                        }
                        
                        // Determinar tipo basado en interfaz
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
                        // Si hay múltiples tipos, combinarlos
                        $uniqueTypes = array_unique($diskTypes);
                        return implode(', ', $uniqueTypes);
                    }
                }
            }
            
            // Método 2: Fallback - usar el método original si el nuevo falla
            if (isset($computer['devices']['Item_DeviceHardDrive'][0])) {
                $disk = $computer['devices']['Item_DeviceHardDrive'][0];
                $interface = $disk['interfacetypes_id_name'] ?? $disk['interface'] ?? '';
                
                // Determinar tipo basado en interfaz
                if (stripos($interface, 'NVME') !== false) {
                    return 'SSD NVMe';
                } elseif (stripos($interface, 'SATA') !== false) {
                    return 'SATA';
                } else {
                    return $interface ?: 'Desconocido';
                }
            }
            
            return 'Desconocido';
            
        } catch (\Exception $e) {
            Log::channel('glpi_sync')->warning("Error obteniendo tipo de disco para equipo {$computer['id']}", [
                'error' => $e->getMessage(),
                'computer_name' => $computer['name'] ?? 'Sin nombre'
            ]);
            
            // Fallback al método original en caso de error
            if (isset($computer['devices']['Item_DeviceHardDrive'][0])) {
                $disk = $computer['devices']['Item_DeviceHardDrive'][0];
                return $disk['interfacetypes_id_name'] ?? $disk['interface'] ?? 'Desconocido';
            }
            
            return 'Desconocido';
        }
    }

    private function extractDiskSize($computer)
    {
        try {
            // Método 1: Usar el ComputerDetailController para obtener información completa de discos
            $computerDetailController = new \App\Http\Controllers\GLPI\ComputerDetailController($this->glpiService);
            $diskInfoResponse = $computerDetailController->getDiskInfo($computer['id']);
            
            if ($diskInfoResponse->getStatusCode() === 200) {
                $diskData = json_decode($diskInfoResponse->getContent(), true);
                
                if ($diskData['success'] && !empty($diskData['data']['disks'])) {
                    // Calcular capacidad total excluyendo discos SD y USB
                    $totalCapacidadMiB = 0;
                    foreach ($diskData['data']['disks'] as $disk) {
                        $interface = $disk['interface'] ?? '';
                        
                        // Ignorar discos con interfaz SD o USB
                        if (stripos($interface, 'SD') !== false || stripos($interface, 'USB') !== false) {
                            Log::channel('glpi_sync')->debug("Disco ignorado en cálculo de capacidad (interfaz SD/USB)", [
                                'computer_id' => $computer['id'],
                                'interface' => $interface,
                                'capacity' => $disk['capacity'] ?? 0
                            ]);
                            continue;
                        }
                        
                        $totalCapacidadMiB += (int) ($disk['capacity'] ?? 0);
                    }
                    
                    if ($totalCapacidadMiB > 0) {
                        // Convertir de MiB a GB (decimal) sin decimales
                        $capacidadGB = (int) round($totalCapacidadMiB / 1000);
                        
                        Log::channel('glpi_sync')->debug("Conversión disco MiB a GB (excluyendo SD/USB)", [
                            'computer_id' => $computer['id'],
                            'capacidad_mib' => $totalCapacidadMiB,
                            'capacidad_gb' => $capacidadGB
                        ]);
                        
                        return $capacidadGB;
                    }
                }
            }
            
            // Método 2: Fallback - usar el método original si el nuevo falla
            if (isset($computer['devices']['Item_DeviceHardDrive'])) {
                $totalDiskMiB = 0;
                foreach ($computer['devices']['Item_DeviceHardDrive'] as $disk) {
                    $capacity = $disk['capacity'] ?? $disk['capacity_default'] ?? 0;
                    $totalDiskMiB += (int) $capacity;
                }
                
                if ($totalDiskMiB > 0) {
                    // Convertir de MiB a GB (decimal) sin decimales
                    $totalDiskGB = (int) round($totalDiskMiB / 1000);
                    
                    Log::channel('glpi_sync')->debug("Conversión disco MiB a GB (fallback)", [
                        'computer_id' => $computer['id'],
                        'capacidad_mib' => $totalDiskMiB,
                        'capacidad_gb' => $totalDiskGB
                    ]);
                    
                    return $totalDiskGB;
                }
                
                return null;
            }
            
            return null;
            
        } catch (\Exception $e) {
            Log::channel('glpi_sync')->warning("Error obteniendo tamaño de disco para equipo {$computer['id']}", [
                'error' => $e->getMessage(),
                'computer_name' => $computer['name'] ?? 'Sin nombre'
            ]);
            
            // Fallback al método original en caso de error
            if (isset($computer['devices']['Item_DeviceHardDrive'])) {
                $totalDiskMiB = 0;
                foreach ($computer['devices']['Item_DeviceHardDrive'] as $disk) {
                    $capacity = $disk['capacity'] ?? $disk['capacity_default'] ?? 0;
                    $totalDiskMiB += (int) $capacity;
                }
                
                if ($totalDiskMiB > 0) {
                    // Convertir de MiB a GB (decimal) sin decimales
                    $totalDiskGB = (int) round($totalDiskMiB / 1000);
                    return $totalDiskGB;
                }
                
                return null;
            }
            
            return null;
        }
    }

    private function extractDiskInterface($computer)
    {
        try {
            // Método 1: Usar el ComputerDetailController para obtener información completa de discos
            $computerDetailController = new \App\Http\Controllers\GLPI\ComputerDetailController($this->glpiService);
            $diskInfoResponse = $computerDetailController->getDiskInfo($computer['id']);
            
            if ($diskInfoResponse->getStatusCode() === 200) {
                $diskData = json_decode($diskInfoResponse->getContent(), true);
                
                if ($diskData['success'] && !empty($diskData['data']['disks'])) {
                    // Tomar la interfaz del primer disco (o combinar si hay múltiples)
                    $interfaces = [];
                    foreach ($diskData['data']['disks'] as $disk) {
                        $interface = $disk['interface'] ?? '';
                        
                        // Ignorar discos con interfaz SD o USB
                        if (stripos($interface, 'SD') !== false || stripos($interface, 'USB') !== false) {
                            Log::channel('glpi_sync')->debug("Interfaz de disco ignorada (SD/USB)", [
                                'computer_id' => $computer['id'],
                                'interface' => $interface
                            ]);
                            continue;
                        }
                        
                        if (!empty($interface)) {
                            $interfaces[] = $interface;
                        }
                    }
                    
                    if (!empty($interfaces)) {
                        // Si hay múltiples interfaces, combinarlas
                        $uniqueInterfaces = array_unique($interfaces);
                        return implode(', ', $uniqueInterfaces);
                    }
                }
            }
            
            // Método 2: Fallback - usar el método original si el nuevo falla
            if (isset($computer['devices']['Item_DeviceHardDrive'][0])) {
                $disk = $computer['devices']['Item_DeviceHardDrive'][0];
                return $disk['interfacetypes_id_name'] ?? $disk['interface'] ?? null;
            }
            
            // Método 3: Buscar en otros campos posibles
            if (isset($computer['_devices']['Item_DeviceHardDrive'])) {
                foreach ($computer['_devices']['Item_DeviceHardDrive'] as $disk) {
                    if (isset($disk['interfacetypes_id_name'])) {
                        return $disk['interfacetypes_id_name'];
                    }
                    if (isset($disk['interface'])) {
                        return $disk['interface'];
                    }
                }
            }
            
            Log::channel('glpi_sync')->debug("No se pudo obtener interfaz de disco para equipo {$computer['id']}", [
                'computer_name' => $computer['name'] ?? 'Sin nombre',
                'devices_available' => isset($computer['devices']) ? array_keys($computer['devices']) : 'No devices'
            ]);
            
            return null;
            
        } catch (\Exception $e) {
            Log::channel('glpi_sync')->warning("Error obteniendo interfaz de disco para equipo {$computer['id']}", [
                'error' => $e->getMessage(),
                'computer_name' => $computer['name'] ?? 'Sin nombre'
            ]);
            
            // Fallback al método original en caso de error
            if (isset($computer['devices']['Item_DeviceHardDrive'][0])) {
                $disk = $computer['devices']['Item_DeviceHardDrive'][0];
                return $disk['interfacetypes_id_name'] ?? $disk['interface'] ?? null;
            }
            
            return null;
        }
    }

    private function checkDeletedAssets()
    {
        $deletedCount = 0;
        
        try {
            // Obtener todos los activos locales que no están marcados como eliminados
            $localAssets = MatzobsActivosC::whereNotNull('id_activo_glpi')
                ->where('usuario_modificacion', '!=', 'ELIMINADO_GLPI')
                ->pluck('id_activo_glpi')
                ->toArray();
            
            Log::channel('glpi_sync')->info("Verificando activos eliminados", [
                'total_local_assets' => count($localAssets)
            ]);

            // Procesar en chunks más pequeños para evitar problemas con la API
            foreach (array_chunk($localAssets, 10) as $chunkIndex => $chunk) {
                try {
                    $existingIds = [];
                    
                    // Verificar cada ID individualmente para mayor confiabilidad
                    foreach ($chunk as $glpiId) {
                        try {
                            $computer = $this->apiCallWithRetry(function() use ($glpiId) {
                                return $this->glpiComputerService->getComputer($glpiId);
                            });
                            
                            // Si el activo existe y no está en papelera, agregarlo a existingIds
                            if (!empty($computer) && isset($computer['id'])) {
                                // Verificar si está en papelera (is_deleted = 1)
                                $isDeleted = isset($computer['is_deleted']) && $computer['is_deleted'] == 1;
                                
                                if (!$isDeleted) {
                                    $existingIds[] = $glpiId;
                                }
                            }
                        } catch (\Exception $e) {
                            // Si hay error 404, el activo no existe
                            if (strpos($e->getMessage(), '404') !== false || strpos($e->getMessage(), 'not found') !== false) {
                                Log::channel('glpi_sync')->debug("Activo no encontrado en GLPI", [
                                    'glpi_id' => $glpiId
                                ]);
                            } else {
                                // Otros errores, asumir que existe para no marcarlo incorrectamente
                                Log::channel('glpi_sync')->warning("Error verificando activo, asumiendo que existe", [
                                    'glpi_id' => $glpiId,
                                    'error' => $e->getMessage()
                                ]);
                                $existingIds[] = $glpiId;
                            }
                        }
                    }
                    
                    // Identificar IDs que fueron eliminados
                    $deletedIds = array_diff($chunk, $existingIds);
                    
                    if (!empty($deletedIds)) {
                        $updated = MatzobsActivosC::whereIn('id_activo_glpi', $deletedIds)
                            ->update([
                                'usuario_modificacion' => 'ELIMINADO_GLPI',
                                'date_u_sincronizacion' => now()
                            ]);
                        
                        $deletedCount += $updated;
                        
                        Log::channel('glpi_sync')->info("Activos marcados como eliminados", [
                            'deleted_ids' => $deletedIds,
                            'count' => $updated
                        ]);
                    }
                    
                    // Pausa entre chunks
                    if (($chunkIndex + 1) % 5 === 0) {
                        $this->info("⏸️  Verificados " . (($chunkIndex + 1) * 10) . " activos...");
                        sleep(2);
                    }
                    
                } catch (\Exception $e) {
                    Log::channel('glpi_sync')->error("Error verificando chunk de activos eliminados", [
                        'chunk' => $chunk,
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
        } catch (\Exception $e) {
            Log::channel('glpi_sync')->error("Error general verificando activos eliminados", [
                'error' => $e->getMessage()
            ]);
        }
        
        return $deletedCount;
    }

    /**
     * Actualizar progreso en cache para la interfaz web
     */
    protected function updateProgress($current, $total, $stats = [])
    {
        if (!$this->syncId) {
            return;
        }

        $percentage = $total > 0 ? round(($current / $total) * 100, 2) : 0;
        
        cache()->put($this->syncId . '_progress', $percentage, 3600);
        cache()->put($this->syncId . '_current', $current, 3600);
        cache()->put($this->syncId . '_total', $total, 3600);
        
        if (isset($stats['processed'])) {
            cache()->put($this->syncId . '_processed', $stats['processed'], 3600);
        }
        if (isset($stats['created'])) {
            cache()->put($this->syncId . '_created', $stats['created'], 3600);
        }
        if (isset($stats['updated'])) {
            cache()->put($this->syncId . '_updated', $stats['updated'], 3600);
        }
        if (isset($stats['errors'])) {
            cache()->put($this->syncId . '_errors', $stats['errors'], 3600);
        }
        
        // Actualizar mensaje
        $message = "Procesando equipos... ({$current}/{$total})";
        cache()->put($this->syncId . '_message', $message, 3600);
    }

    /**
     * Marcar sincronización como completada
     */
    protected function markSyncCompleted($stats = [])
    {
        if (!$this->syncId) {
            return;
        }

        cache()->put($this->syncId . '_status', 'completed', 3600);
        cache()->put($this->syncId . '_progress', 100, 3600);
        cache()->put($this->syncId . '_message', 'Sincronización completada', 3600);
        cache()->put($this->syncId . '_completed_at', now()->toISOString(), 3600);
        
        if (!empty($stats)) {
            cache()->put($this->syncId . '_final_stats', json_encode($stats), 3600);
        }
    }

    /**
     * Marcar sincronización como error
     */
    protected function markSyncError($errorMessage)
    {
        if (!$this->syncId) {
            return;
        }

        cache()->put($this->syncId . '_status', 'error', 3600);
        cache()->put($this->syncId . '_message', $errorMessage, 3600);
        cache()->put($this->syncId . '_error_at', now()->toISOString(), 3600);
    }

}
