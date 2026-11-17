<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\MesaServicio\GlpiTeamsDestino;
use App\Models\ScheduledTask;
use App\Services\MesaServicio\Teams\GlpiTeamsNotificador;

class NotificarTableroTicJob extends BaseScheduledJob
{
    public $tries = 1;

    public $timeout = 180;

    protected function execute(ScheduledTask $task)
    {
        $id = $this->parameters['destino_id'] ?? $task->parameters['destino_id'] ?? null;
        if (! is_numeric($id)) {
            return 'Sin destino de Teams.';
        }

        $destino = GlpiTeamsDestino::query()->find((int) $id);
        if (! $destino || ! $destino->activo) {
            return 'Destino inactivo.';
        }

        $estado = app(GlpiTeamsNotificador::class)->enviarProgramado($destino);

        return $destino->nombre.': '.$estado;
    }
}
