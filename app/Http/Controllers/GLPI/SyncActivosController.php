<?php

namespace App\Http\Controllers\GLPI;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use App\Models\MatrizObsolescencia\MatzobsActivosC;
use Exception;

class SyncActivosController extends Controller
{
    /**
     * Forzar sincronización completa de todos los activos (asíncrono con progreso)
     */
    public function forceSyncAll(Request $request): JsonResponse
    {
        try {
            Log::channel('glpi_sync')->info('Iniciando sincronización forzada desde interfaz web', [
                'user' => auth()->user()->id ?? 'guest',
                'ip' => $request->ip()
            ]);

            // Crear un identificador único para esta sincronización
            $syncId = 'sync_' . time() . '_' . uniqid();
            
            // Inicializar progreso en cache
            cache()->put($syncId . '_status', 'running', 3600);
            cache()->put($syncId . '_started_at', now()->toISOString(), 3600);
            cache()->put($syncId . '_progress', 0, 3600);
            cache()->put($syncId . '_message', 'Iniciando sincronización...', 3600);
            cache()->put($syncId . '_current', 0, 3600);
            cache()->put($syncId . '_total', 0, 3600);
            cache()->put($syncId . '_processed', 0, 3600);
            cache()->put($syncId . '_created', 0, 3600);
            cache()->put($syncId . '_updated', 0, 3600);
            cache()->put($syncId . '_errors', 0, 3600);

            // Ejecutar comando de forma asíncrona usando exec en segundo plano
            $command = sprintf(
                'php %s glpi:sync-activos --batch=10 --force --sync-id=%s > /dev/null 2>&1 &',
                base_path('artisan'),
                $syncId
            );
            
            // En Windows, usar start /B
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $command = sprintf(
                    'start /B php %s glpi:sync-activos --batch=10 --force --sync-id=%s',
                    base_path('artisan'),
                    $syncId
                );
            }
            
            Log::channel('glpi_sync')->info('Ejecutando comando', ['command' => $command]);
            
            exec($command, $output, $returnCode);
            
            Log::channel('glpi_sync')->info('Comando ejecutado', [
                'return_code' => $returnCode,
                'output' => $output
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Sincronización iniciada correctamente',
                'data' => [
                    'sync_id' => $syncId,
                    'type' => 'full_sync',
                    'started_at' => now()->toISOString(),
                    'status' => 'running',
                    'progress' => 0
                ]
            ]);

        } catch (Exception $e) {
            Log::channel('glpi_sync')->error('Error en sincronización forzada desde web', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error interno al ejecutar la sincronización',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cancelar sincronización en curso
     */
    public function cancelSync(Request $request): JsonResponse
    {
        $request->validate([
            'sync_id' => 'required|string'
        ]);

        try {
            $syncId = $request->input('sync_id');
            
            // Obtener el PID del proceso
            $pid = cache()->get($syncId . '_pid');
            
            if ($pid) {
                // Intentar matar el proceso en Windows
                if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                    exec("taskkill /F /PID {$pid} 2>&1", $output, $returnCode);
                } else {
                    // En Linux/Unix
                    exec("kill -9 {$pid} 2>&1", $output, $returnCode);
                }
                
                Log::channel('glpi_sync')->info('Proceso terminado', [
                    'pid' => $pid,
                    'output' => $output,
                    'return_code' => $returnCode
                ]);
            }
            
            // Marcar la sincronización como cancelada
            cache()->put($syncId . '_status', 'cancelled', 3600);
            cache()->put($syncId . '_cancelled_at', now()->toISOString(), 3600);
            cache()->put($syncId . '_message', 'Sincronización cancelada por el usuario', 3600);

            Log::channel('glpi_sync')->warning('Sincronización cancelada por usuario', [
                'sync_id' => $syncId,
                'user' => auth()->user()->id ?? 'guest',
                'ip' => $request->ip()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Sincronización cancelada correctamente',
                'data' => [
                    'sync_id' => $syncId,
                    'status' => 'cancelled',
                    'cancelled_at' => now()->toISOString()
                ]
            ]);

        } catch (Exception $e) {
            Log::channel('glpi_sync')->error('Error al cancelar sincronización', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al cancelar la sincronización',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener estado de una sincronización específica con progreso
     */
    public function getSyncStatus(Request $request): JsonResponse
    {
        $request->validate([
            'sync_id' => 'required|string'
        ]);

        try {
            $syncId = $request->input('sync_id');
            
            $status = cache()->get($syncId . '_status', 'unknown');
            $startedAt = cache()->get($syncId . '_started_at');
            $cancelledAt = cache()->get($syncId . '_cancelled_at');
            $progress = cache()->get($syncId . '_progress', 0);
            $message = cache()->get($syncId . '_message', 'Procesando...');
            $current = cache()->get($syncId . '_current', 0);
            $total = cache()->get($syncId . '_total', 0);
            $processed = cache()->get($syncId . '_processed', 0);
            $created = cache()->get($syncId . '_created', 0);
            $updated = cache()->get($syncId . '_updated', 0);
            $errors = cache()->get($syncId . '_errors', 0);

            return response()->json([
                'success' => true,
                'data' => [
                    'sync_id' => $syncId,
                    'status' => $status,
                    'started_at' => $startedAt,
                    'cancelled_at' => $cancelledAt,
                    'progress' => [
                        'percentage' => $progress,
                        'current' => $current,
                        'total' => $total,
                        'processed' => $processed,
                        'created' => $created,
                        'updated' => $updated,
                        'errors' => $errors,
                        'message' => $message
                    ]
                ]
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener estado de sincronización',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Sincronizar un activo específico
     */
    public function syncSingleAsset(Request $request): JsonResponse
    {
        $request->validate([
            'asset_id' => 'required|integer|min:1'
        ]);

        try {
            $assetId = $request->input('asset_id');

            Log::channel('glpi_sync')->info('Iniciando sincronización de activo específico desde interfaz web', [
                'asset_id' => $assetId,
                'user' => auth()->user()->id ?? 'guest',
                'ip' => $request->ip()
            ]);

            // Ejecutar comando para activo específico
            $exitCode = Artisan::call('glpi:sync-activos', [
                '--single-asset' => $assetId
            ]);

            if ($exitCode === 0) {
                // Obtener el activo actualizado
                $activo = MatzobsActivosC::with('detalles')
                    ->where('id_activo_glpi', $assetId)
                    ->first();

                return response()->json([
                    'success' => true,
                    'message' => "Activo {$assetId} sincronizado correctamente",
                    'data' => [
                        'type' => 'single_asset',
                        'asset_id' => $assetId,
                        'asset_data' => $activo,
                        'updated_at' => now()->toISOString()
                    ]
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => "Error al sincronizar el activo {$assetId}",
                    'error' => 'Exit code: ' . $exitCode
                ], 500);
            }

        } catch (Exception $e) {
            Log::channel('glpi_sync')->error('Error en sincronización de activo específico desde web', [
                'asset_id' => $request->input('asset_id'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error interno al sincronizar el activo',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Sincronización automática por días (para cron jobs)
     */
    public function autoSync(Request $request): JsonResponse
    {
        $request->validate([
            'sync_days' => 'integer|min:1|max:365'
        ]);

        try {
            $syncDays = $request->input('sync_days', 7); // Por defecto 7 días

            Log::channel('glpi_sync')->info('Iniciando sincronización automática desde interfaz web', [
                'sync_days' => $syncDays,
                'user' => auth()->user()->id ?? 'guest',
                'ip' => $request->ip()
            ]);

            // Ejecutar comando de sincronización automática
            $exitCode = Artisan::call('glpi:sync-activos', [
                '--sync-days' => $syncDays,
                '--check-deleted' => true,
                '--batch' => 15
            ]);

            if ($exitCode === 0) {
                return response()->json([
                    'success' => true,
                    'message' => "Sincronización automática completada (últimos {$syncDays} días)",
                    'data' => [
                        'type' => 'auto_sync',
                        'sync_days' => $syncDays,
                        'completed_at' => now()->toISOString()
                    ]
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Error en la sincronización automática',
                    'error' => 'Exit code: ' . $exitCode
                ], 500);
            }

        } catch (Exception $e) {
            Log::channel('glpi_sync')->error('Error en sincronización automática desde web', [
                'sync_days' => $request->input('sync_days'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error interno en la sincronización automática',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener estadísticas de sincronización
     */
    public function getSyncStats(): JsonResponse
    {
        try {
            $stats = [
                'total_assets' => MatzobsActivosC::count(),
                'synced_today' => MatzobsActivosC::whereDate('date_u_sincronizacion', today())->count(),
                'synced_this_week' => MatzobsActivosC::where('date_u_sincronizacion', '>=', now()->subWeek())->count(),
                'never_synced' => MatzobsActivosC::whereNull('date_u_sincronizacion')->count(),
                'deleted_assets' => MatzobsActivosC::where('usuario_modificacion', 'ELIMINADO_GLPI')->count(),
                'last_sync' => MatzobsActivosC::orderBy('date_u_sincronizacion', 'desc')->first()?->date_u_sincronizacion,
                'assets_by_agent' => MatzobsActivosC::selectRaw('agente, COUNT(*) as count')
                    ->groupBy('agente')
                    ->orderBy('count', 'desc')
                    ->limit(10)
                    ->get()
            ];

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);

        } catch (Exception $e) {
            Log::error('Error obteniendo estadísticas de sincronización', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener estadísticas',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener el estado de la última sincronización
     */
    public function getLastSyncStatus(): JsonResponse
    {
        try {
            // Leer las últimas líneas del log para obtener el estado
            $logPath = storage_path('logs/ActivosGLPI.log');
            
            if (!file_exists($logPath)) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'status' => 'no_logs',
                        'message' => 'No se encontraron logs de sincronización'
                    ]
                ]);
            }

            $lastLines = $this->getLastLinesFromFile($logPath, 20);
            $lastSyncInfo = $this->parseLastSyncFromLogs($lastLines);

            return response()->json([
                'success' => true,
                'data' => $lastSyncInfo
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener estado de sincronización',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function getLastLinesFromFile($file, $lines = 20)
    {
        $handle = fopen($file, 'r');
        $linecounter = $lines;
        $pos = -2;
        $beginning = false;
        $text = [];

        while ($linecounter > 0) {
            $t = ' ';
            while ($t != "\n") {
                if (fseek($handle, $pos, SEEK_END) == -1) {
                    $beginning = true;
                    break;
                }
                $t = fgetc($handle);
                $pos--;
            }
            $linecounter--;
            if ($beginning) {
                rewind($handle);
            }
            $text[$lines - $linecounter - 1] = fgets($handle);
            if ($beginning) break;
        }
        fclose($handle);
        return array_reverse($text);
    }

    private function parseLastSyncFromLogs($lines)
    {
        $lastSync = [
            'status' => 'unknown',
            'start_time' => null,
            'end_time' => null,
            'stats' => null,
            'duration' => null
        ];

        foreach ($lines as $line) {
            if (strpos($line, 'INICIO SINCRONIZACIÓN GLPI') !== false) {
                preg_match('/\[(.*?)\]/', $line, $matches);
                $lastSync['start_time'] = $matches[1] ?? null;
                $lastSync['status'] = 'running';
            }
            
            if (strpos($line, 'FIN SINCRONIZACIÓN GLPI') !== false) {
                preg_match('/\[(.*?)\]/', $line, $matches);
                $lastSync['end_time'] = $matches[1] ?? null;
                $lastSync['status'] = 'completed';
                
                // Extraer estadísticas del JSON
                if (preg_match('/\{.*\}/', $line, $jsonMatch)) {
                    $data = json_decode($jsonMatch[0], true);
                    $lastSync['stats'] = $data['stats'] ?? null;
                    $lastSync['duration'] = $data['duration_seconds'] ?? null;
                }
            }
        }

        return $lastSync;
    }
}