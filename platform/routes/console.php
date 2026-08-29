<?php

declare(strict_types=1);

use App\Domain\Analytics\Jobs\RecomputeDirtyAggregates;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Tareas programadas
|--------------------------------------------------------------------------
*/

/**
 * Red de seguridad del recómputo de agregados.
 *
 * El camino normal es que la ingesta encole este trabajo al terminar un
 * lote. Esta corrida periódica existe para los casos en que ese camino
 * falla: un worker que murió justo después de marcar los días, un job que
 * agotó sus reintentos, un despliegue que vació la cola.
 *
 * Sin esto, un día sucio que se pierde queda mal para siempre y nadie se
 * entera. Con esto, el peor caso es que el dashboard esté desactualizado
 * cinco minutos.
 */
Schedule::call(function (): void {
    Tenant::query()
        ->where('is_active', true)
        ->each(fn (Tenant $tenant) => RecomputeDirtyAggregates::dispatch($tenant->id));
})->everyFiveMinutes()->name('recompute-dirty-aggregates')->withoutOverlapping();
