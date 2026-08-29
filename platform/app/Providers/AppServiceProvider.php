<?php

namespace App\Providers;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Passwords\TenantAwarePasswordBrokerManager;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * Reemplaza el gestor de brokers de recuperacion por uno que acota los
         * tokens al tenant.
         *
         * El broker de Laravel busca y valida por email a secas, y el unico de
         * `users` en este sistema es (tenant_id, email): sin esto, un token
         * emitido para el usuario de una organizacion valida contra el usuario
         * de otra que comparte el email. Era la unica puerta del sistema que no
         * distinguia tenants.
         *
         * Se sustituye el gestor entero y no solo el repositorio porque el
         * broker construye su repositorio adentro, sin pasar por el contenedor.
         */
        $this->app->singleton('auth.password', fn ($app) => new TenantAwarePasswordBrokerManager($app));

        $this->app->bind('auth.password.broker', fn ($app) => $app->make('auth.password')->broker());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        /*
         * Desactiva la carga diferida del proveedor de recuperacion de Laravel.
         *
         * Sin esto el binding de register() queda escrito y nunca se usa:
         * PasswordResetServiceProvider es diferido, y `Application::make()`
         * registra el proveedor diferido ANTES de mirar los bindings, pisando
         * el nuestro con el broker de Laravel.
         *
         * Va en boot() y no en register() por orden de arranque: el mapa de
         * diferidos se escribe DESPUES de registrar todos los proveedores
         * (ProviderRepository::load), asi que borrarlo en register() no dura.
         */
        $diferidos = $this->app->getDeferredServices();
        unset($diferidos['auth.password'], $diferidos['auth.password.broker']);
        $this->app->setDeferredServices($diferidos);

        // Los modelos viven en app/Domain/<contexto>/Models, no en app/Models,
        // asi que la convencion por defecto de Laravel no encuentra sus
        // fabricas. Se resuelven por nombre de clase, que es unico en el
        // dominio, en vez de por namespace completo.
        Factory::guessFactoryNamesUsing(
            static fn (string $model) => 'Database\\Factories\\'.class_basename($model).'Factory'
        );

        /*
         * El enlace de recuperacion lleva la organizacion.
         *
         * El de Laravel lleva token y email, que aca no alcanzan para
         * identificar a nadie. Sin el slug, el formulario de reset tendria que
         * adivinar el tenant, y adivinar es justo lo que hacia que el flujo
         * cruzara organizaciones.
         */
        ResetPassword::createUrlUsing(static fn (User $user, string $token) => url(route(
            'password.reset',
            [
                'token' => $token,
                'email' => $user->getEmailForPasswordReset(),
                'tenant' => $user->tenant?->slug,
            ],
            absolute: false,
        )));
    }
}
