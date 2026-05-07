<?php

namespace App\Models\TalentoHumano;

use Illuminate\Database\Eloquent\Model;

class EventNovedad extends Model
{
    protected $table = 'event_novedades';

    protected $fillable = [
        'codigo',
        'descripcion',
        'cubre',
        'activo',
    ];

    protected $casts = [
        'cubre'  => 'boolean',
        'activo' => 'boolean',
    ];

    public function scopeActivos($query)
    {
        return $query->where('activo', true);
    }

    public function vinculaciones()
    {
        return $this->hasMany(EventNovedadCargo::class, 'novedad_id');
    }
}
