<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Tenancy\TenantConnectionResolver;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\ServiceProvider;

/**
 * Registra las piezas de multi-tenancy.
 *
 * Ambas son singletons por motivos distintos:
 *
 *   TenantConnectionResolver  porque registrar una conexion dedicada muta
 *                             la configuracion; instancias separadas harian
 *                             ese trabajo mas de una vez.
 *
 *   TenantContext             porque ES estado. Tener dos instancias
 *                             significaria dos ideas distintas de que
 *                             tenant esta activo, que es exactamente la
 *                             clase de ambiguedad que no puede existir en
 *                             el codigo que decide quien ve que.
 */
class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantConnectionResolver::class);
        $this->app->singleton(TenantContext::class);
    }
}
