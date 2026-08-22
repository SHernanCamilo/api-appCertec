<?php

/**
 * Configuración del módulo Cuadro de Turnos
 *
 * empresas_habilitadas: IDs de empresas que tienen acceso al módulo.
 *   - Si está vacío o no definido → sin filtro (todas las empresas).
 *   - Si tiene valores → solo esas empresas pueden usar el módulo.
 *
 * Se configura desde .env con: CUADRO_TURNOS_EMPRESAS=3,5,7
 */
return [
    'empresas_habilitadas' => array_filter(
        array_map('intval', explode(',', env('CUADRO_TURNOS_EMPRESAS', '')))
    ),
];
