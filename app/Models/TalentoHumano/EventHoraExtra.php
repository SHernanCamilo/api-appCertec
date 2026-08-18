<?php

namespace App\Models\TalentoHumano;

use Illuminate\Database\Eloquent\Model;
use App\Models\Empleado;

class EventHoraExtra extends Model
{
    protected $table = 'event_horas_extra';

    public $timestamps = false;

    protected $fillable = [
        'consecutivo',
        'id_user_nov',
        'id_user_aprobador',
        'id_unidad_funcional',
        'id_motivo_evento',
        'id_user_cubre',
        'fecha_nov_ini',
        'fecha_nov_fin',
        'fecha_solicitud',
        'coment_solicitante',
        'coment_aprobador',
        'coment_autorizador',
        'coment_digitalizador',
        'fecha_aprobacion',
        'fecha_autorizacion',
        'fecha_digitalizacion',
        'user_digitalizador',
        'id_motivo_rechazo',
        'estado',
        'wf_instancia_id',
        'id_user_reg',
    ];

    protected $casts = [
        'fecha_nov_ini'   => 'datetime',
        'fecha_nov_fin'   => 'datetime',
        'fecha_solicitud' => 'datetime',
    ];

    /** Alias que consume el frontend al editar/listar solicitudes. */
    protected $appends = ['empleado_id', 'novedad_id', 'aprobador_id', 'empleado_cubre_id'];

    public function getEmpleadoIdAttribute(): ?int
    {
        $valor = $this->attributes['id_user_nov'] ?? null;
        return $valor !== null ? (int) $valor : null;
    }

    public function getNovedadIdAttribute(): ?int
    {
        $valor = $this->attributes['id_motivo_evento'] ?? null;
        return $valor !== null ? (int) $valor : null;
    }

    public function getAprobadorIdAttribute(): ?int
    {
        $valor = $this->attributes['id_user_aprobador'] ?? null;
        return $valor !== null ? (int) $valor : null;
    }

    public function getEmpleadoCubreIdAttribute(): ?int
    {
        $valor = $this->attributes['id_user_cubre'] ?? null;
        return $valor !== null ? (int) $valor : null;
    }

    public function empleado()
    {
        return $this->belongsTo(Empleado::class, 'id_user_nov');
    }

    public function aprobador()
    {
        return $this->belongsTo(Empleado::class, 'id_user_aprobador');
    }

    public function empleadoCubre()
    {
        return $this->belongsTo(Empleado::class, 'id_user_cubre');
    }

    public function novedad()
    {
        return $this->belongsTo(EventNovedad::class, 'id_motivo_evento');
    }

    public function motivoRechazo()
    {
        return $this->belongsTo(\App\Models\Config\ConfigMotRechazo::class, 'id_motivo_rechazo');
    }

    public function instancia()
    {
        return $this->belongsTo(\App\Models\Workflow\WfInstancia::class, 'wf_instancia_id');
    }
}
