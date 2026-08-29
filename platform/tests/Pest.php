<?php

declare(strict_types=1);

use App\Domain\Tenancy\Database\RowLevelSecurity;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Caso base
|--------------------------------------------------------------------------
|
| Deliberadamente NO se usa RefreshDatabase. El sistema tiene dos planos con
| conexiones distintas y juegos de migraciones distintos, y RefreshDatabase
| solo entiende de una conexion. El esquema se crea una vez con `d4s:install`
| antes de correr la suite, y cada test limpia lo que ensucio.
|
| Tampoco se usan transacciones envolventes: varios tests verifican que el
| motor RECHACE una escritura, y en PostgreSQL un error aborta la
| transaccion en curso, lo que volveria fragil el aislamiento entre tests.
|
*/

pest()->extend(TestCase::class)
    /*
     * Sin Vite. Los tests de backend prueban rutas, permisos y props: que el
     * frontend esté compilado o no es irrelevante para eso, y hacerlos
     * depender del manifiesto los rompería cada vez que alguien toca el
     * JavaScript sin correr el build.
     */
    ->beforeEach(fn () => test()->withoutVite())
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Limpieza
|--------------------------------------------------------------------------
|
| Vaciar el contexto de tenant es lo primero: un contexto colgado haria que
| el TRUNCATE siguiente corriera bajo una politica de RLS que no corresponde,
| y el sintoma seria un test que falla por razones que no tienen nada que ver
| con lo que estaba probando.
|
*/

const DATA_PLANE_TABLES = [
    'order_status_history',
    'order_items',
    'orders',
    'products',
    'customers',
    'ingestion_rejects',
    'raw_events',
    'ingestion_batches',
    'daily_sales_facts',
    'customer_metrics',
    'aggregate_dirty_days',
];

pest()->afterEach(function () {
    DB::connection('shared')->statement(
        'SELECT set_config(?, ?, false)',
        [RowLevelSecurity::CONTEXT_SETTING, ''],
    );

    DB::connection('shared')->statement(
        'TRUNCATE '.implode(', ', DATA_PLANE_TABLES).' CASCADE'
    );

    DB::connection('system')->statement('TRUNCATE tenants CASCADE');
})->in('Feature');
