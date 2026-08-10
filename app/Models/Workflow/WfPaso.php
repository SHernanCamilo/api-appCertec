<?php

namespace App\Models\Workflow;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Paso dentro de un flujo de aprobación.
 * Cada paso tiene un orden y un rol aprobador.
 */
class WfPaso extends Model
{
    use HasFactory;
    protected $table = 'wf_pasos';

    protected $fillable = [
        'id_definicion',
        'orden',
        'nombre_paso',
        'rol_aprobador',
        'es_opcional',
        'permite_rechazo',
        'requiere_monto',
        'reglas',
        'descripcion_contexto',
        'estado',
    ];

    protected $casts = [
        'es_opcional' => 'boolean',
        'permite_rechazo' => 'boolean',
        'requiere_monto' => 'boolean',
        'reglas' => 'array',
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

    /**
     * Evalúa si las reglas del paso aplican al contexto dado.
     */
    public function evaluarReglas(array $contexto): bool
    {
        if (empty($this->reglas)) {
            return true;
        }

        foreach ($this->reglas as $campo => $valor) {
            if (!$this->evaluarCondicion($campo, $valor, $contexto)) {
                return false;
            }
        }
        return true;
    }

    private function evaluarCondicion(string $campo, $valor, array $contexto): bool
    {
        // Rangos numéricos
        if (str_ends_with($campo, '_min')) {
            $campoBase = str_replace('_min', '', $campo);
            return isset($contexto[$campoBase]) && $contexto[$campoBase] >= $valor;
        }
        if (str_ends_with($campo, '_max')) {
            $campoBase = str_replace('_max', '', $campo);
            return isset($contexto[$campoBase]) && $contexto[$campoBase] <= $valor;
        }

        if (!array_key_exists($campo, $contexto) || $contexto[$campo] === null) {
            return true;
        }

        return $contexto[$campo] == $valor;
    }
}
