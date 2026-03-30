<?php

namespace App\Models\Finance\Workflow;

use Illuminate\Database\Eloquent\Model;

class AntiFlujo extends Model
{
    protected $table = 'anti_flujos';

    protected $fillable = ['codigo', 'nombre', 'descripcion', 'estado'];

    protected $casts = ['estado' => 'boolean'];

    public function pasos()
    {
        return $this->hasMany(AntiFlujoPaso::class, 'id_flujo')->orderBy('orden');
    }

    public function reglas()
    {
        return $this->hasMany(AntiFlujoRegla::class, 'id_flujo')->orderBy('prioridad');
    }

    public function scopeActivos($query)
    {
        return $query->where('estado', true);
    }
}
