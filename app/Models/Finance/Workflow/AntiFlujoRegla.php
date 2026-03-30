<?php

namespace App\Models\Finance\Workflow;

use Illuminate\Database\Eloquent\Model;

class AntiFlujoRegla extends Model
{
    protected $table = 'anti_flujo_reglas';

    protected $fillable = [
        'id_flujo', 'prioridad', 'nivel_jerarquico_min', 'nivel_jerarquico_max',
        'prefijo_sucursal', 'monto_min', 'monto_max', 'cobertura', 'estado'
    ];

    protected $casts = [
        'estado' => 'boolean',
        'prioridad' => 'integer',
        'nivel_jerarquico_min' => 'integer',
        'nivel_jerarquico_max' => 'integer',
        'monto_min' => 'decimal:2',
        'monto_max' => 'decimal:2',
    ];

    public function flujo()
    {
        return $this->belongsTo(AntiFlujo::class, 'id_flujo');
    }

    /**
     * Evalúa si esta regla aplica a una solicitud dada.
     */
    public function aplica(int $nivelJerarquico, string $prefijoSucursal, float $monto, string $cobertura): bool
    {
        // Nivel jerárquico
        if ($this->nivel_jerarquico_min !== null && $nivelJerarquico < $this->nivel_jerarquico_min) {
            return false;
        }
        if ($this->nivel_jerarquico_max !== null && $nivelJerarquico > $this->nivel_jerarquico_max) {
            return false;
        }

        // Prefijo sucursal
        if ($this->prefijo_sucursal !== null && $this->prefijo_sucursal !== $prefijoSucursal) {
            return false;
        }

        // Monto
        if ($this->monto_min !== null && $monto < $this->monto_min) {
            return false;
        }
        if ($this->monto_max !== null && $monto > $this->monto_max) {
            return false;
        }

        // Cobertura
        if ($this->cobertura !== null && $this->cobertura !== $cobertura) {
            return false;
        }

        return true;
    }
}
