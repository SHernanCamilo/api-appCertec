<?php

namespace App\Services\Finance\Anticipos;

use App\Models\AntiConcepto;
use App\Models\AntiRegla;

/**
 * Servicio de Reglas asociadas a un Concepto.
 * Maneja la creación, reemplazo y eliminación de reglas.
 */
class AnticipoReglaService
{
    /**
     * Crea las reglas para un concepto dado.
     */
    public function crearReglas(AntiConcepto $concepto, array $reglas): void
    {
        foreach ($reglas as $regla) {
            $concepto->reglas()->create([
                'descripcion' => $regla['descripcion'],
                'valor_tope'  => $regla['valor_tope'],
                'estado'      => true,
            ]);
        }
    }

    /**
     * Reemplaza todas las reglas de un concepto (delete + insert).
     */
    public function reemplazarReglas(AntiConcepto $concepto, array $reglas): void
    {
        $concepto->reglas()->delete();
        $this->crearReglas($concepto, $reglas);
    }
}
