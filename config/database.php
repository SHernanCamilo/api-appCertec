<?php

use Illuminate\Support\Str;

return [

    'default' => env('DB_CONNECTION', 'mysql'),

    'connections' => [

        'sqlite' => [
            'driver'                  => 'sqlite',
            'url'                     => env('DATABASE_URL'),
            'database'                => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix'                  => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
        ],

        'mysql' => [
            'driver'      => 'mysql',
            'url'         => env('DATABASE_URL'),
            'host'        => env('DB_HOST', '127.0.0.1'),
            'port'        => env('DB_PORT', '3306'),
            'database'    => env('DB_DATABASE', 'forge'),
            'username'    => env('DB_USERNAME', 'forge'),
            'password'    => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset'     => 'utf8mb4',
            'collation'   => 'utf8mb4_unicode_ci',
            'prefix'      => '',
            'prefix_indexes' => true,
            'strict'      => false,
            'engine'      => null,
            'timezone'    => env('DB_TIMEZONE', '-05:00'),
            'options'     => extension_loaded('pdo_mysql') ? array_filter([
                PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'pgsql' => [
            'driver'         => 'pgsql',
            'url'            => env('DATABASE_URL'),
            'host'           => env('DB_HOST', '127.0.0.1'),
            'port'           => env('DB_PORT', '5432'),
            'database'       => env('DB_DATABASE', 'forge'),
            'username'       => env('DB_USERNAME', 'forge'),
            'password'       => env('DB_PASSWORD', ''),
            'charset'        => 'utf8',
            'prefix'         => '',
            'prefix_indexes' => true,
            'search_path'    => 'public',
            'sslmode'        => 'prefer',
        ],

        'sqlsrv' => [
            'driver'         => 'sqlsrv',
            'url'            => env('DATABASE_URL'),
            'host'           => env('DB_HOST', 'localhost'),
            'port'           => env('DB_PORT', '1433'),
            'database'       => env('DB_DATABASE', 'forge'),
            'username'       => env('DB_USERNAME', 'forge'),
            'password'       => env('DB_PASSWORD', ''),
            'charset'        => 'utf8',
            'prefix'         => '',
            'prefix_indexes' => true,
        ],

        // ─────────────────────────────────────────────────────────────────
        // JADE legacy (BD `fichas`) — solo lectura, usada por
        // `php artisan fichas:migrar-datos`. Puede quedar sin configurar en
        // producción una vez completada la migración de Fichas Técnicas.
        // ─────────────────────────────────────────────────────────────────

        'jade_legacy' => [
            'driver'   => 'mysql',
            'host'     => env('JADE_LEGACY_HOST', env('DB_HOST', '127.0.0.1')),
            'port'     => env('JADE_LEGACY_PORT', env('DB_PORT', '3306')),
            'database' => env('JADE_LEGACY_DATABASE', 'fichas'),
            // Si no se definen credenciales propias se reutilizan las de la app,
            // útil cuando la BD legacy vive en el mismo servidor MySQL.
            'username'       => env('JADE_LEGACY_USERNAME', env('DB_USERNAME', 'root')),
            'password'       => env('JADE_LEGACY_PASSWORD', env('DB_PASSWORD', '')),
            'charset'        => 'utf8mb4',
            'collation'      => 'utf8mb4_unicode_ci',
            'prefix'         => '',
            'prefix_indexes' => true,
            'strict'         => false,
            'engine'         => null,
        ],

        // Copia de `jade_legacy` apuntando a un esquema de pruebas, para
        // validar el comando de migración sin tocar la BD legacy real.
        'jade_legacy_test' => [
            'driver'         => 'mysql',
            'host'           => env('DB_HOST', '127.0.0.1'),
            'port'           => env('DB_PORT', '3306'),
            'database'       => env('JADE_LEGACY_TEST_DATABASE', 'fichas_legacy_test'),
            'username'       => env('DB_USERNAME', 'root'),
            'password'       => env('DB_PASSWORD', ''),
            'charset'        => 'utf8mb4',
            'collation'      => 'utf8mb4_unicode_ci',
            'prefix'         => '',
            'prefix_indexes' => true,
            'strict'         => false,
            'engine'         => null,
        ],

        // ─────────────────────────────────────────────────────────────────
        // DIGIPHARMA (Migración de inventario farmacia - solo lectura)
        // BD legacy en 192.168.12.20, usada para migrar datos hacia la VPS.
        // ─────────────────────────────────────────────────────────────────
        'digipharma' => [
            'driver'         => 'mysql',
            'host'           => env('DIGIPHARMA_HOST', '192.168.12.20'),
            'port'           => env('DIGIPHARMA_PORT', '3306'),
            'database'       => env('DIGIPHARMA_DATABASE', 'digipharma'),
            'username'       => env('DIGIPHARMA_USERNAME', 'digipharma_app'),
            'password'       => env('DIGIPHARMA_PASSWORD', 'kD21c2P7wQW9'),
            'charset'        => 'utf8mb4',
            'collation'      => 'utf8mb4_unicode_ci',
            'prefix'         => '',
            'prefix_indexes' => true,
            'strict'         => false,
            'engine'         => null,
        ],

        // ─────────────────────────────────────────────────────────────────
        // ERP INDIGO777 (Órdenes de Compra)
        // ─────────────────────────────────────────────────────────────────
        'sqlsrv_indigo' => [
            'driver'         => 'sqlsrv',
            'host'           => env('MSSQL_PURCHASEORDER_HOST', '192.168.10.9'),
            'port'           => env('MSSQL_PURCHASEORDER_PORT', '1433'),
            'database'       => env('MSSQL_PURCHASEORDER_DB', 'INDIGO777'),
            'username'       => env('MSSQL_PURCHASEORDER_USER', 'Pr_Genesis'),
            'password'       => env('MSSQL_PURCHASEORDER_PASS', 'Genesis2021#'),
            'charset'        => 'utf8',
            'prefix'         => '',
            'prefix_indexes' => true,
        ],

        // ─────────────────────────────────────────────────────────────────
        // Microsoft Fabric / LH_MEDILASER_ANALYTICS
        // Autenticación: Service Principal (OAuth2 AAD)
        // El FabricService obtiene el token AAD y lo inyecta en la conexión
        // ─────────────────────────────────────────────────────────────────
        'fabric' => [
            'driver'                   => 'sqlsrv',
            'host'                     => env('FABRIC_HOST'),
            'port'                     => env('FABRIC_PORT', 1433),
            'database'                 => env('FABRIC_DATABASE'),
            'username'                 => env('FABRIC_CLIENT_ID'),   // Service Principal ID
            'password'                 => env('FABRIC_CLIENT_SECRET'), // SP Secret (usado para obtener token)
            'charset'                  => 'utf8',
            'prefix'                   => '',
            'prefix_indexes'           => true,
            'encrypt'                  => 'yes',
            'trust_server_certificate' => 'false',
            'login_timeout'            => 30,
            // El FabricConnectionService reemplaza username/password por AccessToken en runtime
        ],

    ],

    'migrations' => 'migrations',

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix'  => env('REDIS_PREFIX', Str::slug(env('APP_NAME', 'laravel'), '_') . '_database_'),
        ],

        'default' => [
            'url'      => env('REDIS_URL'),
            'host'     => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port'     => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],

        'cache' => [
            'url'      => env('REDIS_URL'),
            'host'     => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port'     => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
        ],

    ],

];
