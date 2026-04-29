<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScheduledTaskResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $taskConfig = config("scheduled-tasks.types.{$this->type}", []);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'type_name' => $taskConfig['name'] ?? $this->type,
            'type_description' => $taskConfig['description'] ?? null,
            'status' => $this->status,
            'status_label' => $this->getStatusLabel(),
            'description' => $this->description,
            'parameters' => $this->parameters,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'last_run_at' => $this->last_run_at?->toIso8601String(),
            'next_run_at' => $this->next_run_at?->toIso8601String(),
            'attempts' => $this->attempts,
            'max_attempts' => $this->max_attempts,
            'can_retry' => $this->canRetry(),
            'is_overdue' => $this->isOverdue(),
            'duration' => $this->duration,
            'duration_formatted' => $this->duration ? $this->formatDuration($this->duration) : null,
            'result' => $this->result,
            'error_message' => $this->error_message,
            'job_id' => $this->job_id,
            'is_recurring' => $this->is_recurring,
            'is_active' => $this->is_active,
            'recurrence_type' => $this->recurrence_type,
            'recurrence_type_label' => $this->getRecurrenceTypeLabel(),
            'recurrence_value' => $this->recurrence_value,
            'recurrence_description' => $this->getRecurrenceDescription(),
            'created_by' => $this->created_by,
            'creator' => $this->whenLoaded('creator', function () {
                return [
                    'id' => $this->creator->id,
                    'name' => $this->creator->name,
                    'email' => $this->creator->email,
                ];
            }),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }

    /**
     * Obtener etiqueta del estado
     */
    private function getStatusLabel(): string
    {
        return match($this->status) {
            'pending' => 'Pendiente',
            'running' => 'En Ejecución',
            'completed' => 'Completada',
            'failed' => 'Fallida',
            'cancelled' => 'Cancelada',
            default => $this->status,
        };
    }

    /**
     * Formatear duración en formato legible
     */
    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}s";
        }
        
        $minutes = floor($seconds / 60);
        $remainingSeconds = $seconds % 60;
        
        if ($minutes < 60) {
            return "{$minutes}m {$remainingSeconds}s";
        }
        
        $hours = floor($minutes / 60);
        $remainingMinutes = $minutes % 60;
        
        return "{$hours}h {$remainingMinutes}m";
    }

    /**
     * Obtener etiqueta del tipo de recurrencia
     */
    private function getRecurrenceTypeLabel(): ?string
    {
        if (!$this->is_recurring) {
            return null;
        }

        return match($this->recurrence_type) {
            'every_minute' => 'Cada minuto',
            'every_5_minutes' => 'Cada 5 minutos',
            'every_15_minutes' => 'Cada 15 minutos',
            'every_30_minutes' => 'Cada 30 minutos',
            'hourly' => 'Cada hora',
            'daily' => 'Diariamente',
            'weekly' => 'Semanalmente',
            'monthly' => 'Mensualmente',
            'custom_days' => 'Días personalizados',
            'cron' => 'Expresión cron',
            default => $this->recurrence_type,
        };
    }

    /**
     * Obtener descripción legible de la recurrencia
     */
    private function getRecurrenceDescription(): ?string
    {
        if (!$this->is_recurring || !$this->recurrence_value) {
            return null;
        }

        $value = $this->recurrence_value;

        return match($this->recurrence_type) {
            'daily' => "Todos los días a las {$value['time']}",
            'weekly' => "Cada " . $this->getDayName($value['day_of_week']) . " a las {$value['time']}",
            'monthly' => $value['day'] === 'last' 
                ? "Último día del mes a las {$value['time']}"
                : "Día {$value['day']} de cada mes a las {$value['time']}",
            'custom_days' => "Días: " . implode(', ', array_map([$this, 'getDayName'], $value['days'])) . " a las {$value['time']}",
            default => null,
        };
    }

    /**
     * Obtener nombre del día
     */
    private function getDayName(int $day): string
    {
        return match($day) {
            0 => 'Domingo',
            1 => 'Lunes',
            2 => 'Martes',
            3 => 'Miércoles',
            4 => 'Jueves',
            5 => 'Viernes',
            6 => 'Sábado',
            default => (string)$day,
        };
    }
}
