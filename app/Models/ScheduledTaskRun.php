<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduledTaskRun extends Model
{
    protected $fillable = [
        'scheduled_task_id',
        'status',
        'started_at',
        'completed_at',
        'duration_seconds',
        'result',
        'error_message',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'duration_seconds' => 'integer',
    ];

    public function task()
    {
        return $this->belongsTo(ScheduledTask::class, 'scheduled_task_id');
    }
}
