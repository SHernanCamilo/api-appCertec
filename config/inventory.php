<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Empresa del módulo de Inventario (Farmacia)
    |--------------------------------------------------------------------------
    | El inventario de farmacia opera sobre una sola empresa. Este valor se usa,
    | entre otros, para acotar el selector de sucursales de órdenes de compra a
    | las sucursales de esta empresa (evita mostrar sucursales de otras empresas).
    */
    'empresa_id' => env('INVENTORY_EMPRESA_ID', 1),

    /*
    |--------------------------------------------------------------------------
    | Activos Fijos: filtro de localizaciones por sucursal del usuario
    |--------------------------------------------------------------------------
    | El buscador de localizaciones (toma de inventario de activos fijos) solo
    | debe mostrar las localizaciones de la sucursal del usuario autenticado.
    |
    | La columna `Sucursal` de la vista `ra.VW_Fixed_DetalleActivos` guarda la
    | ciudad (ej. "Neiva", "Florencia", "Tunja", "Facatativa", "Bogota"...),
    | mientras que la sucursal del usuario (config_ubi_sucursales.nombre) viene
    | como "Sucursal <Ciudad>". Por defecto derivamos la ciudad quitando el
    | prefijo "Sucursal " y comparamos sin tildes / sin distinguir mayúsculas.
    |
    | Casos que no siguen ese patrón se resuelven aquí sin tocar código:
    |
    |  - `sucursal_ciudad`: mapa explícito nombre-de-sucursal => ciudad-en-vista.
    |     Tiene prioridad sobre la derivación automática. La clave se compara
    |     normalizada (sin tildes, minúsculas). Deja el valor en null para que
    |     esa sucursal NO filtre (vea todo).
    |
    |  - `nacional`: nombres de sucursal (normalizados) que representan acceso
    |     nacional y por tanto NO deben filtrar por ciudad (ven todas).
    */
    'activos_fijos' => [
        'filtrar_localizaciones_por_sucursal' => env('ACTIVOS_FIJOS_FILTRAR_POR_SUCURSAL', true),

        'sucursal_ciudad' => [
            // 'sucursal abner lozano' => 'Abner',
            // 'sucursal myriam parra' => 'Materno',
        ],

        'nacional' => [
            'sucursal nacional',
            'nacional',
        ],
    ],
];
