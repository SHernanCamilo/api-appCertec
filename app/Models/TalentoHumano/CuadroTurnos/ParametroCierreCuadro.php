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

    public function empresa()
    {
        return $this->belongsTo(\App\Models\Empresa::class, 'id_empresa');
    }

    /**
     * Obtiene el parametro de cierre vigente de una empresa (Opcion B: por empresa, sin global).
     */
    public static function vigente(?int $idEmpresa = null): ?self
    {
        return self::where('activo', true)
            ->when($idEmpresa !== null, fn($q) => $q->where('id_empresa', $idEmpresa))
            ->orderByDesc('created_at')
            ->first();
    }
}
