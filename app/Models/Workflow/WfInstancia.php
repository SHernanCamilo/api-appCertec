<?php

namespace App\Models\Workflow;

use Illuminate\Database\Eloquent\Model;

/**
 * Instancia de flujo (cada solicitud tiene una instancia).
 * Representa la ejecución de un flujo para un registro específico.
 */
class WfInstancia extends Model
{
    protected $table = 'wf_instancias';

    protected $fillable = [
        'id_definicion',
        'id_modulo',
        'modulo_record_id',
        'id_paso_actual',
        'estado',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    const ESTADO_EN_PROGRESO = 'en_progreso';
    const ESTADO_COMPLETADO = 'completado';
    const ESTADO_RECHAZADO = 'rechazado';
    const ESTADO_CANCELADO = 'cancelado';

    public function definicion()
    {
        return $this->belongsTo(WfDefinicion::class, 'id_definicion');
    }

    public function modulo()
    {
        return $this->belongsTo(WfModulo::class, 'id_modulo');
    }

    public function pasoActual()
    {
        return $this->belongsTo(WfPaso::class, 'id_paso_actual');
    }

    public function aprobaciones()
    {
        return $this->hasMany(WfAprobacion::class, 'id_instancia')->orderBy('fecha_accion');
    }

    public function notificaciones()
    {
        return $this->hasMany(WfNotificacion::class, 'id_instancia');
    }

    public function scopeEnProgreso($query)
    {
        return $query->where('estado', self::ESTADO_EN_PROGRESO);
    }

    public function scopeCompletados($query)
    {
        return $query->where('estado', self::ESTADO_COMPLETADO);
    }

    public function scopeRechazados($query)
    {
        return $query->where('estado', self::ESTADO_RECHAZADO);
    }

    public function estaEnProgreso(): bool
    {
        return $this->estado === self::ESTADO_EN_PROGRESO;
    }

    public function estaCompletado(): bool
    {
        return $this->estado === self::ESTADO_COMPLETADO;
    }

    public function estaRechazado(): bool
    {
        return $this->estado === self::ESTADO_RECHAZADO;
    }
}
