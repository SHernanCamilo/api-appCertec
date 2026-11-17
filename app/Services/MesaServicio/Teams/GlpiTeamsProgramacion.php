<?php

declare(strict_types=1);

namespace App\Services\MesaServicio\Teams;

use App\Models\MesaServicio\GlpiTeamsDestino;
use App\Models\ScheduledTask;
use App\Services\TaskSchedulerService;
use Throwable;

class GlpiTeamsProgramacion
{
    public function __construct(private TaskSchedulerService $tareas)
    {
    }

    public function sincronizar(GlpiTeamsDestino $destino): ScheduledTask
    {
        [$tipo, $valor] = $this->recurrencia($destino);
        $parametros = ['destino_id' => (int) $destino->id];
        $nombre = 'Tablero TIC — '.$destino->nombre;
        $descripcion = 'Aviso del tablero TIC al destino '.$destino->nombre;
        $tarea = $destino->scheduled_task_id
            ? ScheduledTask::query()->find($destino->scheduled_task_id)
            : null;

        if (! $tarea || $tarea->type !== 'notificar_tablero_tic') {
            $tarea = $this->tareas->scheduleRecurringTask(
                $nombre,
                'notificar_tablero_tic',
                $tipo,
                $valor,
                $parametros,
                $descripcion,
                $destino->created_by ? (int) $destino->created_by : null
            );
            $destino->scheduled_task_id = $tarea->id;
            $destino->save();
        } else {
            $tarea = $this->tareas->updateTask($tarea, [
                'name' => $nombre,
                'description' => $descripcion,
                'parameters' => $parametros,
                'recurrence_type' => $tipo,
                'recurrence_value' => $valor,
            ]);
        }

        if ((bool) $tarea->is_active !== (bool) $destino->activo) {
            $this->tareas->toggleRecurringTask($tarea->id, (bool) $destino->activo);
            $tarea->refresh();
        }

        return $tarea;
    }

    public function retirar(GlpiTeamsDestino $destino): void
    {
        $tarea = $destino->scheduled_task_id
            ? ScheduledTask::query()->find($destino->scheduled_task_id)
            : null;
        if (! $tarea) {
            return;
        }

        try {
            if ($tarea->is_active) {
                $this->tareas->toggleRecurringTask($tarea->id, false);
            }
        } catch (Throwable) {
            $this->tareas->dropQueuedJob($tarea);
        }

        $tarea->delete();
        $destino->scheduled_task_id = null;
        $destino->save();
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function recurrencia(GlpiTeamsDestino $destino): array
    {
        if ($destino->programacion === 'hora') {
            $hora = $this->hora($destino->hora);

            return [ScheduledTask::RECURRENCE_DAILY, ['time' => $hora]];
        }

        return match ($this->intervalo((int) $destino->intervalo_minutos)) {
            5 => [ScheduledTask::RECURRENCE_EVERY_5_MINUTES, []],
            15 => [ScheduledTask::RECURRENCE_EVERY_15_MINUTES, []],
            60 => [ScheduledTask::RECURRENCE_HOURLY, []],
            default => [ScheduledTask::RECURRENCE_EVERY_30_MINUTES, []],
        };
    }

    public function intervalo(int $minutos): int
    {
        return match (true) {
            $minutos <= 5 => 5,
            $minutos <= 15 => 15,
            $minutos <= 30 => 30,
            default => 60,
        };
    }

    public function hora(?string $hora): string
    {
        $hora = substr(trim((string) $hora), 0, 5);
        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $hora) !== 1) {
            return '08:00';
        }

        return $hora;
    }
}
