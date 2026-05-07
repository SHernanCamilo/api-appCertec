<?php

namespace App\Jobs;

use App\Models\ScheduledTask;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Exception;

abstract class BaseScheduledJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * ID de la tarea programada
     */
    public $scheduledTaskId;

    /**
     * Parámetros de la tarea
     */
    public $parameters;

    /**
     * Número de intentos
     */
    public $tries = 3;

    /**
     * Timeout en segundos
     */
    public $timeout = 3600;

    /**
     * Create a new job instance.
     */
    public function __construct($scheduledTaskId, array $parameters = [])
    {
        $this->scheduledTaskId = $scheduledTaskId;
        $this->parameters = $parameters;
        $this->onQueue('scheduled-tasks');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $task = ScheduledTask::find($this->scheduledTaskId);

        if (!$task) {
            Log::error("ScheduledTask not found: {$this->scheduledTaskId}");
            return;
        }

        // Verificar si la tarea fue cancelada
        if ($task->status === ScheduledTask::STATUS_CANCELLED) {
            Log::info("Task {$task->id} was cancelled, skipping execution");
            return;
        }

        try {
            // Marcar como iniciada
            $task->markAsStarted();

            Log::info("Starting scheduled task: {$task->name} (ID: {$task->id})");

            // Ejecutar la lógica específica del job
            $result = $this->execute($task);

            // Marcar como completada
            $task->markAsCompleted($result);

            Log::info("Completed scheduled task: {$task->name} (ID: {$task->id})");

        } catch (Exception $e) {
            Log::error("Failed scheduled task: {$task->name} (ID: {$task->id})", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Marcar como fallida
            $task->markAsFailed($e->getMessage());

            // Re-lanzar la excepción para que Laravel maneje los reintentos
            throw $e;
        }
    }

    /**
     * Método abstracto que debe implementar cada job específico
     */
    abstract protected function execute(ScheduledTask $task);

    /**
     * Handle a job failure.
     */
    public function failed(Exception $exception): void
    {
        $task = ScheduledTask::find($this->scheduledTaskId);

        if ($task) {
            $task->markAsFailed($exception->getMessage());
            
            Log::error("Job permanently failed for task: {$task->name} (ID: {$task->id})", [
                'error' => $exception->getMessage(),
                'attempts' => $task->attempts,
            ]);
        }
    }

    /**
     * Obtener el nombre de la cola
     */
    public function viaQueue(): string
    {
        return config('scheduled-tasks.queue.default', 'scheduled-tasks');
    }
}
