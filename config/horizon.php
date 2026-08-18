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
        // NOTA: Este bloque se usa como "template" para environments que no
        // definen supervisors explícitos. En producción usamos los del bloque
        // 'environments.production', así que este default es solo para entornos
        // que no configuren sus propios workers.
        'supervisor-1' => [
            'connection' => 'redis',
            'queue' => ['default'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
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
                'maxProcesses' => 2,   // Reducido de 3 a 2 — VPS tiene RAM limitada
                'maxTime' => 3600,
                'maxJobs' => 1000,
                'memory' => 256,
                'tries' => 3,
                'timeout' => 120,
                'nice' => 0,
            ],

            // Workers DEDICADOS para exports Excel/CSV (aislados, no bloquean la API)
            //
            // 3 procesos: Graph-Fabric implementa "carriles" (bulkhead) y atiende
            // hasta 15 exports en paralelo — con 1 worker PHP los jobs se
            // serializaban aunque Python tuviera capacidad libre.
            //
            // RAM: el writer es streaming (OpenSpout + CSV a disco), ~5-100 MB por
            // export. 3 × 100 MB = 300 MB peor caso, holgado para la VPS.
            'export-workers' => [
                'connection' => 'redis',
                'queue' => ['exports'],
                'balance' => 'simple',
                'minProcesses' => 1,
                'maxProcesses' => 3,
                'maxTime' => 3600,
                'maxJobs' => 30,
                'memory' => 768,
                'tries' => 1,         // No reintentar: una vista pesada no será más rápida al segundo intento
                'timeout' => 960,     // 16 min — margen sobre el timeout del job (900s)
                'nice' => 10,         // Prioridad baja (no afectar API)
            ],

            // Workers para sincronización (Indigo, GLPI, snapshots, etc.)
            'sync-workers' => [
                'connection' => 'redis',
                'queue' => ['sync', 'snapshots'],
                'balance' => 'simple',
                'minProcesses' => 1,
                'maxProcesses' => 1,  // Reducido de 2+2 a 1 — liberar RAM
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
                'queue' => ['default', 'sync', 'notifications', 'snapshots'],
                'balance' => 'auto',
                'minProcesses' => 1,
                'maxProcesses' => 2,
                'maxTime' => 3600,
                'maxJobs' => 500,
                'memory' => 512,
                'tries' => 3,
                'timeout' => 300,
                'nice' => 0,
            ],

            'export-workers' => [
                'connection' => 'redis',
                'queue' => ['exports'],
                'balance' => 'simple',
                'minProcesses' => 1,
                'maxProcesses' => 2,
                'maxTime' => 3600,
                'maxJobs' => 30,
                'memory' => 768,
                'tries' => 1,
                'timeout' => 960,
                'nice' => 10,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Horizon Trimming
    |--------------------------------------------------------------------------
    */

    'trim' => [
        'recent' => 30,           // 30 min (era 60 — reduce memoria Redis)
        'pending' => 30,
        'completed' => 30,
        'recent_failed' => 4320,  // Jobs fallidos 3 días (era 7)
        'failed' => 4320,
        'monitored' => 4320,
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
