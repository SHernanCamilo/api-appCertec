<?php

namespace App\Services\Finance;

use App\Services\Finance\Anticipos\AnticipoTipoService;
use App\Services\Finance\Anticipos\AnticipoConceptoService;
use App\Services\Finance\Anticipos\AnticipoReglaService;

/**
 * Servicio padre de Anticipos.
 * Punto de entrada único que expone los servicios hijos al controller.
 *
 * Jerarquía de dominio:
 *   AntiTipo → AntiClase → AntiModalidad   (catálogos)  → AnticipoTipoService
 *   AntiConcepto                           (negocio)    → AnticipoConceptoService
 *   AntiRegla                              (detalle)    → AnticipoReglaService
 */

class AnticipoService
{
    public function __construct(
        public readonly AnticipoTipoService    $tipos,
        public readonly AnticipoConceptoService $conceptos,
        public readonly AnticipoReglaService   $reglas,
    ) {}
}
