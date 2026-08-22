<?php

namespace App\Models\TalentoHumano\CuadroTurnos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BloqueoCuadro extends Model
{
    protected $table = 'humtal_bloqueo_cuadro';

    protected $fillable = [
        'id_cuadro', 'id_unidad_funcional', 'anio', 'mes', 'estado',
        'bloqueado_en', 'bloqueado_por', 'tipo_bloqueo',
        'desbloqueado_en', 'desbloqueado_por', 'motivo_desbloqueo',
    ];

    protected $casts = [
        'bloqueado_en'    => 'datetime',
        'desbloqueado_en' => 'datetime',
    ];

    // Relaciones
    public function cuadro(): BelongsTo { return $this->belongsTo(CtCuadro::class, 'id_cuadro'); }
    public function unidadFuncional(): BelongsTo { return $this->belongsTo(\App\Models\Config\ConfigUnidadFuncional::class, 'id_unidad_funcional'); }

    // Scopes
    public function scopeBloqueados($query) { return $query->where('estado', 'bloqueado'); }
    public function scopeDesbloqueados($query) { return $query->where('estado', 'desbloqueado'); }
    public function scopePorPeriodo($query, int $anio, int $mes) { return $query->where('anio', $anio)->where('mes', $mes); }

    /**
     * Verifica si una unidad est+� bloqueada para un per+�odo.
     */
    public static function estaBloqueada(int $idUnidad, int $anio, int $mes): bool
    {
        return self::where('id_unidad_funcional', $idUnidad)
            ->where('anio', $anio)
            ->where('mes', $mes)
            ->where('estado', 'bloqueado')
            ->exists();
    }
}
