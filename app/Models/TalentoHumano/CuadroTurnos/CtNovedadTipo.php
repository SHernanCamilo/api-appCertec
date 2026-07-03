<?php

namespace App\Models\TalentoHumano\CuadroTurnos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CtNovedadTipo extends Model
{
    protected $table = 'humtal_ct_novedad_tipo';

    protected $fillable = [
        'codigo',
        'nombre',
        'descripcion',
        'afecta_turno',
        'requiere_reemplazo',
        'requiere_aprobacion',
        'color_hex',
        'estado',
    ];

    protected $casts = [
        'afecta_turno'        => 'boolean',
        'requiere_reemplazo'  => 'boolean',
        'requiere_aprobacion' => 'boolean',
        'estado'              => 'boolean',
    ];

    // =========================================================================
    // RELACIONES
    // =========================================================================

    public function novedades(): HasMany
    {
        return $this->hasMany(CtNovedad::class, 'id_novedad_tipo');
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopeActivos($query)
    {
        return $query->where('estado', true);
    }

    public function scopeRequierenReemplazo($query)
    {
        return $query->where('requiere_reemplazo', true);
    }

    public function scopeRequierenAprobacion($query)
    {
        return $query->where('requiere_aprobacion', true);
    }
}
