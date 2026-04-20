<?php

namespace App\Console\Commands;

use App\Models\ScheduledTask;
use App\Services\TaskSchedulerService;
use Illuminate\Console\Command;
use Carbon\Carbon;

class ManageScheduledTasks extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tasks:manage 
                            {action : Acción a realizar (list, create, cancel, execute, retry, stats, cleanup)}
                            {--type= : Tipo de tarea}
                            {--name= : Nombre de la tarea}
                            {--id= : ID de la tarea}
                            {--schedule= : Fecha/hora de programación (Y-m-d H:i:s)}
                            {--params= : Parámetros en formato JSON}
                            {--status= : Filtrar por estado}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Gestionar tareas programadas del sistema';

    protected TaskSchedulerService $service;

    public function __construct(TaskSchedulerService $service)
    {
        parent::__construct();
        $this->service = $service;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $action = $this->argument('action');

        return match($action) {
            'list' => $this->listTasks(),
            'create' => $this->createTask(),
            'cancel' => $this->cancelTask(),
            'execute' => $this->executeTask(),
            'retry' => $this->retryTask(),
            'stats' => $this->showStats(),
            'cleanup' => $this->cleanupTasks(),
            default => $this->error("Acción no válida: {$action}")
        };
    }

    /**
     * Listar tareas
     */
    private function listTasks()
    {
        $query = ScheduledTask::query();

        if ($status = $this->option('status')) {
            $query->where('status', $status);
        }

        if ($type = $this->option('type')) {
            $query->where('type', $type);
        }

        $tasks = $query->orderBy('created_at', 'desc')->limit(50)->get();

        if ($tasks->isEmpty()) {
            $this->info('No hay tareas programadas');
            return 0;
        }

        $this->table(
            ['ID', 'Nombre', 'Tipo', 'Estado', 'Programada', 'Intentos'],
            $tasks->map(fn($task) => [
                $task->id,
                $task->name,
                $task->type,
                $task->status,
                $task->scheduled_at?->format('Y-m-d H:i:s') ?? 'Inmediata',
                "{$task->attempts}/{$task->max_attempts}",
            ])
        );

        return 0;
    }

    /**
     * Crear nueva tarea
     */
    private function createTask()
    {
        $type = $this->option('type');
        $name = $this->option('name');

        if (!$type || !$name) {
            $this->error('Se requieren las opciones --type y --name');
            return 1;
        }

        $scheduledAt = null;
        if ($schedule = $this->option('schedule')) {
            $scheduledAt = Carbon::parse($schedule);
        }

        $parameters = [];
        if ($params = $this->option('params')) {
            $parameters = json_decode($params, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->error('Parámetros JSON inválidos');
                return 1;
            }
        }

        try {
            $task = $this->service->scheduleTask(
                $name,
                $type,
                $parameters,
                $scheduledAt
            );

            $this->info("Tarea creada exitosamente (ID: {$task->id})");
            return 0;
        } catch (\Exception $e) {
            $this->error("Error al crear tarea: {$e->getMessage()}");
            return 1;
        }
    }

    /**
     * Cancelar tarea
     */
    private function cancelTask()
    {
        $id = $this->option('id');

        if (!$id) {
            $this->error('Se requiere la opción --id');
            return 1;
        }

        try {
            $this->service->cancelTask($id);
            $this->info("Tarea {$id} cancelada exitosamente");
            return 0;
        } catch (\Exception $e) {
            $this->error("Error al cancelar tarea: {$e->getMessage()}");
            return 1;
        }
    }

    /**
     * Ejecutar tarea inmediatamente
     */
    private function executeTask()
    {
        $id = $this->option('id');

        if (!$id) {
            $this->error('Se requiere la opción --id');
            return 1;
        }

        try {
            $this->service->executeNow($id);
            $this->info("Tarea {$id} ejecutada");
            return 0;
        } catch (\Exception $e) {
            $this->error("Error al ejecutar tarea: {$e->getMessage()}");
            return 1;
        }
    }

    /**
     * Reintentar tarea fallida
     */
    private function retryTask()
    {
        $id = $this->option('id');

        if (!$id) {
            $this->error('Se requiere la opción --id');
            return 1;
        }

        try {
            $this->service->retryTask($id);
            $this->info("Tarea {$id} reintentada");
            return 0;
        } catch (\Exception $e) {
            $this->error("Error al reintentar tarea: {$e->getMessage()}");
            return 1;
        }
    }

    /**
     * Mostrar estadísticas
     */
    private function showStats()
    {
        $stats = $this->service->getDashboardStats();

        $this->info('=== Estadísticas de Tareas Programadas ===');
        $this->line('');
        $this->line("Pendientes: {$stats['pending']}");
        $this->line("En ejecución: {$stats['running']}");
        $this->line("Completadas hoy: {$stats['completed_today']}");
        $this->line("Fallidas hoy: {$stats['failed_today']}");
        $this->line("Total: {$stats['total']}");
        $this->line('');

        if (!empty($stats['by_type'])) {
            $this->info('Por tipo:');
            foreach ($stats['by_type'] as $type => $count) {
                $this->line("  {$type}: {$count}");
            }
        }

        return 0;
    }

    /**
     * Limpiar tareas antiguas
     */
    private function cleanupTasks()
    {
        $keepCompletedDays = config('scheduled-tasks.cleanup.keep_completed_days', 30);
        $keepFailedDays = config('scheduled-tasks.cleanup.keep_failed_days', 90);

        $deletedCompleted = ScheduledTask::where('status', ScheduledTask::STATUS_COMPLETED)
            ->where('completed_at', '<', now()->subDays($keepCompletedDays))
            ->delete();

        $deletedFailed = ScheduledTask::where('status', ScheduledTask::STATUS_FAILED)
            ->where('completed_at', '<', now()->subDays($keepFailedDays))
            ->delete();

        $total = $deletedCompleted + $deletedFailed;

        $this->info("Limpieza completada: {$total} tareas eliminadas");
        $this->line("  Completadas: {$deletedCompleted}");
        $this->line("  Fallidas: {$deletedFailed}");

        return 0;
    }
}

