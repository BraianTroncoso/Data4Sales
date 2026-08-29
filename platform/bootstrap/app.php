<?php

use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\BindTenantContext;
use App\Http\Middleware\EnsureRoleCan;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // La API publica vive en su propio archivo de rutas y su propio
        // prefijo. No es organizacion cosmetica: la API y el dashboard
        // tienen amenazas, autenticacion y ritmo de cambio distintos, y
        // mantenerlos separados es lo que permite que separarlos en dos
        // servicios manana sea un movimiento acotado.
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // La API no usa sesiones ni cookies: es autenticacion por credencial
        // en cada request. Sin sesion no hay CSRF que atacar.
        $middleware->alias([
            'api-key' => AuthenticateApiKey::class,

            // Activa el contexto de tenant del usuario logueado. Es el
            // equivalente de api-key para el dashboard: resuelve la conexión
            // y publica app.tenant_id para las políticas de RLS.
            'tenant' => BindTenantContext::class,

            // Control de acceso por capacidad. Se declara `can.role:statistics`
            // y no `role:analyst` porque la capacidad es lo estable: los roles
            // que la tienen pueden cambiar.
            'can.role' => EnsureRoleCan::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        /*
         * La API responde errores en JSON, siempre, incluso cuando el
         * cliente no manda Accept. Un pipeline de ingesta que recibe HTML
         * donde esperaba JSON falla de la forma mas dificil de diagnosticar.
         */
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson()
        );
    })->create();
