<?php

namespace App\Models\TalentoHumano\CuadroTurnos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Empresa;

class CtPlantilla extends Model
{
    protected $table = 'humtal_ct_plantillas';

    protected $fillable = [
        'codigo',
        'nombre',
        'hora_inicio',
        'hora_fin',
        'hora_inicio_2',
        'hora_fin_2',
        'duracion_horas',
        'es_nocturno',
        'color_hex',
        'id_empresa',
        'estado',
    ];

    protected $casts = [
        'duracion_horas' => 'decimal:2',
        'es_nocturno'    => 'boolean',
        'estado'         => 'boolean',
    ];

    // =========================================================================
    // RELACIONES
    // =========================================================================

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'id_empresa');
    }

    public function asignaciones(): HasMany
    {
        return $this->hasMany(CtAsignacion::class, 'id_plantilla');
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopeActivas($query)
    {
        return $query->where('estado', true);
    }

    public function scopePorEmpresa($query, int $idEmpresa)
    {
        return $query->where(function ($q) use ($idEmpresa) {
            $q->where('id_empresa', $idEmpresa)
              ->orWhereNull('id_empresa');
        });
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Indica si la plantilla tiene jornada partida (dos rangos).
     */
    public function esJornadaPartida(): bool
    {
        return !empty($this->hora_inicio_2) && !empty($this->hora_fin_2);
    }

    /**
     * Calcula la duración total en horas (sumando ambos rangos si existen).
     */
    public function calcularDuracionTotal(): float
    {
        $total = $this->calcularHorasRango($this->hora_inicio, $this->hora_fin);

        if ($this->esJornadaPartida()) {
            $total += $this->calcularHorasRango($this->hora_inicio_2, $this->hora_fin_2);
        }

        return round($total, 2);
    }

    /**
     * Calcula horas entre dos tiempos (HH:MM o HH:MM:SS).
     * Si fin < inicio, asume cruce de medianoche (turno nocturno).
     */
    public function calcularHorasRango(?string $inicio, ?string $fin): float
    {
        if (!$inicio || !$fin) {
            return 0;
        }

        $inicioMin = $this->timeToMinutes($inicio);
        $finMin    = $this->timeToMinutes($fin);

        // Cruce de medianoche
        if ($finMin <= $inicioMin) {
            $finMin += 24 * 60;
        }

        return ($finMin - $inicioMin) / 60;
    }

    private function timeToMinutes(string $time): int
    {
        $parts = explode(':', $time);
        return ((int) $parts[0]) * 60 + ((int) ($parts[1] ?? 0));
    }

    /**
     * Retorna descripción formateada.
     * Turno corrido:  "07:00 - 15:00 (8h)"
     * Jornada partida: "07:00 - 12:00 | 14:00 - 18:00 (9h)"
     */
    public function getDuracionFormateada(): string
    {
        $inicio = substr($this->hora_inicio, 0, 5);
        $fin    = substr($this->hora_fin, 0, 5);
        $horas  = (float) $this->duracion_horas;

        $horasInt = (int) $horas;
        $minutos  = (int) round(($horas - $horasInt) * 60);

        $duracion = $minutos > 0
            ? "{$horasInt}h {$minutos}m"
            : "{$horasInt}h";

        $rango = "{$inicio} - {$fin}";

        if ($this->esJornadaPartida()) {
            $inicio2 = substr($this->hora_inicio_2, 0, 5);
            $fin2    = substr($this->hora_fin_2, 0, 5);
            $rango  .= " | {$inicio2} - {$fin2}";
        }

        return "{$rango} ({$duracion})";
    }
}
