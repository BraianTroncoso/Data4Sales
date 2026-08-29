<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'system'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    'connections' => [

        /*
        |--------------------------------------------------------------
        | Plano de control
        |--------------------------------------------------------------
        | Registro de tenants, sus API keys y los usuarios del
        | dashboard. Es unico y compartido por diseno: cuando llega un
        | request con una API key todavia no sabemos a que base ir, asi
        | que la resolucion key -> tenant -> conexion tiene que vivir en
        | un lugar que no dependa del tenant.
        */
        'system' => [
            'driver' => 'pgsql',
            'host' => env('DB_SYSTEM_HOST', '127.0.0.1'),
            'port' => env('DB_SYSTEM_PORT', '5432'),
            'database' => env('DB_SYSTEM_DATABASE', 'd4s_system'),
            'username' => env('DB_USERNAME', 'd4s_app'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ],

        /*
        |--------------------------------------------------------------
        | Plano de datos compartido
        |--------------------------------------------------------------
        | Donde viven las ventas de los tenants con estrategia `shared`.
        | El aislamiento aca no lo da la aplicacion: lo da Row-Level
        | Security en Postgres, con el rol restringido d4s_app.
        */
        'shared' => [
            'driver' => 'pgsql',
            'host' => env('DB_SHARED_HOST', '127.0.0.1'),
            'port' => env('DB_SHARED_PORT', '5432'),
            'database' => env('DB_SHARED_DATABASE', 'd4s_shared'),
            'username' => env('DB_USERNAME', 'd4s_app'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ],

        /*
        |--------------------------------------------------------------
        | Plano de datos dedicado (plantilla)
        |--------------------------------------------------------------
        | Los tenants promovidos a base propia usan esta configuracion
        | con el nombre de base sustituido en runtime por
        | TenantConnectionResolver. El esquema es identico al compartido,
        | por eso promover un tenant es mover datos y no transformar
        | estructura.
        |
        | Las credenciales NO se guardan en la tabla de tenants: ahi solo
        | va el nombre logico de la conexion. En produccion esto saldria
        | de un gestor de secretos; aca sale del entorno.
        */
        'dedicated' => [
            'driver' => 'pgsql',
            'host' => env('DB_DEDICATED_HOST', '127.0.0.1'),
            'port' => env('DB_DEDICATED_PORT', '5432'),
            'database' => null, // lo fija el resolver, por tenant
            'username' => env('DB_USERNAME', 'd4s_app'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug(env('APP_NAME', 'laravel'), '_').'_database_'),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
        ],

    ],

];
