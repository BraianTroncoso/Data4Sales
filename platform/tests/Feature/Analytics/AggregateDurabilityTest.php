<?php

declare(strict_types=1);

use App\Domain\Analytics\Actions\RecomputeCustomerMetrics;
use App\Domain\Analytics\Jobs\RecomputeDirtyAggregates;
use App\Domain\Analytics\Models\CustomerMetric;
use App\Domain\Analytics\Models\DailySalesFact;
use App\Domain\Sales\Models\Customer;
use App\Domain\Tenancy\Database\RowLevelSecurity;
use App\Domain\Tenancy\Models\ApiKey;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Que pasa con los agregados cuando el recomputo no llega al final.
 *
 * La pregunta que contestan estos tests no es si el numero se calcula bien
 * (eso lo cubre LateArrivalTest), sino si el sistema SABE que todavia no lo
 * calculo. Un agregado viejo se corrige solo en la proxima vuelta; un
 * agregado viejo marcado como fresco no se corrige nunca.
 */
beforeEach(function () {
    $this->tenant = Tenant::factory()->create([
        'slug' => 'agg-'.Str::random(6),
        'timezone' => 'America/Argentina/Buenos_Aires',
        'reporting_currency' => 'ARS',
    ]);

    [, $this->secret] = ApiKey::issue($this->tenant, 'test');
});

function ingestarParaAgregados(string $secret, string $orderId, string $fecha, string $total = '1000.00'): void
{
    test()->postJson('/api/v1/ingest/orders', [
        'source' => 'daily_stream',
        'records' => [[
            'order_id' => $orderId,
            'status' => 'delivered',
            'currency' => 'ARS',
            'total' => $total,
            'placed_at' => "{$fecha}T12:00:00-03:00",
            'updated_at' => "{$fecha}T12:00:00-03:00",
            'customer' => ['customer_id' => 'CUS-1', 'name' => 'María', 'email' => 'maria@example.test'],
            'items' => [['product_id' => 'P-1', 'name' => 'Remera', 'quantity' => 1, 'unit_price' => $total]],
        ]],
    ], ['Authorization' => "Bearer {$secret}"])->assertStatus(202);
}

function enTenantAgregados(Tenant $tenant, Closure $callback): mixed
{
    return app(TenantContext::class)->run($tenant, $callback);
}

/**
 * Otra conexion PDO contra el mismo esquema, para poder mirar lo que ya esta
 * commiteado mientras el trabajo todavia esta a mitad de camino.
 */
function conexionRivalAgregados(Tenant $tenant): Connection
{
    $nombre = app(TenantContext::class)->connectionName();

    config(['database.connections.rival_agregados' => config("database.connections.{$nombre}")]);
    DB::purge('rival_agregados');

    $rival = DB::connection('rival_agregados');

    assert($rival instanceof Connection);

    $rival->statement('SELECT set_config(?, ?, false)', [RowLevelSecurity::CONTEXT_SETTING, $tenant->id]);

    return $rival;
}

it('no da el día por procesado antes de haberlo recalculado', function () {
    ingestarParaAgregados($this->secret, 'ORD-1', '2026-03-01');

    $pendienteDurante = enTenantAgregados($this->tenant, function () {
        $conexion = DB::connection(app(TenantContext::class)->connectionName());
        $tenantId = $this->tenant->id;

        // El día vuelve a ensuciarse, como cuando llega una orden vieja.
        $conexion->statement(
            'INSERT INTO aggregate_dirty_days (tenant_id, date, reason, created_at) VALUES (?,?,?,NOW())',
            [$tenantId, '2026-03-01', 'late_arrival'],
        );

        $rival = conexionRivalAgregados($this->tenant);
        $yaMiro = false;
        $pendiente = null;

        // En medio del recómputo, otro proceso mira el estado COMMITEADO del
        // día. Si ya figura procesado, un kill -9 en este punto lo dejaría
        // marcado sin haberse recalculado nunca, y `freshness()` no lo
        // reportaría: el dashboard mostraría números viejos afirmando estar
        // al día.
        $conexion->beforeExecuting(function (string $query) use (&$yaMiro, &$pendiente, $rival, $tenantId) {
            if ($yaMiro || preg_match('/delete from "?daily_sales_facts/i', $query) !== 1) {
                return;
            }

            $yaMiro = true;

            $pendiente = (int) $rival->selectOne(
                'SELECT COUNT(*) AS total FROM aggregate_dirty_days WHERE tenant_id = ? AND date = ? AND processed_at IS NULL',
                [$tenantId, '2026-03-01'],
            )->total;
        });

        RecomputeDirtyAggregates::dispatch($tenantId);

        return $pendiente;
    });

    expect($pendienteDurante)->toBe(1);

    // Y una vez que terminó, el día queda marcado y el agregado recalculado.
    enTenantAgregados($this->tenant, function () {
        $sinProcesar = DB::connection(app(TenantContext::class)->connectionName())->selectOne(
            'SELECT COUNT(*) AS total FROM aggregate_dirty_days WHERE tenant_id = ? AND processed_at IS NULL',
            [$this->tenant->id],
        );

        expect((int) $sinProcesar->total)->toBe(0)
            ->and(DailySalesFact::query()->where('date', '2026-03-01')->value('orders_count'))->toBe(1);
    });
});

it('recalcula las métricas de un conjunto de clientes más grande que el límite de parámetros', function () {
    ingestarParaAgregados($this->secret, 'ORD-1', '2026-03-01');

    enTenantAgregados($this->tenant, function () {
        $real = Customer::query()->where('external_id', 'CUS-1')->firstOrFail();

        // PostgreSQL admite 65.535 parámetros por sentencia. Con un marcador
        // por cliente, un backfill de un tenant grande ni siquiera llega a
        // ejecutar la consulta: explota justo cuando más datos hay que
        // arreglar.
        $ids = array_map(
            static fn (int $i) => sprintf('%08x-0000-4000-8000-%012x', $i, $i),
            range(1, 70_000),
        );

        $ids[] = $real->id;

        app(RecomputeCustomerMetrics::class)->execute($ids);

        expect(CustomerMetric::query()->where('customer_id', $real->id)->value('orders_count'))->toBe(1);
    });
});
