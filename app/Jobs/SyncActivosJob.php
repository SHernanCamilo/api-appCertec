<?php

namespace App\Jobs;

use App\Models\ScheduledTask;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class SyncActivosJob extends BaseScheduledJob
{
    /**
     * Timeout en segundos (1 hora)
     */
    public $timeout = 3600;

    public function __construct($scheduledTaskId, array $parameters = [])
    {
        parent::__construct($scheduledTaskId, $parameters);
        $this->onQueue(config('scheduled-tasks.types.sync_activos.queue', 'scheduled-tasks-heavy'));
    }

    /**
     * Ejecutar la sincronización de activos
     */
    protected function execute(ScheduledTask $task)
    {
        $forceFullSync = filter_var($this->parameters['force_full_sync'] ?? false, FILTER_VALIDATE_BOOLEAN);

        Log::info("Iniciando sincronización de activos GLPI", [
            'alcance' => 'todas_las_empresas',
            'force_full_sync' => $forceFullSync,
        ]);

        // El comando no filtra por empresa: cada activo toma empresa, sucursal y sede de su agente.
        // Sincronización completa recorre todo GLPI y actualiza los que ya existen.
        $options = [];
        if ($forceFullSync) {
            $options['--full-sync'] = true;
            $options['--force'] = true;
        }

        $exitCode = Artisan::call('glpi:sync-activos', $options);

        $output = Artisan::output();

        if ($exitCode === 0) {
            Log::info("Sincronización de activos completada exitosamente");
            return "Sincronización completada. " . trim($output);
        } else {
            Log::error("Error en sincronización de activos", ['output' => $output]);
            throw new \Exception("Error en sincronización: " . trim($output));
        }
    }
}
