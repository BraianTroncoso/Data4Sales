<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Emisor de datos
    |--------------------------------------------------------------------------
    |
    | Configuración del cliente que empuja el dataset contra la API de ingesta.
    |
    | Estas variables se leen ACÁ y no con env() desde el comando. No es una
    | convención estética: `env()` devuelve null cuando la configuración está
    | cacheada, así que un emisor que la lea directo deja de encontrar su
    | credencial apenas alguien corre `config:cache`. El síntoma sería "no hay
    | credencial para [luna]", que apunta al lugar equivocado y manda a buscar
    | un problema de permisos que no existe.
    |
    */

    'emitter' => [

        'base_url' => env('D4S_API_BASE_URL', 'http://localhost:8080'),

        /*
        | Credenciales por tenant, indexadas por slug.
        |
        | El comando resuelve `d4s.emitter.api_keys.{slug}` y cae a la clave
        | genérica si no encuentra una específica. Son las claves deterministas
        | del seed, documentadas en el README: en producción saldrían de un
        | gestor de secretos, igual que las credenciales de base.
        */
        'api_keys' => array_filter([
            'luna' => env('D4S_API_KEY_LUNA'),
            'andes' => env('D4S_API_KEY_ANDES'),
            'mega' => env('D4S_API_KEY_MEGA'),
        ]),

        'default_api_key' => env('D4S_API_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tablero de Horizon
    |--------------------------------------------------------------------------
    |
    | Cerrado por defecto, y la razón no es prudencia genérica: la cola es
    | compartida por todos los tenants, así que el tablero muestra payloads de
    | trabajos de organizaciones distintas en la misma pantalla. No hay ningún
    | usuario de este sistema que tenga derecho a eso — todos pertenecen a un
    | tenant. Además expone reintentar y borrar trabajos.
    |
    | La puerta por defecto de Horizon es `app()->environment('local')`, y el
    | compose fija APP_ENV=local: sin esta configuración el tablero queda
    | abierto y anónimo. Se cierra explícitamente en HorizonServiceProvider.
    |
    | Para mirarlo en una sesión de depuración se activa esta bandera Y hace
    | falta sesión de administrador. Es una concesión consciente del operador,
    | no una pantalla del producto. La vía normal es la CLI dentro del
    | contenedor: `php artisan horizon:status`.
    |
    */

    'horizon' => [
        'dashboard_enabled' => (bool) env('D4S_HORIZON_DASHBOARD', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Límites de la API pública
    |--------------------------------------------------------------------------
    |
    | Peticiones por minuto y por credencial. Se separan ingesta y consulta
    | porque tienen formas de uso distintas: la ingesta manda pocos requests
    | grandes (lotes de cientos de registros) y la consulta manda muchos
    | chicos. Un único límite castigaría a uno de los dos.
    |
    | El límite es por CREDENCIAL y no por IP: los clientes de esta API son
    | pipelines de servidor, y varios tenants pueden salir por la misma IP.
    | Limitar por IP mezclaría a organizaciones que no tienen nada que ver.
    |
    */

    'api' => [
        'rate_limits' => [
            'ingest' => (int) env('D4S_RATE_LIMIT_INGEST', 300),
            'query' => (int) env('D4S_RATE_LIMIT_QUERY', 120),
        ],
    ],

];
