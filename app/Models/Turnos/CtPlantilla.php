<?php

namespace App\Models\Turnos;

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
     * Retorna descripción formateada: "07:00 - 15:00 (8h)"
     */
    public function getDuracionFormateada(): string
    {
        $inicio = substr($this->hora_inicio, 0, 5);
        $fin    = substr($this->hora_fin, 0, 5);
        $horas  = (float) $this->duracion_horas;

        $horasInt  = (int) $horas;
        $minutos   = (int) round(($horas - $horasInt) * 60);

        $duracion = $minutos > 0
            ? "{$horasInt}h {$minutos}m"
            : "{$horasInt}h";

        return "{$inicio} - {$fin} ({$duracion})";
    }
}
