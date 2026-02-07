<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class StopSyncActivos extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'glpi:stop-sync 
                           {--all : Detener todos los procesos de sincronización}
                           {--sync-id= : ID específico de sincronización a detener}';

    /**
     * The console command description.
     */
    protected $description = 'Detiene procesos de sincronización de GLPI en ejecución';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $all = $this->option('all');
        $syncId = $this->option('sync-id');

        $this->info("🛑 Deteniendo procesos de sincronización GLPI...");
        $this->newLine();

        if ($syncId) {
            return $this->stopSpecificSync($syncId);
        }

        if ($all) {
            return $this->stopAllSyncs();
        }

        // Si no se especifica nada, mostrar procesos activos y preguntar
        return $this->interactiveStop();
    }

    /**
     * Detener una sincronización específica por ID
     */
    private function stopSpecificSync($syncId)
    {
        $this->info("🔍 Buscando sincronización con ID: {$syncId}");

        // Obtener PID del cache
        $pid = cache()->get($syncId . '_pid');
        $status = cache()->get($syncId . '_status');

        if (!$pid) {
            $this->warn("⚠️  No se encontró PID para la sincronización {$syncId}");
            $this->info("Estado en cache: " . ($status ?? 'No encontrado'));
            
            // Intentar buscar por proceso
            return $this->findAndKillByCommand();
        }

        $this->info("📌 PID encontrado: {$pid}");
        $this->info("📊 Estado actual: {$status}");

        if ($this->killProcess($pid)) {
            // Actualizar cache
            cache()->put($syncId . '_status', 'cancelled', 3600);
            cache()->put($syncId . '_message', 'Sincronización detenida manualmente', 3600);
            cache()->put($syncId . '_cancelled_at', now()->toISOString(), 3600);

            $this->info("✅ Proceso detenido exitosamente");
            
            Log::channel('glpi_sync')->warning('Sincronización detenida manualmente', [
                'sync_id' => $syncId,
                'pid' => $pid,
                'stopped_at' => now()->toISOString()
            ]);

            return 0;
        }

        $this->error("❌ No se pudo detener el proceso");
        return 1;
    }

    /**
     * Detener todas las sincronizaciones activas
     */
    private function stopAllSyncs()
    {
        $this->info("🔍 Buscando todos los procesos de sincronización...");
        $this->newLine();

        $killed = 0;

        // Buscar por comando
        if ($this->findAndKillByCommand()) {
            $killed++;
        }

        // Buscar en cache
        $cacheKeys = $this->findActiveSyncsInCache();
        
        if (!empty($cacheKeys)) {
            $this->info("📋 Sincronizaciones activas en cache: " . count($cacheKeys));
            
            foreach ($cacheKeys as $syncId) {
                $pid = cache()->get($syncId . '_pid');
                
                if ($pid && $this->killProcess($pid)) {
                    cache()->put($syncId . '_status', 'cancelled', 3600);
                    cache()->put($syncId . '_message', 'Sincronización detenida manualmente', 3600);
                    $killed++;
                    
                    $this->info("  ✅ Detenido: {$syncId} (PID: {$pid})");
                }
            }
        }

        $this->newLine();
        
        if ($killed > 0) {
            $this->info("✅ Se detuvieron {$killed} proceso(s)");
            return 0;
        } else {
            $this->warn("⚠️  No se encontraron procesos activos");
            return 0;
        }
    }

    /**
     * Modo interactivo para detener procesos
     */
    private function interactiveStop()
    {
        // Buscar procesos activos
        $processes = $this->findActiveProcesses();
        $cacheKeys = $this->findActiveSyncsInCache();

        if (empty($processes) && empty($cacheKeys)) {
            $this->info("✅ No hay procesos de sincronización activos");
            return 0;
        }

        $this->warn("⚠️  Se encontraron procesos activos:");
        $this->newLine();

        // Mostrar procesos del sistema
        if (!empty($processes)) {
            $this->info("📋 Procesos del sistema:");
            foreach ($processes as $index => $process) {
                $this->line("  [{$index}] PID: {$process['pid']} - {$process['command']}");
            }
            $this->newLine();
        }

        // Mostrar sincronizaciones en cache
        if (!empty($cacheKeys)) {
            $this->info("📋 Sincronizaciones en cache:");
            foreach ($cacheKeys as $syncId) {
                $pid = cache()->get($syncId . '_pid');
                $status = cache()->get($syncId . '_status');
                $progress = cache()->get($syncId . '_progress', 0);
                
                $this->line("  - {$syncId}");
                $this->line("    PID: {$pid}, Estado: {$status}, Progreso: {$progress}%");
            }
            $this->newLine();
        }

        if ($this->confirm('¿Deseas detener TODOS los procesos?', true)) {
            return $this->stopAllSyncs();
        }

        $this->info("Operación cancelada");
        return 0;
    }

    /**
     * Buscar y matar procesos por comando
     */
    private function findAndKillByCommand()
    {
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $killed = false;

        if ($isWindows) {
            // Windows: buscar procesos PHP que ejecutan glpi:sync-activos
            exec('wmic process where "name=\'php.exe\'" get ProcessId,CommandLine /format:csv', $output);
            
            foreach ($output as $line) {
                if (stripos($line, 'glpi:sync-activos') !== false) {
                    // Extraer PID
                    $parts = explode(',', $line);
                    if (count($parts) >= 3) {
                        $pid = trim($parts[2]);
                        if (is_numeric($pid)) {
                            $this->info("🔍 Encontrado proceso con PID: {$pid}");
                            if ($this->killProcess($pid)) {
                                $killed = true;
                            }
                        }
                    }
                }
            }
        } else {
            // Linux/Unix: usar ps y grep
            exec("ps aux | grep 'glpi:sync-activos' | grep -v grep | awk '{print $2}'", $pids);
            
            foreach ($pids as $pid) {
                $pid = trim($pid);
                if (is_numeric($pid)) {
                    $this->info("🔍 Encontrado proceso con PID: {$pid}");
                    if ($this->killProcess($pid)) {
                        $killed = true;
                    }
                }
            }
        }

        return $killed;
    }

    /**
     * Matar un proceso por PID
     */
    private function killProcess($pid)
    {
        if (!is_numeric($pid)) {
            return false;
        }

        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

        if ($isWindows) {
            exec("taskkill /F /PID {$pid} 2>&1", $output, $returnCode);
        } else {
            exec("kill -9 {$pid} 2>&1", $output, $returnCode);
        }

        return $returnCode === 0;
    }

    /**
     * Encontrar procesos activos
     */
    private function findActiveProcesses()
    {
        $processes = [];
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

        if ($isWindows) {
            exec('wmic process where "name=\'php.exe\'" get ProcessId,CommandLine /format:csv', $output);
            
            foreach ($output as $line) {
                if (stripos($line, 'glpi:sync-activos') !== false) {
                    $parts = explode(',', $line);
                    if (count($parts) >= 3) {
                        $pid = trim($parts[2]);
                        $command = trim($parts[1]);
                        
                        if (is_numeric($pid)) {
                            $processes[] = [
                                'pid' => $pid,
                                'command' => $command
                            ];
                        }
                    }
                }
            }
        } else {
            exec("ps aux | grep 'glpi:sync-activos' | grep -v grep", $lines);
            
            foreach ($lines as $line) {
                if (preg_match('/\s+(\d+)\s+/', $line, $matches)) {
                    $processes[] = [
                        'pid' => $matches[1],
                        'command' => trim($line)
                    ];
                }
            }
        }

        return $processes;
    }

    /**
     * Buscar sincronizaciones activas en cache
     */
    private function findActiveSyncsInCache()
    {
        $activeSyncs = [];
        
        // Buscar claves de cache que empiecen con 'sync_' y tengan status 'running'
        // Nota: Esto depende del driver de cache. Para file/redis funciona diferente.
        
        // Método alternativo: buscar en logs recientes
        $logPath = storage_path('logs/ActivosGLPI.log');
        
        if (file_exists($logPath)) {
            $lines = file($logPath);
            $recentLines = array_slice($lines, -100); // Últimas 100 líneas
            
            foreach ($recentLines as $line) {
                if (preg_match('/sync_id.*?(sync_\d+_[a-z0-9]+)/', $line, $matches)) {
                    $syncId = $matches[1];
                    $status = cache()->get($syncId . '_status');
                    
                    if ($status === 'running') {
                        $activeSyncs[] = $syncId;
                    }
                }
            }
        }

        return array_unique($activeSyncs);
    }
}
