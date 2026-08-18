<?php

namespace App\Models\TalentoHumano\CuadroTurnos;

use Illuminate\Database\Eloquent\Model;

class CtConcepto extends Model
{
    protected $table = 'humtal_ct_conceptos';

    const TIPO_DEVENGADO = 'devengado';
    const TIPO_DEDUCIDO  = 'deducido';

    protected $fillable = [
        'codigo',
        'nombre',
        'tipo_concepto',
        'formula',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopeActivos($query)
    {
        return $query->where('activo', true);
    }

    public function scopeDevengados($query)
    {
        return $query->where('tipo_concepto', self::TIPO_DEVENGADO);
    }

    public function scopeDeducidos($query)
    {
        return $query->where('tipo_concepto', self::TIPO_DEDUCIDO);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Extrae las variables usadas en la fórmula.
     * Ej: "[Horas Nocturnas] * [Valor Hora] * 0.35" → ['Horas Nocturnas', 'Valor Hora']
     */
    public function getVariables(): array
    {
        preg_match_all('/\[([^\]]+)\]/', $this->formula, $matches);
        return array_unique($matches[1] ?? []);
    }

    /**
     * Lista de todas las variables disponibles para fórmulas.
     */
    public static function variablesDisponibles(): array
    {
        return [
            'Horas Normales',
            'Horas Nocturnas',
            'Horas Festivas',
            'Horas Festivas Nocturnas',
            'Horas Extra Diurnas',
            'Horas Extra Nocturnas',
            'Horas Extra Festivas',
            'Horas Extra Festivas Nocturnas',
            'Dias Trabajados',
            'Dias Descanso',
            'Dias Festivos',
            'Valor Hora',
            'Sueldo Base',
        ];
    }
}
