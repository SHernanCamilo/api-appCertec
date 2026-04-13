<?php

namespace App\Services\Finance\Anticipos;

use App\Models\Finance\AntiTipo;
use App\Models\Finance\AntiClase;
use App\Models\Finance\AntiModalidad;

/**
 * Servicio de catálogos: Tipos, Clases y Modalidades.
 * Son los nodos de la jerarquía que clasifican un Concepto.
 */
class AnticipoTipoService
{
    public function getTipos(): array
    {
        return AntiTipo::activos()->orderBy('nombre')->get()->toArray();
    }

    public function getClasesPorTipo(int $tipoId): array
    {
        return AntiClase::where('id_tipo', $tipoId)
            ->where('estado', 1)
            ->orderBy('nombre')
            ->get()
            ->toArray();
    }

    public function getModalidadesPorClase(int $claseId): array
    {
        return AntiModalidad::where('id_clase', $claseId)
            ->where('estado', 1)
            ->orderBy('nombre')
            ->get()
            ->toArray();
    }
}
