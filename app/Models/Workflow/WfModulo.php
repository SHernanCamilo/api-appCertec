<?php

namespace App\Models\Workflow;

use Illuminate\Database\Eloquent\Model;

/**
 * Módulos del sistema que usan flujos de aprobación.
 * Ej: anticipos, horas_extras, eventos, permisos, etc.
 */
class WfModulo extends Model
{
    protected $table = 'wf_modulos';

    protected $fillable = [
        'codigo',
        'nombre',
        'descripcion',
        'estado',
    ];

    protected $casts = [
        'estado' => 'boolean',
    ];

    public function definiciones()
    {
        return $this->hasMany(WfDefinicion::class, 'id_modulo');
    }

    public function scopeActivos($query)
    {
        return $query->where('estado', true);
    }

    public function scopePorCodigo($query, string $codigo)
    {
        return $query->where('codigo', $codigo);
    }
}
