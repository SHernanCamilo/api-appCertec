<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\ScheduledTask;
use App\Services\TaskSchedulerService;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class RunRecurringTasks extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tasks:run-recurring 
                            {--dry-run : Simular ejecución sin ejecutar tareas}
                            {--force : Forzar ejecución de todas las tareas activas}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Ejecuta tareas recurrentes programadas que están listas para ejecutarse';

    protected TaskSchedulerService $taskScheduler;

    public function __construct(TaskSchedulerService $taskScheduler)
    {
        parent::__construct();
        $this->taskScheduler = $taskScheduler;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');

        $this->info('🔄 Verificando tareas recurrentes...');

        // Obtener tareas recurrentes listas para ejecutarse
        $query = ScheduledTask::recurringActive();

        if ($force) {
            $tasks = $query->get();
            $this->warn('⚠️  Modo forzado: ejecutando todas las tareas activas');
        } else {
            $tasks = $query->readyToExecuteRecurring()->get();
        }

        if ($tasks->isEmpty()) {
            $this->info('✅ No hay tareas recurrentes para ejecutar en este momento');
            return 0;
        }

        $this->info("📋 Encontradas {$tasks->count()} tarea(s) para ejecutar");

        $executed = 0;
        $failed = 0;

        foreach ($tasks as $task) {
            try {
                $nextRun = $task->next_run_at ? $task->next_run_at->format('Y-m-d H:i:s') : 'Ahora';
                $this->line("⏰ Tarea: {$task->name} (ID: {$task->id}) - Próxima ejecución: {$nextRun}");

                if ($dryRun) {
                    $this->info("   [DRY RUN] Se ejecutaría ahora");
                    continue;
                }

                // Verificar si ya hay una instancia en ejecución
                $runningInstance = ScheduledTask::where('type', $task->type)
                    ->where('status', ScheduledTask::STATUS_RUNNING)
                    ->where('id', '!=', $task->id)
                    ->exists();

                if ($runningInstance) {
                    $this->warn("   ⏭️  Omitida: Ya hay una instancia en ejecución");
                    Log::channel('glpi_sync')->warning("Tarea recurrente omitida por instancia en ejecución", [
                        'task_id' => $task->id,
                        'task_name' => $task->name,
                        'task_type' => $task->type
                    ]);
                    continue;
                }

                // Ejecutar la tarea
                $this->info("   ▶️  Ejecutando...");
                
                $this->taskScheduler->executeRecurringTask($task);
                
                $executed++;
                $this->info("   ✅ Ejecutada exitosamente");

                Log::channel('glpi_sync')->info("Tarea recurrente ejecutada", [
                    'task_id' => $task->id,
                    'task_name' => $task->name,
                    'task_type' => $task->type,
                    'next_run' => $task->fresh()->next_run_at
                ]);

            } catch (\Exception $e) {
                $failed++;
                $this->error("   ❌ Error: {$e->getMessage()}");
                
                Log::channel('glpi_sync')->error("Error ejecutando tarea recurrente", [
                    'task_id' => $task->id,
                    'task_name' => $task->name,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }

        $this->newLine();
        $this->info("📊 Resumen:");
        $this->info("   - Ejecutadas: {$executed}");
        if ($failed > 0) {
            $this->warn("   - Fallidas: {$failed}");
        }

        return 0;
    }
}
