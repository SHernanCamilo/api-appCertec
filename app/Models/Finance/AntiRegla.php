<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Model;

class AntiRegla extends Model
{
    protected $table = 'anti_reglas';

    /** nivel_jerarquico = 0 → aplica a todos los niveles */
    const NIVEL_TODOS       = 0;
    const NIVEL_ESTRATEGICO = 1;
    const NIVEL_TACTICO     = 2;
    const NIVEL_OPERATIVO   = 3;

    protected $fillable = [
        'id_concepto',
        'nivel_jerarquico',
        'descripcion',
        'valor_tope',
        'estado',
    ];

    protected $casts = [
        'estado'           => 'boolean',
        'valor_tope'       => 'decimal:2',
        'nivel_jerarquico' => 'integer',
    ];

    public function concepto()
    {
        return $this->belongsTo(AntiConcepto::class, 'id_concepto');
    }

    public function scopeActivos($query)
    {
        return $query->where('estado', 1);
    }

    /**
     * Scope: reglas que aplican a un nivel específico (incluye nivel 0 = todos).
     */
    public function scopeParaNivel($query, int $nivel)
    {
        return $query->where(function ($q) use ($nivel) {
            $q->where('nivel_jerarquico', $nivel)
              ->orWhere('nivel_jerarquico', self::NIVEL_TODOS);
        });
    }
}
