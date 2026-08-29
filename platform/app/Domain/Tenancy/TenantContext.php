<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Domain\Tenancy\Database\RowLevelSecurity;
use App\Domain\Tenancy\Models\Tenant;
use Closure;
use Illuminate\Database\DatabaseManager;
use RuntimeException;
use Throwable;

/**
 * El tenant activo durante una unidad de trabajo, y su proyeccion sobre la
 * conexion de base de datos.
 *
 * Su unica responsabilidad real es una linea de SQL: publicar el tenant en
 * la variable de sesion `app.tenant_id`, que es la que leen las politicas de
 * Row-Level Security. Todo lo demas de esta clase existe para garantizar que
 * esa variable nunca quede colgada.
 *
 * Por que importa tanto: en php-fpm cada request estrena conexion, asi que
 * una variable de sesion mal limpiada se pierde sola. Pero un worker de
 * Horizon es un proceso largo que reutiliza la conexion entre trabajos. Si
 * el trabajo del tenant A terminara sin limpiar, el trabajo del tenant B
 * empezaria viendo los datos de A. De ahi que `run()` restaure siempre el
 * valor anterior, incluso cuando el callback lanza una excepcion.
 *
 * Y si alguien se olvidara igual: la politica de RLS lee el contexto con
 * `missing_ok`, asi que sin contexto la consulta devuelve cero filas. El
 * modo de fallo es quedarse sin datos, nunca ver los del vecino.
 */
final class TenantContext
{
    private ?Tenant $tenant = null;

    private ?string $connectionName = null;

    public function __construct(
        private readonly TenantConnectionResolver $resolver,
        private readonly DatabaseManager $database,
    ) {}

    /**
     * Ejecuta el callback con el tenant activo y restaura el estado previo
     * al terminar, pase lo que pase.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(Tenant $tenant, Closure $callback): mixed
    {
        $previousTenant = $this->tenant;

        $this->enter($tenant);

        try {
            return $callback();
        } finally {
            $previousTenant !== null
                ? $this->enter($previousTenant)
                : $this->leave();
        }
    }

    /**
     * Activa un tenant sin alcance acotado.
     *
     * Pensado para el middleware, donde el final de la unidad de trabajo es
     * el final del request. En cualquier otro lugar es preferible `run()`,
     * que no depende de que alguien se acuerde de limpiar.
     */
    public function enter(Tenant $tenant): void
    {
        $connection = $this->resolver->resolve($tenant);

        $this->database->connection($connection)->statement(
            'SELECT set_config(?, ?, false)',
            [RowLevelSecurity::CONTEXT_SETTING, $tenant->id],
        );

        $this->tenant = $tenant;
        $this->connectionName = $connection;
    }

    /**
     * Limpia el contexto. Vaciar la variable equivale a no haberla seteado
     * nunca, porque la politica colapsa la cadena vacia a NULL.
     */
    public function leave(): void
    {
        if ($this->connectionName !== null) {
            try {
                $this->database->connection($this->connectionName)->statement(
                    'SELECT set_config(?, ?, false)',
                    [RowLevelSecurity::CONTEXT_SETTING, ''],
                );
            } catch (Throwable) {
                // Si la conexion ya murio no hay contexto que limpiar: la
                // proxima nace vacia. No vale la pena tirar el request por
                // esto, pero tampoco silenciarlo mas alla de este punto.
            }
        }

        $this->tenant = null;
        $this->connectionName = null;
    }

    public function current(): ?Tenant
    {
        return $this->tenant;
    }

    public function currentOrFail(): Tenant
    {
        return $this->tenant ?? throw new RuntimeException(
            'No hay tenant activo. Toda operacion sobre el plano de datos '
            .'tiene que ocurrir dentro de TenantContext::run().'
        );
    }

    /**
     * Conexion del plano de datos para el tenant activo. Es lo que deben
     * usar los modelos y las consultas del dominio de ventas.
     */
    public function connectionName(): string
    {
        return $this->connectionName ?? throw new RuntimeException(
            'No hay conexion de tenant activa.'
        );
    }

    public function hasTenant(): bool
    {
        return $this->tenant !== null;
    }
}
