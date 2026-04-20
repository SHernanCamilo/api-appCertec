<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

class ScheduledTask extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'type',
        'status',
        'parameters',
        'description',
        'scheduled_at',
        'started_at',
        'completed_at',
        'attempts',
        'max_attempts',
        'result',
        'error_message',
        'job_id',
        'created_by',
    ];

    protected $casts = [
        'parameters' => 'array',
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    // Tipos de tareas disponibles
    const TYPE_SYNC_ACTIVOS = 'sync_activos';
    const TYPE_CIERRE_AUTOMATICO = 'cierre_automatico';
    const TYPE_MANTENIMIENTO_DB = 'mantenimiento_db';
    const TYPE_ENVIO_REPORTES = 'envio_reportes';

    // Estados de tareas
    const STATUS_PENDING = 'pending';
    const STATUS_RUNNING = 'running';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_CANCELLED = 'cancelled';

    /**
     * Relación con el usuario que creó la tarea
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Scope para tareas pendientes
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope para tareas en ejecución
     */
    public function scopeRunning($query)
    {
        return $query->where('status', self::STATUS_RUNNING);
    }

    /**
     * Scope para tareas completadas
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * Scope para tareas fallidas
     */
    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /**
     * Scope para tareas que deben ejecutarse ahora
     */
    public function scopeReadyToExecute($query)
    {
        return $query->where('status', self::STATUS_PENDING)
            ->where(function ($q) {
                $q->whereNull('scheduled_at')
                  ->orWhere('scheduled_at', '<=', Carbon::now());
            });
    }

    /**
     * Scope por tipo de tarea
     */
    public function scopeOfType($query, $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Marcar tarea como iniciada
     */
    public function markAsStarted()
    {
        $this->update([
            'status' => self::STATUS_RUNNING,
            'started_at' => Carbon::now(),
            'attempts' => $this->attempts + 1,
        ]);
    }

    /**
     * Marcar tarea como completada
     */
    public function markAsCompleted($result = null)
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'completed_at' => Carbon::now(),
            'result' => $result,
        ]);
    }

    /**
     * Marcar tarea como fallida
     */
    public function markAsFailed($errorMessage)
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'completed_at' => Carbon::now(),
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * Marcar tarea como cancelada
     */
    public function markAsCancelled()
    {
        $this->update([
            'status' => self::STATUS_CANCELLED,
            'completed_at' => Carbon::now(),
        ]);
    }

    /**
     * Verificar si puede reintentar
     */
    public function canRetry()
    {
        return $this->attempts < $this->max_attempts;
    }

    /**
     * Obtener duración de ejecución
     */
    public function getDurationAttribute()
    {
        if ($this->started_at && $this->completed_at) {
            return $this->started_at->diffInSeconds($this->completed_at);
        }
        return null;
    }

    /**
     * Verificar si está vencida
     */
    public function isOverdue()
    {
        return $this->status === self::STATUS_PENDING 
            && $this->scheduled_at 
            && $this->scheduled_at->isPast();
    }
}
