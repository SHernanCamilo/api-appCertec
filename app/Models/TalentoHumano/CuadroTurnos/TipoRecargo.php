<?php

namespace App\Models\TalentoHumano\CuadroTurnos;

use Illuminate\Database\Eloquent\Model;

class TipoRecargo extends Model
{
    protected $table = 'humtal_tipos_recargo';

    protected $fillable = [
        'codigo',
        'nombre',
        'porcentaje',
        'es_hora_extra',
        'aplica_dominical_festivo',
        'hora_inicio',
        'hora_fin',
        'activo',
    ];

    protected $casts = [
        'porcentaje'              => 'decimal:2',
        'es_hora_extra'           => 'boolean',
        'aplica_dominical_festivo' => 'boolean',
        'activo'                  => 'boolean',
    ];

    // Scopes
    public function scopeActivos($query) { return $query->where('activo', true); }
    public function scopeHorasExtra($query) { return $query->where('es_hora_extra', true); }
    public function scopeRecargosSimples($query) { return $query->where('es_hora_extra', false); }
}
