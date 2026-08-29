<?php

declare(strict_types=1);

namespace App\Domain\Sales\Concerns;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Ata un modelo del plano de datos al tenant activo.
 *
 * Hace dos cosas, y conviene tener claro que ninguna de las dos es la que
 * garantiza el aislamiento:
 *
 *   1. Resuelve la CONEXION. Es lo unico que separa un tenant compartido de
 *      uno con base propia, y por eso el modelo nunca pregunta cual es cual.
 *   2. Filtra y completa el tenant_id, por ergonomia y para fallar temprano.
 *
 * La garantia real vive en las politicas de Row-Level Security (ver
 * App\Domain\Tenancy\Database\RowLevelSecurity). Este scope se puede
 * desactivar con withoutGlobalScopes() y la consulta seguiria devolviendo
 * solo las filas del tenant activo. Esta capa existe para que el codigo sea
 * comodo de escribir, no para que sea seguro.
 *
 * @property string $tenant_id
 */
trait BelongsToTenant
{
    /**
     * La conexion se resuelve en cada acceso, no se cachea en el modelo:
     * un worker de Horizon procesa trabajos de tenants distintos sobre las
     * mismas clases, y una conexion memorizada seria exactamente el bug que
     * mezcla datos entre tenants.
     */
    public function getConnectionName(): ?string
    {
        return app(TenantContext::class)->connectionName();
    }

    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder): void {
            $model = $builder->getModel();

            $builder->where(
                $model->getTable().'.tenant_id',
                app(TenantContext::class)->currentOrFail()->id,
            );
        });

        // Escribir sin tenant activo es un error de programacion, no un caso
        // a tolerar. Falla aca, con un mensaje claro, en vez de fallar mas
        // abajo con una violacion de RLS que cuesta mas leer.
        static::creating(function (Model $model): void {
            // Se usa el API de atributos en vez de la propiedad magica. La
            // clausura recibe un Model generico, no el modelo concreto, asi
            // que `$model->tenant_id` es magia que ninguna herramienta puede
            // verificar. `getAttribute`/`setAttribute` dicen exactamente lo
            // mismo y son metodos reales.
            if (empty($model->getAttribute('tenant_id'))) {
                $model->setAttribute('tenant_id', app(TenantContext::class)->currentOrFail()->id);
            }
        });
    }
}
