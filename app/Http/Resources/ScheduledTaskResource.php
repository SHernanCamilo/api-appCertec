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
            'attempts' => $this->attempts,
            'max_attempts' => $this->max_attempts,
            'can_retry' => $this->canRetry(),
            'is_overdue' => $this->isOverdue(),
            'duration' => $this->duration,
            'duration_formatted' => $this->duration ? $this->formatDuration($this->duration) : null,
            'result' => $this->result,
            'error_message' => $this->error_message,
            'job_id' => $this->job_id,
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
}
