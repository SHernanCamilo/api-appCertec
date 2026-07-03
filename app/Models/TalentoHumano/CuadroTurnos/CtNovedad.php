<?php

namespace App\Models\TalentoHumano\CuadroTurnos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\ConfigPersonTercero;
use App\Models\User;

class CtNovedad extends Model
{
    protected $table = 'humtal_ct_novedad';

    // Estados de la novedad
    const ESTADO_PENDIENTE  = 'pendiente';
    const ESTADO_APROBADO   = 'aprobado';
    const ESTADO_RECHAZADO  = 'rechazado';

    protected $fillable = [
        'id_cuadro',
        'id_asignacion',
        'id_empleado',
        'id_novedad_tipo',
        'id_empleado_reemplaza',
        'fecha_inicio',
        'fecha_fin',
        'estado',
        'motivo',
        'observacion',
        'solicitado_por',
        'aprobado_por',
        'fecha_aprobacion',
        'comentario_aprobacion',
    ];

    protected $casts = [
        'fecha_inicio'     => 'date',
        'fecha_fin'        => 'date',
        'fecha_aprobacion' => 'datetime',
    ];

    // =========================================================================
    // RELACIONES
    // =========================================================================

    public function cuadro(): BelongsTo
    {
        return $this->belongsTo(CtCuadro::class, 'id_cuadro');
    }

    public function asignacion(): BelongsTo
    {
        return $this->belongsTo(CtAsignacion::class, 'id_asignacion');
    }

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(ConfigPersonTercero::class, 'id_empleado');
    }

    public function novedadTipo(): BelongsTo
    {
        return $this->belongsTo(CtNovedadTipo::class, 'id_novedad_tipo');
    }

    public function empleadoReemplaza(): BelongsTo
    {
        return $this->belongsTo(ConfigPersonTercero::class, 'id_empleado_reemplaza');
    }

    public function solicitadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitado_por');
    }

    public function aprobadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobado_por');
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopePendientes($query)
    {
        return $query->where('estado', self::ESTADO_PENDIENTE);
    }

    public function scopeAprobadas($query)
    {
        return $query->where('estado', self::ESTADO_APROBADO);
    }

    public function scopeRechazadas($query)
    {
        return $query->where('estado', self::ESTADO_RECHAZADO);
    }

    public function scopePorEmpleado($query, int $idEmpleado)
    {
        return $query->where('id_empleado', $idEmpleado);
    }

    public function scopePorCuadro($query, int $idCuadro)
    {
        return $query->where('id_cuadro', $idCuadro);
    }
}
