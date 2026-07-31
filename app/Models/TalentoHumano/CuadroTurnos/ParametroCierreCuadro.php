<?php

namespace App\Models\TalentoHumano\CuadroTurnos;

use Illuminate\Database\Eloquent\Model;

class ParametroCierreCuadro extends Model
{
    protected $table = 'humtal_parametro_cierre_cuadro';

    protected $fillable = [
        'tipo_bloqueo', 'tipo_nomina', 'dia_cierre', 'hora_cierre',
        'aplica_mes_actual', 'id_empresa', 'activo',
    ];

    protected $casts = [
        'dia_cierre'       => 'integer',
        'aplica_mes_actual' => 'boolean',
        'activo'           => 'boolean',
    ];

    public function scopeActivos($query) { return $query->where('activo', true); }

    /**
     * Obtiene el par+ímetro de cierre vigente para una empresa (o global).
     */
    public static function vigente(?int $idEmpresa = null): ?self
    {
        return self::where('activo', true)
            ->where(function ($q) use ($idEmpresa) {
                $q->where('id_empresa', $idEmpresa)
                  ->orWhereNull('id_empresa');
            })
            ->orderByRaw('id_empresa IS NULL ASC') // Prioridad: empresa espec+¡fica > global
            ->first();
    }
}
