<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    */

    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    */

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    */

    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    */

    'prefix' => env('HORIZON_PREFIX', Str::slug(env('APP_NAME', 'laravel'), '_') . '_horizon:'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Configuración multi-worker para JadeOne:
    |
    | - "default-workers": Jobs rápidos (notificaciones, auditoría, emails)
    | - "export-workers":  Exports Excel/CSV (pesados, aislados)
    | - "sync-workers":    Sincronización de datos (GLPI, Indigo, etc.)
    |
    | Esto permite que un export de 441K filas NO bloquee las notificaciones
    | ni las consultas OData. Cada grupo tiene sus propios workers dedicados.
    |
    */

    'defaults' => [
        'supervisor-1' => [
            'connection' => 'redis',
            'queue' => ['default', 'notifications'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 3,
            'minProcesses' => 1,
            'maxTime' => 3600,
            'maxJobs' => 500,
            'memory' => 256,
            'tries' => 3,
            'timeout' => 120,
            'nice' => 0,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Worker Configuration — Producción
    |--------------------------------------------------------------------------
    */

    'environments' => [
        'production' => [
            // Workers para jobs rápidos (notificaciones, auditoría, emails)
            'default-workers' => [
                'connection' => 'redis',
                'queue' => ['default', 'notifications'],
                'balance' => 'auto',
                'autoScalingStrategy' => 'time',
                'minProcesses' => 1,
                'maxProcesses' => 3,
                'maxTime' => 3600,
                'maxJobs' => 1000,
                'memory' => 256,
                'tries' => 3,
                'timeout' => 120,
                'nice' => 0,
            ],

            // Workers DEDICADOS para exports Excel/CSV (aislados, no bloquean la API)
            // ⚠️ MAX 1 proceso para no saturar a Python ni agotar RAM.
            // Con los fixes de Python (streaming a disco) el pico es ~100 MB por export.
            'export-workers' => [
                'connection' => 'redis',
                'queue' => ['exports'],
                'balance' => 'simple',
                'minProcesses' => 1,
                'maxProcesses' => 1,  // 1 export a la vez — evita represamiento
                'maxTime' => 3600,
                'maxJobs' => 30,
                'memory' => 768,
                'tries' => 1,         // No reintentar automáticamente (el job tiene su propio retry)
                'timeout' => 600,     // 10 min max — coherente con job timeout de 300s + margen
                'nice' => 10,         // Prioridad baja (no afectar API)
            ],

            // Workers para refrescar snapshots de OData en background.
            // Patrón stale-while-revalidate: Excel recibe el snapshot actual al
            // instante y este worker regenera el archivo sin que nadie espere.
            'snapshot-workers' => [
                'connection' => 'redis',
                'queue' => ['snapshots'],
                'balance' => 'auto',
                'autoScalingStrategy' => 'size',
                'minProcesses' => 1,
                'maxProcesses' => 2,
                'maxTime' => 3600,
                'maxJobs' => 50,
                'memory' => 512,
                'tries' => 1,
                'timeout' => 700, // > timeout del job (600s)
                'nice' => 12,     // Prioridad baja: nunca por encima de la API
            ],

            // Workers para sincronización (Indigo, GLPI, etc.)
            'sync-workers' => [
                'connection' => 'redis',
                'queue' => ['sync'],
                'balance' => 'simple',
                'minProcesses' => 1,
                'maxProcesses' => 2,
                'maxTime' => 3600,
                'maxJobs' => 100,
                'memory' => 512,
                'tries' => 2,
                'timeout' => 600,
                'nice' => 15,
            ],
        ],

        'local' => [
            'default-workers' => [
                'connection' => 'redis',
                'queue' => ['default', 'sync', 'notifications'],
                'balance' => 'auto',
                'minProcesses' => 1,
                'maxProcesses' => 3,
                'maxTime' => 3600,
                'maxJobs' => 500,
                'memory' => 512,
                'tries' => 3,
                'timeout' => 300,
                'nice' => 0,
            ],

            // Workers DEDICADOS para exports (mismo límite que production)
            'export-workers' => [
                'connection' => 'redis',
                'queue' => ['exports'],
                'balance' => 'simple',
                'minProcesses' => 1,
                'maxProcesses' => 1,
                'maxTime' => 3600,
                'maxJobs' => 30,
                'memory' => 768,
                'tries' => 1,
                'timeout' => 600,
                'nice' => 10,
            ],

            // Workers para refrescar snapshots de OData en background
            'snapshot-workers' => [
                'connection' => 'redis',
                'queue' => ['snapshots'],
                'balance' => 'auto',
                'autoScalingStrategy' => 'size',
                'minProcesses' => 1,
                'maxProcesses' => 1,
                'maxTime' => 3600,
                'maxJobs' => 50,
                'memory' => 512,
                'tries' => 1,
                'timeout' => 700,
                'nice' => 12,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Horizon Trimming
    |--------------------------------------------------------------------------
    */

    'trim' => [
        'recent' => 60,       // Mantener jobs recientes 60 min
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080, // Jobs fallidos 7 días
        'failed' => 10080,
        'monitored' => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    */

    'silenced' => [
        // Jobs que no queremos ver en el dashboard (muy frecuentes)
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    */

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    */

    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    */

    'memory_limit' => 128,
];
