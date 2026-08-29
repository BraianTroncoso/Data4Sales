<?php

declare(strict_types=1);

use App\Domain\Analytics\Models\AggregateDirtyDay;
use App\Domain\Analytics\Models\CustomerMetric;
use App\Domain\Analytics\Models\DailySalesFact;
use App\Domain\Tenancy\Models\ApiKey;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * La pregunta 3 de la defensa oral: "llega una orden de hace 60 días,
 * ¿qué queda mal y por cuánto tiempo?".
 *
 * Estos tests son la respuesta ejecutable. Sin ellos sería una teoría.
 */
beforeEach(function () {
    $this->tenant = Tenant::factory()->create([
        'slug' => 'luna-'.Str::random(6),
        'timezone' => 'America/Argentina/Buenos_Aires',
        'reporting_currency' => 'ARS',
    ]);

    [, $this->secret] = ApiKey::issue($this->tenant, 'test');
});

function enviar(string $secret, array $records): TestResponse
{
    return test()->postJson('/api/v1/ingest/orders', [
        'source' => 'daily_stream',
        'records' => $records,
    ], ['Authorization' => "Bearer {$secret}"]);
}

function ordenEn(string $fecha, string $orderId, string $total = '1000.00', string $status = 'delivered'): array
{
    return [
        'order_id' => $orderId,
        'status' => $status,
        'currency' => 'ARS',
        'total' => $total,
        'placed_at' => "{$fecha}T12:00:00-03:00",
        'updated_at' => "{$fecha}T12:00:00-03:00",
        'customer' => ['customer_id' => 'CUS-1', 'name' => 'María', 'email' => 'maria@example.test'],
        'items' => [['product_id' => 'P-1', 'name' => 'Remera', 'category' => 'indumentaria', 'quantity' => 1, 'unit_price' => $total]],
    ];
}

function enContexto(Tenant $tenant, Closure $callback): mixed
{
    return app(TenantContext::class)->run($tenant, $callback);
}

it('marca el día de la orden como sucio y lo recalcula', function () {
    enviar($this->secret, [ordenEn('2026-03-01', 'ORD-1')])->assertStatus(202);

    enContexto($this->tenant, function () {
        $fact = DailySalesFact::query()->where('date', '2026-03-01')->first();

        expect($fact)->not->toBeNull()
            ->and($fact->orders_count)->toBe(1)
            ->and($fact->reporting_net_minor)->toBe(100000);

        // Con la cola procesada, no queda nada pendiente: el dashboard está
        // al día.
        expect(AggregateDirtyDay::query()->whereNull('processed_at')->count())->toBe(0);
    });
});

it('corrige el pasado cuando una orden vieja llega hoy', function () {
    // Día ya consolidado.
    enviar($this->secret, [ordenEn('2026-03-01', 'ORD-1', '1000.00')])->assertStatus(202);

    enContexto($this->tenant, fn () => expect(
        DailySalesFact::query()->where('date', '2026-03-01')->value('reporting_net_minor')
    )->toBe(100000));

    // Dos meses después aparece una orden de ese mismo día.
    enviar($this->secret, [ordenEn('2026-03-01', 'ORD-TARDIA', '500.00')])->assertStatus(202);

    enContexto($this->tenant, function () {
        // El día del pasado quedó corregido, no duplicado ni ignorado.
        $fact = DailySalesFact::query()->where('date', '2026-03-01')->first();

        expect($fact->orders_count)->toBe(2)
            ->and($fact->reporting_net_minor)->toBe(150000);
    });
});

it('ensucia los dos días cuando una orden cambia de fecha', function () {
    enviar($this->secret, [ordenEn('2026-03-01', 'ORD-1', '1000.00')])->assertStatus(202);

    // Corrección del retailer: la venta era de otro día.
    enviar($this->secret, [[
        ...ordenEn('2026-03-10', 'ORD-1', '1000.00'),
        'updated_at' => '2026-04-01T12:00:00-03:00',
    ]])->assertStatus(202);

    enContexto($this->tenant, function () {
        // El día que la pierde tiene que quedar en cero, no con el valor
        // viejo. Olvidarse de ese lado deja revenue inflado en el pasado que
        // nadie vuelve a mirar.
        expect(DailySalesFact::query()->where('date', '2026-03-01')->count())->toBe(0)
            ->and(DailySalesFact::query()->where('date', '2026-03-10')->value('orders_count'))->toBe(1);
    });
});

it('excluye canceladas y devueltas del neto pero no del bruto', function () {
    enviar($this->secret, [
        ordenEn('2026-03-01', 'ORD-1', '1000.00', 'delivered'),
        ordenEn('2026-03-01', 'ORD-2', '2000.00', 'cancelled'),
        ordenEn('2026-03-01', 'ORD-3', '3000.00', 'returned'),
    ])->assertStatus(202);

    enContexto($this->tenant, function () {
        $fact = DailySalesFact::query()->where('date', '2026-03-01')->first();

        expect($fact->orders_count)->toBe(3)
            ->and($fact->cancelled_count)->toBe(1)
            ->and($fact->returned_count)->toBe(1)
            ->and($fact->reporting_gross_minor)->toBe(600000)
            // El neto es lo que un gerente considera "ventas de verdad".
            ->and($fact->reporting_net_minor)->toBe(100000);
    });
});

it('recalcula las métricas del cliente al ingerir', function () {
    enviar($this->secret, [
        ordenEn('2026-03-01', 'ORD-1', '1000.00'),
        ordenEn('2026-03-15', 'ORD-2', '3000.00'),
    ])->assertStatus(202);

    enContexto($this->tenant, function () {
        $metric = CustomerMetric::query()->first();

        expect($metric->orders_count)->toBe(2)
            ->and($metric->net_orders_count)->toBe(2)
            ->and($metric->total_net_reporting_minor)->toBe(400000)
            ->and($metric->avg_order_reporting_minor)->toBe(200000)
            ->and($metric->segment)->not->toBeNull();
    });
});
