<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Tipos de Tareas Programadas
    |--------------------------------------------------------------------------
    |
    | Define los tipos de tareas que pueden ser programadas en el sistema.
    | Cada tipo debe tener un Job correspondiente en app/Jobs/
    |
    */
    'types' => [
        'sync_activos' => [
            'name' => 'Sincronización de Activos GLPI',
            'description' => 'Sincroniza los activos desde GLPI hacia la base de datos local',
            'job_class' => \App\Jobs\SyncActivosJob::class,
            'max_attempts' => 3,
            'timeout' => 3600, // 1 hora
            'parameters' => [
                'empresa_id' => 'integer|nullable',
                'force_full_sync' => 'boolean',
            ],
        ],
        'cierre_automatico' => [
            'name' => 'Cierre Automático de Inventario',
            'description' => 'Ejecuta el cierre automático del inventario de obsolescencia',
            'job_class' => \App\Jobs\CierreAutomaticoJob::class,
            'max_attempts' => 2,
            'timeout' => 1800, // 30 minutos
            'parameters' => [
                'empresa_id' => 'integer|required',
                'periodo' => 'string|required',
            ],
        ],
        'mantenimiento_db' => [
            'name' => 'Mantenimiento de Base de Datos',
            'description' => 'Ejecuta tareas de mantenimiento y limpieza de la base de datos',
            'job_class' => \App\Jobs\MantenimientoDbJob::class,
            'max_attempts' => 1,
            'timeout' => 600, // 10 minutos
            'parameters' => [
                'clean_logs' => 'boolean',
                'optimize_tables' => 'boolean',
            ],
        ],
        'envio_reportes' => [
            'name' => 'Envío de Reportes',
            'description' => 'Genera y envía reportes programados por correo',
            'job_class' => \App\Jobs\EnvioReportesJob::class,
            'max_attempts' => 2,
            'timeout' => 900, // 15 minutos
            'parameters' => [
                'report_type' => 'string|required',
                'recipients' => 'array|required',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Configuración de Queue
    |--------------------------------------------------------------------------
    */
    'queue' => [
        'default' => 'scheduled-tasks',
        'connection' => env('QUEUE_CONNECTION', 'database'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Configuración de Reintentos
    |--------------------------------------------------------------------------
    */
    'retry' => [
        'default_max_attempts' => 3,
        'backoff_seconds' => [60, 300, 900], // 1 min, 5 min, 15 min
    ],

    /*
    |--------------------------------------------------------------------------
    | Configuración de Limpieza
    |--------------------------------------------------------------------------
    */
    'cleanup' => [
        'keep_completed_days' => 30, // Mantener tareas completadas por 30 días
        'keep_failed_days' => 90, // Mantener tareas fallidas por 90 días
    ],
];
