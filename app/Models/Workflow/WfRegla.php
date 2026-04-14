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
        // Rangos numéricos (nivel_min, nivel_max, monto_min, monto_max)
        if (str_ends_with($campo, '_min')) {
            $campoBase = str_replace('_min', '', $campo);
            return isset($contexto[$campoBase]) && $contexto[$campoBase] >= $valor;
        }
        if (str_ends_with($campo, '_max')) {
            $campoBase = str_replace('_max', '', $campo);
            return isset($contexto[$campoBase]) && $contexto[$campoBase] <= $valor;
        }

        // Si el campo no existe en el contexto o es null, ignorar esta condición
        // (permite que reglas con id_grupo funcionen aunque el contexto no tenga grupo)
        if (!array_key_exists($campo, $contexto) || $contexto[$campo] === null) {
            return true;
        }

        // Comparación exacta (prefijo, cobertura, id_grupo, etc.)
        return $contexto[$campo] == $valor;
    }
}
