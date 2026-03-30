<?php

namespace App\Models\Workflow;

use Illuminate\Database\Eloquent\Model;

/**
 * Paso dentro de un flujo de aprobación.
 * Cada paso tiene un orden y un rol aprobador.
 */
class WfPaso extends Model
{
    protected $table = 'wf_pasos';

    protected $fillable = [
        'id_definicion',
        'orden',
        'nombre_paso',
        'rol_aprobador',
        'es_opcional',
        'permite_rechazo',
        'requiere_monto',
        'estado',
    ];

    protected $casts = [
        'es_opcional' => 'boolean',
        'permite_rechazo' => 'boolean',
        'requiere_monto' => 'boolean',
        'estado' => 'boolean',
        'orden' => 'integer',
    ];

    public function definicion()
    {
        return $this->belongsTo(WfDefinicion::class, 'id_definicion');
    }

    public function aprobadores()
    {
        return $this->hasMany(WfAprobador::class, 'id_paso');
    }

    public function aprobaciones()
    {
        return $this->hasMany(WfAprobacion::class, 'id_paso');
    }

    public function scopeActivos($query)
    {
        return $query->where('estado', true);
    }

    public function scopeOrdenados($query)
    {
        return $query->orderBy('orden');
    }
}
