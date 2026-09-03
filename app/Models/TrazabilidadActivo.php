<?php

namespace App\Models;

use App\Models\Inventory\InvTrazActivo;

/**
 * Alias de compatibilidad de {@see \App\Models\Inventory\InvTrazActivo}.
 *
 * Históricamente existían dos modelos apuntando a la misma tabla
 * `inv_traz_activo` con `$fillable`, scopes y relaciones divergentes, lo que
 * provocaba que el cálculo "valor anterior → valor nuevo" se perdiera según
 * qué modelo se usara para leer.
 *
 * Ahora hay una sola fuente de verdad: `InvTrazActivo`. Esta clase solo se
 * mantiene para no romper referencias existentes (`App\Models\TrazabilidadActivo`)
 * y hereda todo el comportamiento consolidado.
 *
 * @deprecated Usar App\Models\Inventory\InvTrazActivo directamente.
 */
class TrazabilidadActivo extends InvTrazActivo
{
}
