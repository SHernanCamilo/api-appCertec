<?php

namespace App\Models\Notificaciones;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotifEmailTrace extends Model
{
    protected $table = 'notif_email_trace';

    /**
     * La tabla solo tiene created_at (no updated_at).
     */
    public $timestamps = false;

    protected $fillable = [
        'email_log_id',
        'event_type',
        'event_status',
        'event_message',
        'event_details',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    // =========================================================================
    // RELACIONES
    // =========================================================================

    public function emailLog(): BelongsTo
    {
        return $this->belongsTo(NotifEmailLog::class, 'email_log_id');
    }
}
