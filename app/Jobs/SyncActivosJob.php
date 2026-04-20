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

    /**
     * Ejecutar la sincronización de activos
     */
    protected function execute(ScheduledTask $task)
    {
        $empresaId = $this->parameters['empresa_id'] ?? null;
        $forceFullSync = $this->parameters['force_full_sync'] ?? false;

        Log::info("Iniciando sincronización de activos GLPI", [
            'empresa_id' => $empresaId,
            'force_full_sync' => $forceFullSync,
        ]);

        // Llamar al comando existente de sincronización
        $exitCode = Artisan::call('glpi:sync-activos', [
            '--empresa' => $empresaId,
            '--force' => $forceFullSync,
        ]);

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
