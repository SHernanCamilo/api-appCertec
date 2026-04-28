<?php

namespace App\Models\TalentoHumano;

use Illuminate\Database\Eloquent\Model;

class EventNovedadCargo extends Model
{
    protected $table = 'event_novedad_cargo';

    protected $fillable = [
        'novedad_id',
        'empresa_id',
        'cargo_id',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    public function novedad()
    {
        return $this->belongsTo(EventNovedad::class, 'novedad_id');
    }

    public function empresa()
    {
        return $this->belongsTo(\App\Models\Empresa::class, 'empresa_id');
    }

    public function cargo()
    {
        return $this->belongsTo(\App\Models\Cargo::class, 'cargo_id', 'id_cargo');
    }
}
