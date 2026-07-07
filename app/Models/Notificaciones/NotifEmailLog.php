<?php

namespace App\Models\Notificaciones;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotifEmailLog extends Model
{
    protected $table = 'notif_email_logs';

    // =========================================================================
    // CONSTANTES
    // =========================================================================

    public const STATUS_PENDING = 'PENDING';
    public const STATUS_SENT    = 'SENT';
    public const STATUS_ERROR   = 'ERROR';
    public const STATUS_EXPIRED = 'EXPIRED';

    public const DELIVERY_PENDING   = 'PENDING';
    public const DELIVERY_DELIVERED  = 'DELIVERED';
    public const DELIVERY_BOUNCED    = 'BOUNCED';
    public const DELIVERY_FAILED     = 'FAILED';

    // =========================================================================
    // MASS ASSIGNMENT
    // =========================================================================

    protected $fillable = [
        'tipo',
        'identificacion_paciente',
        'nombre_paciente',
        'profesional_nombre',
        'email_to',
        'subject',
        'body',
        'status',
        'delivery_status',
        'message_id',
        'error_message',
        'bounce_reason',
        'intentos',
        'fecha_envio',
        'fecha_intento',
        'delivered_at',
        'bounce_detected_at',
        'ingreso',
        'clinica',
        'unidad_funcional',
        'cama',
        'orden',
        'especialidad',
        'diagnostico',
        'folio',
        'estado_orden',
        'fecha_orden',
        'observaciones',
    ];

    protected $casts = [
        'fecha_envio'        => 'datetime',
        'fecha_intento'      => 'datetime',
        'delivered_at'       => 'datetime',
        'bounce_detected_at' => 'datetime',
        'fecha_orden'        => 'datetime',
        'intentos'           => 'integer',
    ];

    // =========================================================================
    // RELACIONES
    // =========================================================================

    public function traces(): HasMany
    {
        return $this->hasMany(NotifEmailTrace::class, 'email_log_id');
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopePendientes(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeEnviados(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SENT);
    }

    public function scopeErrores(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ERROR);
    }

    public function scopePorTipo(Builder $query, string $tipo): Builder
    {
        return $query->where('tipo', $tipo);
    }

    public function scopePorPaciente(Builder $query, string $identificacion): Builder
    {
        return $query->where('identificacion_paciente', $identificacion);
    }
}
