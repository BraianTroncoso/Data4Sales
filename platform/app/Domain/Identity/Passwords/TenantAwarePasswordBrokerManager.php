<?php

declare(strict_types=1);

namespace App\Domain\Identity\Passwords;

use Illuminate\Auth\Passwords\PasswordBrokerManager;
use Illuminate\Support\Str;

/**
 * Sustituye el repositorio de tokens por el que conoce el tenant.
 *
 * Es el unico gancho que ofrece Laravel para cambiarlo: el broker construye su
 * repositorio adentro, sin pasar por el contenedor.
 */
class TenantAwarePasswordBrokerManager extends PasswordBrokerManager
{
    protected function createTokenRepository(array $config)
    {
        $key = $this->app['config']['app.key'];

        if (Str::startsWith($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }

        $connection = $config['connection'] ?? null;

        return new TenantAwareTokenRepository(
            $this->app['db']->connection($connection),
            $this->app['hash'],
            $config['table'],
            $key,
            $config['expire'],
            $config['throttle'] ?? 0
        );
    }
}
