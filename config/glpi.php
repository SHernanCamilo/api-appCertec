<?php

return [
    /*
    |--------------------------------------------------------------------------
    | GLPI Configuration
    |--------------------------------------------------------------------------
    |
    | Configuración para la conexión con GLPI API REST
    |
    */

    'base_url' => env('GLPI_BASE_URL', 'http://localhost/glpi/apirest.php'),
    
    'user_token' => env('GLPI_USER_TOKEN', ''),
    
    'app_token' => env('GLPI_APP_TOKEN', ''),
    
    'timeout' => env('GLPI_TIMEOUT', 30),
    
    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Configuración de cache para tokens de sesión
    |
    */
    
    'cache' => [
        'session_duration' => env('GLPI_SESSION_DURATION', 480), // 8 horas en minutos
        'prefix' => 'glpi_',
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Default Parameters
    |--------------------------------------------------------------------------
    |
    | Parámetros por defecto para las consultas a GLPI
    |
    */
    
    'defaults' => [
        'expand_dropdowns' => true,
        'get_hateoas' => true,
        'range' => '0-50',
        'with_devices' => true,
        'with_softwares' => true,
        'with_connections' => true,
        'with_networkports' => true,
        'with_infocoms' => true,
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Item Types
    |--------------------------------------------------------------------------
    |
    | Tipos de elementos disponibles en GLPI
    |
    */
    
    'item_types' => [
        'Computer' => 'Computadora',
        'Monitor' => 'Monitor',
        'NetworkEquipment' => 'Equipo de Red',
        'Peripheral' => 'Periférico',
        'Phone' => 'Teléfono',
        'Printer' => 'Impresora',
        'Software' => 'Software',
        'User' => 'Usuario',
        'Group' => 'Grupo',
        'Entity' => 'Entidad',
        'Location' => 'Ubicación',
        'Manufacturer' => 'Fabricante',
        'Supplier' => 'Proveedor',
        'Contact' => 'Contacto',
        'Contract' => 'Contrato',
        'Document' => 'Documento',
        'Ticket' => 'Ticket',
        'Problem' => 'Problema',
        'Change' => 'Cambio',
        'Project' => 'Proyecto',
        'Budget' => 'Presupuesto',
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Computer Fields
    |--------------------------------------------------------------------------
    |
    | Campos disponibles para computadoras
    |
    */
    
    'computer_fields' => [
        1 => 'Nombre',
        2 => 'ID',
        3 => 'Ubicación',
        4 => 'Tipo',
        5 => 'Número de serie',
        6 => 'Número de inventario',
        7 => 'Fecha de compra',
        8 => 'Fecha de puesta en servicio',
        9 => 'Fecha de garantía',
        10 => 'Comentarios',
        19 => 'Fecha de última actualización',
        23 => 'Fabricante',
        31 => 'Estado',
        40 => 'Modelo',
        45 => 'Sistema Operativo',
        46 => 'Versión del SO',
        47 => 'Service Pack del SO',
        70 => 'Usuario',
        71 => 'Grupo',
        121 => 'Fecha de creación',
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Search Operators
    |--------------------------------------------------------------------------
    |
    | Operadores de búsqueda disponibles
    |
    */
    
    'search_operators' => [
        'contains' => 'Contiene',
        'equals' => 'Igual a',
        'notequals' => 'Diferente de',
        'lessthan' => 'Menor que',
        'morethan' => 'Mayor que',
        'under' => 'Bajo',
        'notunder' => 'No bajo',
        'searchopt' => 'Opción de búsqueda',
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Device Types
    |--------------------------------------------------------------------------
    |
    | Tipos de dispositivos que pueden estar asociados a una computadora
    |
    */
    
    'device_types' => [
        'Item_DeviceMotherboard' => 'Placa Madre',
        'Item_DeviceProcessor' => 'Procesador',
        'Item_DeviceMemory' => 'Memoria RAM',
        'Item_DeviceHardDrive' => 'Disco Duro',
        'Item_DeviceNetworkCard' => 'Tarjeta de Red',
        'Item_DeviceSoundCard' => 'Tarjeta de Sonido',
        'Item_DeviceGraphicCard' => 'Tarjeta Gráfica',
        'Item_DevicePowerSupply' => 'Fuente de Poder',
        'Item_DeviceControl' => 'Controlador',
        'Item_DeviceDrive' => 'Unidad',
        'Item_DevicePci' => 'Tarjeta PCI',
        'Item_DeviceCase' => 'Carcasa',
        'Item_DeviceGeneric' => 'Dispositivo Genérico',
    ],

    /*
    |--------------------------------------------------------------------------
    | Tablero TIC — Nivel 1 Nacional
    |--------------------------------------------------------------------------
    |
    | Tickets abiertos del grupo técnico 29 (Nivel 1 Nacional) y alerta ANS
    | dos horas antes del vencimiento de time_to_resolve.
    |
    */
    'web_url' => env('GLPI_WEB_URL'),

    'tic_tablero' => [
        'grupo_id' => (int) env('GLPI_TIC_GRUPO_ID', 29),
        'alerta_horas' => (int) env('GLPI_TIC_ALERTA_HORAS', 2),
        'cache_segundos' => (int) env('GLPI_TIC_CACHE_SEGUNDOS', 60),
    ],
];