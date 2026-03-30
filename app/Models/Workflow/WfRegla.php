<?php

namespace App\Models\Workflow;

use Illuminate\Database\Eloquent\Model;

/**
 * Reglas de asignación de flujo.
 * Determina qué flujo se aplica según condiciones parametrizables.
 */
class WfRegla extends Model
{
    protected $table = 'wf_reglas';

    protected $fillable = [
        'id_definicion',
        'prioridad',
        'condiciones',
        'estado',
    ];

    protected $casts = [
        'condiciones' => 'array',
        'estado' => 'boolean',
        'prioridad' => 'integer',
    ];

    public function definicion()
    {
        return $this->belongsTo(WfDefinicion::class, 'id_definicion');
    }

    public function scopeActivos($query)
    {
        return $query->where('estado', true);
    }

    public function scopeOrdenadas($query)
    {
        return $query->orderBy('prioridad');
    }

    /**
     * Evalúa si esta regla aplica al contexto dado.
     */
    public function evaluar(array $contexto): bool
    {
        foreach ($this->condiciones as $campo => $valor) {
            if (!$this->evaluarCondicion($campo, $valor, $contexto)) {
                return false;
            }
        }
        return true;
    }

    private function evaluarCondicion(string $campo, $valor, array $contexto): bool
    {
        // Si el campo no existe en el contexto, la condición no aplica
        if (!isset($contexto[$campo])) {
            return false;
        }

        $valorContexto = $contexto[$campo];

        // Rangos numéricos (nivel_min, nivel_max, monto_min, monto_max)
        if (str_ends_with($campo, '_min')) {
            return $valorContexto >= $valor;
        }
        if (str_ends_with($campo, '_max')) {
            return $valorContexto <= $valor;
        }

        // Comparación exacta (prefijo, cobertura, etc.)
        return $valorContexto == $valor;
    }
}
