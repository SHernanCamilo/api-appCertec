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
        'hora_inicio_override_2',
        'hora_fin_override_2',
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

    /**
     * Retorna la hora de inicio del SEGUNDO rango (jornada partida).
     */
    public function getHoraInicio2(): ?string
    {
        if ($this->hora_inicio_override_2) {
            return $this->hora_inicio_override_2;
        }

        return $this->plantilla?->hora_inicio_2;
    }

    /**
     * Retorna la hora de fin del SEGUNDO rango (jornada partida).
     */
    public function getHoraFin2(): ?string
    {
        if ($this->hora_fin_override_2) {
            return $this->hora_fin_override_2;
        }

        return $this->plantilla?->hora_fin_2;
    }

    /**
     * Indica si la asignación es de jornada partida (tiene segundo rango).
     */
    public function esJornadaPartida(): bool
    {
        return !empty($this->getHoraInicio2()) && !empty($this->getHoraFin2());
    }

    /**
     * Retorna todos los rangos horarios de la asignación.
     * 
     * @return array  [['inicio' => 'HH:MM', 'fin' => 'HH:MM'], ...]
     */
    public function getRangos(): array
    {
        $rangos = [];

        if ($this->getHoraInicio() && $this->getHoraFin()) {
            $rangos[] = [
                'inicio' => $this->getHoraInicio(),
                'fin'    => $this->getHoraFin(),
            ];
        }

        if ($this->esJornadaPartida()) {
            $rangos[] = [
                'inicio' => $this->getHoraInicio2(),
                'fin'    => $this->getHoraFin2(),
            ];
        }

        return $rangos;
    }
}
