<?php

namespace App\Services;

use App\Models\ScheduledTask;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class TaskSchedulerService
{
    /**
     * Programar una nueva tarea
     */
    public function scheduleTask(
        string $name,
        string $type,
        array $parameters = [],
        ?Carbon $scheduledAt = null,
        ?string $description = null,
        ?int $createdBy = null
    ): ScheduledTask {
        // Validar que el tipo de tarea existe
        $taskConfig = config("scheduled-tasks.types.{$type}");
        
        if (!$taskConfig) {
            throw new \InvalidArgumentException("Tipo de tarea no válido: {$type}");
        }

        // Validar parámetros según configuración
        $this->validateParameters($type, $parameters);

        // Crear la tarea
        $task = ScheduledTask::create([
            'name' => $name,
            'type' => $type,
            'status' => ScheduledTask::STATUS_PENDING,
            'parameters' => $parameters,
            'description' => $description,
            'scheduled_at' => $scheduledAt,
            'max_attempts' => $taskConfig['max_attempts'] ?? 3,
            'created_by' => $createdBy,
        ]);

        // Despachar el job
        $this->dispatchJob($task);

        Log::info("Tarea programada creada", [
            'task_id' => $task->id,
            'type' => $type,
            'scheduled_at' => $scheduledAt,
        ]);

        return $task;
    }

    /**
     * Despachar el job correspondiente
     */
    public function dispatchJob(ScheduledTask $task): void
    {
        $taskConfig = config("scheduled-tasks.types.{$task->type}");
        $jobClass = $taskConfig['job_class'];

        if (!class_exists($jobClass)) {
            throw new \Exception("Clase de job no encontrada: {$jobClass}");
        }

        // Crear instancia del job
        $job = new $jobClass($task->id, $task->parameters ?? []);

        // Si tiene scheduled_at, programar para ese momento
        if ($task->scheduled_at && $task->scheduled_at->isFuture()) {
            $delay = $task->scheduled_at->diffInSeconds(now());
            $job->delay($delay);
        }

        // Despachar el job
        dispatch($job);

        Log::info("Job despachado", [
            'task_id' => $task->id,
            'job_class' => $jobClass,
        ]);
    }

    /**
     * Cancelar una tarea programada
     */
    public function cancelTask(int $taskId): bool
    {
        $task = ScheduledTask::find($taskId);

        if (!$task) {
            throw new \Exception("Tarea no encontrada: {$taskId}");
        }

        if ($task->status === ScheduledTask::STATUS_RUNNING) {
            throw new \Exception("No se puede cancelar una tarea en ejecución");
        }

        if (in_array($task->status, [ScheduledTask::STATUS_COMPLETED, ScheduledTask::STATUS_FAILED])) {
            throw new \Exception("No se puede cancelar una tarea ya finalizada");
        }

        $task->markAsCancelled();

        Log::info("Tarea cancelada", ['task_id' => $taskId]);

        return true;
    }

    /**
     * Ejecutar una tarea inmediatamente
     */
    public function executeNow(int $taskId): void
    {
        $task = ScheduledTask::find($taskId);

        if (!$task) {
            throw new \Exception("Tarea no encontrada: {$taskId}");
        }

        if ($task->status === ScheduledTask::STATUS_RUNNING) {
            throw new \Exception("La tarea ya está en ejecución");
        }

        // Resetear el scheduled_at para ejecución inmediata
        $task->update([
            'scheduled_at' => null,
            'status' => ScheduledTask::STATUS_PENDING,
        ]);

        // Despachar el job
        $this->dispatchJob($task);

        Log::info("Tarea ejecutada inmediatamente", ['task_id' => $taskId]);
    }

    /**
     * Obtener estadísticas del dashboard
     */
    public function getDashboardStats(): array
    {
        return [
            'pending' => ScheduledTask::pending()->count(),
            'running' => ScheduledTask::running()->count(),
            'completed_today' => ScheduledTask::completed()
                ->whereDate('completed_at', today())
                ->count(),
            'failed_today' => ScheduledTask::failed()
                ->whereDate('completed_at', today())
                ->count(),
            'total' => ScheduledTask::count(),
            'by_type' => ScheduledTask::selectRaw('type, count(*) as count')
                ->groupBy('type')
                ->pluck('count', 'type'),
            'recent_failures' => ScheduledTask::failed()
                ->orderBy('completed_at', 'desc')
                ->limit(5)
                ->get(['id', 'name', 'type', 'error_message', 'completed_at']),
        ];
    }

    /**
     * Validar parámetros según configuración
     */
    private function validateParameters(string $type, array $parameters): void
    {
        $taskConfig = config("scheduled-tasks.types.{$type}");
        $requiredParams = $taskConfig['parameters'] ?? [];

        foreach ($requiredParams as $param => $rules) {
            $rulesArray = explode('|', $rules);
            
            // Verificar si es requerido
            if (in_array('required', $rulesArray) && !isset($parameters[$param])) {
                throw new \InvalidArgumentException("Parámetro requerido faltante: {$param}");
            }

            // Validar tipo si está presente
            if (isset($parameters[$param])) {
                $this->validateParameterType($param, $parameters[$param], $rulesArray);
            }
        }
    }

    /**
     * Validar tipo de parámetro
     */
    private function validateParameterType(string $param, $value, array $rules): void
    {
        foreach ($rules as $rule) {
            if ($rule === 'integer' && !is_int($value)) {
                throw new \InvalidArgumentException("El parámetro {$param} debe ser un entero");
            }
            if ($rule === 'boolean' && !is_bool($value)) {
                throw new \InvalidArgumentException("El parámetro {$param} debe ser un booleano");
            }
            if ($rule === 'array' && !is_array($value)) {
                throw new \InvalidArgumentException("El parámetro {$param} debe ser un array");
            }
            if ($rule === 'string' && !is_string($value)) {
                throw new \InvalidArgumentException("El parámetro {$param} debe ser una cadena");
            }
        }
    }

    /**
     * Reintentar una tarea fallida
     */
    public function retryTask(int $taskId): void
    {
        $task = ScheduledTask::find($taskId);

        if (!$task) {
            throw new \Exception("Tarea no encontrada: {$taskId}");
        }

        if ($task->status !== ScheduledTask::STATUS_FAILED) {
            throw new \Exception("Solo se pueden reintentar tareas fallidas");
        }

        if (!$task->canRetry()) {
            throw new \Exception("La tarea ha alcanzado el máximo de intentos");
        }

        // Resetear estado
        $task->update([
            'status' => ScheduledTask::STATUS_PENDING,
            'error_message' => null,
        ]);

        // Despachar nuevamente
        $this->dispatchJob($task);

        Log::info("Tarea reintentada", ['task_id' => $taskId]);
    }
}
