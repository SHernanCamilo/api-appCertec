<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\MesaServicio\Teams\GlpiTeamsNotificador;
use Illuminate\Console\Command;
use Throwable;

class NotificarTableroTicTeams extends Command
{
    protected $signature = 'mesa:notificar-teams {--id=} {--force}';

    protected $description = 'Envía a Teams el resumen o los casos del tablero TIC según los destinos activos';

    public function handle(GlpiTeamsNotificador $notificador): int
    {
        $id = $this->option('id');
        $id = is_numeric($id) ? (int) $id : null;

        try {
            $lineas = $notificador->enviarPendientes($id, (bool) $this->option('force'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($lineas === []) {
            $this->info('Sin destinos pendientes.');

            return self::SUCCESS;
        }

        foreach ($lineas as $linea) {
            $this->line($linea);
        }

        return self::SUCCESS;
    }
}
