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
];
