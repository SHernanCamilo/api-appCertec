<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Graph-Fabric API (Python)
    |--------------------------------------------------------------------------
    |
    | Configuración para la API Python de Graph-Fabric que sirve datos de
    | Microsoft Fabric (DuckDB/Parquet) y actúa como proxy a SQL Server.
    |
    */

    'url' => env('GRAPHQL_URL', 'http://127.0.0.1:8001'),

    'token_admin' => env('TOKEN_ADMIN', ''),

    'api_key' => env('GRAPHQL_API_KEY', ''),

    // Timeout general para consultas de datos (segundos)
    'timeout' => (int) env('GRAPHQL_TIMEOUT', 185),

    // Timeout para catálogo de vistas (debe ser rápido, es solo metadata)
    'catalog_timeout' => (int) env('GRAPHQL_CATALOG_TIMEOUT', 60),

    // Timeout para exports (segundos). Las vistas de 460K filas tardan 1-6 min
    // en el carril "heavy" de Graph-Fabric; 300s las cortaba antes de terminar.
    'export_timeout' => (int) env('GRAPHQL_EXPORT_TIMEOUT', 600),

    // Máximo de filas exportables. Excel corta en 1.048.576 y por encima de
    // ese volumen el export monopoliza un carril de Python varios minutos.
    'max_export_rows' => (int) env('FABRIC_MAX_EXPORT_ROWS', 1000000),

    // Chunk size para exports paginados (filas por request)
    // Graph-Fabric acepta máximo 20000 filas por request (validación FastAPI)
    'export_chunk' => (int) env('FABRIC_EXPORT_CHUNK', 20000),

    // Pausa entre chunks de export (ms) — cede el worker Python
    'export_chunk_pause_ms' => (int) env('FABRIC_EXPORT_CHUNK_PAUSE_MS', 100),

    // TTL de cache para queries repetidas (segundos)
    'query_cache_ttl' => (int) env('FABRIC_QUERY_CACHE_TTL', 30),

    // Email del sistema para requests internos (notificaciones, sync)
    'admin_email' => env('NOTIF_ADMIN_EMAIL', 'sistema@medilaser.com.co'),

];
