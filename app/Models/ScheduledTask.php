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
        'is_recurring',
        'is_active',
        'recurrence_type',
        'recurrence_value',
        'last_run_at',
        'next_run_at',
    ];

    protected $casts = [
        'parameters' => 'array',
        'recurrence_value' => 'array',
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'last_run_at' => 'datetime',
        'next_run_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'is_recurring' => 'boolean',
        'is_active' => 'boolean',
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

    // Tipos de recurrencia
    const RECURRENCE_EVERY_MINUTE = 'every_minute';
    const RECURRENCE_EVERY_5_MINUTES = 'every_5_minutes';
    const RECURRENCE_EVERY_15_MINUTES = 'every_15_minutes';
    const RECURRENCE_EVERY_30_MINUTES = 'every_30_minutes';
    const RECURRENCE_HOURLY = 'hourly';
    const RECURRENCE_DAILY = 'daily';
    const RECURRENCE_WEEKLY = 'weekly';
    const RECURRENCE_MONTHLY = 'monthly';
    const RECURRENCE_CUSTOM_DAYS = 'custom_days';
    const RECURRENCE_CRON = 'cron';

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

    /**
     * Scope para tareas recurrentes activas
     */
    public function scopeRecurringActive($query)
    {
        return $query->where('is_recurring', true)
            ->where('is_active', true);
    }

    /**
     * Scope para tareas que deben ejecutarse (recurrentes)
     */
    public function scopeReadyToExecuteRecurring($query)
    {
        return $query->where('is_recurring', true)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('next_run_at')
                  ->orWhere('next_run_at', '<=', Carbon::now());
            })
            ->where(function ($q) {
                $q->where('status', self::STATUS_PENDING)
                  ->orWhere('status', self::STATUS_COMPLETED);
            });
    }

    /**
     * Marcar tarea recurrente como completada y calcular próxima ejecución
     */
    public function markAsCompletedRecurring($result = null)
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'completed_at' => Carbon::now(),
            'last_run_at' => Carbon::now(),
            'result' => $result,
        ]);

        // Calcular próxima ejecución
        if ($this->is_recurring && $this->is_active) {
            $this->calculateNextRun();
        }
    }

    /**
     * Calcular próxima ejecución basada en el tipo de recurrencia
     */
    public function calculateNextRun()
    {
        $now = Carbon::now();
        $nextRun = null;

        switch ($this->recurrence_type) {
            case self::RECURRENCE_EVERY_MINUTE:
                $nextRun = $now->copy()->addMinute();
                break;

            case self::RECURRENCE_EVERY_5_MINUTES:
                $nextRun = $now->copy()->addMinutes(5);
                break;

            case self::RECURRENCE_EVERY_15_MINUTES:
                $nextRun = $now->copy()->addMinutes(15);
                break;

            case self::RECURRENCE_EVERY_30_MINUTES:
                $nextRun = $now->copy()->addMinutes(30);
                break;

            case self::RECURRENCE_HOURLY:
                $nextRun = $now->copy()->addHour();
                break;

            case self::RECURRENCE_DAILY:
                $time = $this->recurrence_value['time'] ?? '00:00';
                [$hour, $minute] = explode(':', $time);
                $nextRun = $now->copy()->addDay()->setTime((int)$hour, (int)$minute, 0);
                break;

            case self::RECURRENCE_WEEKLY:
                $time = $this->recurrence_value['time'] ?? '00:00';
                $dayOfWeek = $this->recurrence_value['day_of_week'] ?? 1; // 1=Lunes
                [$hour, $minute] = explode(':', $time);
                
                $nextRun = $now->copy()->next($dayOfWeek)->setTime((int)$hour, (int)$minute, 0);
                break;

            case self::RECURRENCE_MONTHLY:
                $time = $this->recurrence_value['time'] ?? '00:00';
                $day = $this->recurrence_value['day'] ?? 1;
                [$hour, $minute] = explode(':', $time);
                
                if ($day === 'last') {
                    $nextRun = $now->copy()->addMonth()->endOfMonth()->setTime((int)$hour, (int)$minute, 0);
                } else {
                    $nextRun = $now->copy()->addMonth()->setDay((int)$day)->setTime((int)$hour, (int)$minute, 0);
                }
                break;

            case self::RECURRENCE_CUSTOM_DAYS:
                $time = $this->recurrence_value['time'] ?? '00:00';
                $days = $this->recurrence_value['days'] ?? [1]; // Array de días [1,3,5]
                [$hour, $minute] = explode(':', $time);
                
                $nextRun = $now->copy()->addDay();
                while (!in_array($nextRun->dayOfWeek, $days)) {
                    $nextRun->addDay();
                }
                $nextRun->setTime((int)$hour, (int)$minute, 0);
                break;

            case self::RECURRENCE_CRON:
                // Para expresiones cron personalizadas
                // Requiere librería cron-expression
                break;
        }

        if ($nextRun) {
            $this->update([
                'next_run_at' => $nextRun,
                'status' => self::STATUS_PENDING,
            ]);
        }
    }
}
