<?php

namespace App\Jobs;

use App\Models\ScheduledTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MantenimientoDbJob extends BaseScheduledJob
{
    /**
     * Timeout en segundos (10 minutos)
     */
    public $timeout = 600;

    /**
     * Ejecutar mantenimiento de base de datos
     */
    protected function execute(ScheduledTask $task)
    {
        $cleanLogs = $this->parameters['clean_logs'] ?? true;
        $optimizeTables = $this->parameters['optimize_tables'] ?? false;

        Log::info("Iniciando mantenimiento de base de datos", [
            'clean_logs' => $cleanLogs,
            'optimize_tables' => $optimizeTables,
        ]);

        $results = [];

        // Limpiar logs antiguos
        if ($cleanLogs) {
            $deleted = $this->cleanOldLogs();
            $results[] = "Logs eliminados: {$deleted}";
        }

        // Optimizar tablas
        if ($optimizeTables) {
            $optimized = $this->optimizeTables();
            $results[] = "Tablas optimizadas: {$optimized}";
        }

        // Limpiar tareas completadas antiguas
        $cleanedTasks = $this->cleanOldTasks();
        $results[] = "Tareas antiguas eliminadas: {$cleanedTasks}";

        Log::info("Mantenimiento de base de datos completado", $results);

        return implode('. ', $results);
    }

    /**
     * Limpiar logs antiguos
     */
    private function cleanOldLogs()
    {
        // Implementar según tu sistema de logs
        return 0;
    }

    /**
     * Optimizar tablas
     */
    private function optimizeTables()
    {
        $tables = ['scheduled_tasks', 'jobs', 'failed_jobs'];
        $count = 0;

        foreach ($tables as $table) {
            try {
                DB::statement("OPTIMIZE TABLE {$table}");
                $count++;
            } catch (\Exception $e) {
                Log::warning("No se pudo optimizar tabla {$table}: " . $e->getMessage());
            }
        }

        return $count;
    }

    /**
     * Limpiar tareas antiguas completadas
     */
    private function cleanOldTasks()
    {
        $keepCompletedDays = config('scheduled-tasks.cleanup.keep_completed_days', 30);
        $keepFailedDays = config('scheduled-tasks.cleanup.keep_failed_days', 90);

        $deletedCompleted = ScheduledTask::where('status', ScheduledTask::STATUS_COMPLETED)
            ->where('completed_at', '<', now()->subDays($keepCompletedDays))
            ->delete();

        $deletedFailed = ScheduledTask::where('status', ScheduledTask::STATUS_FAILED)
            ->where('completed_at', '<', now()->subDays($keepFailedDays))
            ->delete();

        return $deletedCompleted + $deletedFailed;
    }
}
