<?php

namespace App\Models\Turnos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\ConfigPersonTercero;

class CtAsignacion extends Model{
    protected $table = 'humtal_ct_asignacion';

    protected $fillable = [
        'id_cuadro',
        'id_empleado',
        'fecha',
        'id_plantilla',
        'es_descanso',
        'es_festivo',
        'hora_inicio_override',
        'hora_fin_override',
        'observacion',
    ];

    protected $casts = [
        'fecha'       => 'date',
        'es_descanso' => 'boolean',
        'es_festivo'  => 'boolean',
    ];

    // =========================================================================
    // RELACIONES
    // =========================================================================

    public function cuadro(): BelongsTo
    {
        return $this->belongsTo(CtCuadro::class, 'id_cuadro');
    }

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(ConfigPersonTercero::class, 'id_empleado');
    }

    public function plantilla(): BelongsTo
    {
        return $this->belongsTo(CtPlantilla::class, 'id_plantilla');
    }

    public function novedades(): HasMany
    {
        return $this->hasMany(CtNovedad::class, 'id_asignacion');
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopePorEmpleado($query, int $idEmpleado)
    {
        return $query->where('id_empleado', $idEmpleado);
    }

    public function scopePorFecha($query, string $fecha)
    {
        return $query->where('fecha', $fecha);
    }

    public function scopeConTurno($query)
    {
        return $query->whereNotNull('id_plantilla')->where('es_descanso', false);
    }

    public function scopeDescansos($query)
    {
        return $query->where('es_descanso', true);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Retorna la hora de inicio efectiva (override si existe, sino la de la plantilla).
     */
    public function getHoraInicio(): ?string
    {
        if ($this->hora_inicio_override) {
            return $this->hora_inicio_override;
        }

        return $this->plantilla?->hora_inicio;
    }

    /**
     * Retorna la hora de fin efectiva (override si existe, sino la de la plantilla).
     */
    public function getHoraFin(): ?string
    {
        if ($this->hora_fin_override) {
            return $this->hora_fin_override;
        }

        return $this->plantilla?->hora_fin;
    }
}
