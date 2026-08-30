<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Models\User;
use App\Domain\Sales\Models\Customer;
use App\Domain\Sales\Models\Order;
use App\Domain\Tenancy\Models\ApiKey;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Str;

/**
 * El detalle de una orden.
 *
 * Es la pantalla donde el proyecto muestra que el dato es auditable: el
 * historial de estados, la tasa con la que se convirtio el monto, y los tres
 * tiempos que separan cuando compro el cliente de cuando nos enteramos. Estos
 * tests cubren las dos cosas que pueden salir mal: que el historial no llegue,
 * y que la orden llegue a quien no corresponde.
 */
beforeEach(function () {
    $this->luna = Tenant::factory()->create([
        'slug' => 'luna-'.Str::random(6),
        'timezone' => 'America/Argentina/Buenos_Aires',
        'reporting_currency' => 'ARS',
    ]);

    $this->andes = Tenant::factory()->create([
        'slug' => 'andes-'.Str::random(6),
        'reporting_currency' => 'USD',
    ]);

    $this->soporteLuna = User::factory()->create([
        'tenant_id' => $this->luna->id,
        'role' => UserRole::Support,
    ]);
});

/**
 * Una orden entra por la API real, no por factory: no hay factories de ventas
 * en este proyecto a proposito, para que los tests recorran el mismo pipeline
 * de normalizacion que la produccion.
 */
function ingestarOrden(Tenant $tenant, string $orderId, string $customerId, string $status, string $updatedAt): void
{
    [, $secret] = ApiKey::issue($tenant, 'seed-'.Str::random(4));

    test()->postJson('/api/v1/ingest/orders', [
        'source' => 'daily_stream',
        'records' => [[
            'order_id' => $orderId,
            'status' => $status,
            'currency' => 'ARS',
            'total' => '1500.00',
            'placed_at' => '2026-03-01T12:00:00-03:00',
            'updated_at' => $updatedAt,
            'customer' => [
                'customer_id' => $customerId,
                'name' => 'Cliente '.$customerId,
                'email' => strtolower($customerId).'@example.test',
            ],
            'items' => [[
                'product_id' => 'P-1',
                'name' => 'Remera',
                'category' => 'indumentaria',
                'quantity' => 3,
                'unit_price' => '500.00',
            ]],
        ]],
    ], ['Authorization' => "Bearer {$secret}"])->assertStatus(202);
}

/** @return array{0: Customer, 1: Order} */
function buscarOrden(Tenant $tenant, string $customerExternalId, string $orderExternalId): array
{
    return app(TenantContext::class)->run($tenant, fn () => [
        Customer::query()->where('external_id', $customerExternalId)->firstOrFail(),
        Order::query()->where('external_id', $orderExternalId)->firstOrFail(),
    ]);
}

it('muestra la orden con su historial de estados completo', function () {
    // Dos ingestas de la MISMA orden: primero pagada, despues entregada. Es lo
    // que produce una transicion real en el historial.
    ingestarOrden($this->luna, 'ORD-1', 'CUS-1', 'paid', '2026-03-01T12:00:00-03:00');
    ingestarOrden($this->luna, 'ORD-1', 'CUS-1', 'delivered', '2026-03-03T09:00:00-03:00');

    [$cliente, $orden] = buscarOrden($this->luna, 'CUS-1', 'ORD-1');

    $this->actingAs($this->soporteLuna);

    $props = $this->get("/clientes/{$cliente->id}/ordenes/{$orden->id}")
        ->assertOk()
        ->viewData('page')['props'];

    // El historial es la razon de ser de la pantalla: si no llega, la pantalla
    // no tiene sentido aunque devuelva 200.
    $historial = $props['order']['status_history'];

    expect($historial)->toHaveCount(2)
        ->and($historial[0]['from'])->toBeNull()
        ->and($historial[0]['to'])->toBe('paid')
        ->and($historial[1]['from'])->toBe('paid')
        ->and($historial[1]['to'])->toBe('delivered')
        // Ordenado por cuando ocurrio, no por cuando se inserto.
        ->and($historial[0]['occurred_at'])->toBeLessThan($historial[1]['occurred_at']);
});

it('expone la trazabilidad de moneda y de ingesta', function () {
    ingestarOrden($this->luna, 'ORD-1', 'CUS-1', 'delivered', '2026-03-01T12:00:00-03:00');

    [$cliente, $orden] = buscarOrden($this->luna, 'CUS-1', 'ORD-1');

    $this->actingAs($this->soporteLuna);

    $props = $this->get("/clientes/{$cliente->id}/ordenes/{$orden->id}")->viewData('page')['props'];
    $order = $props['order'];

    // Los dos montos con la tasa al lado: sin la tasa, el numero del dashboard
    // no se puede auditar contra el del retailer.
    expect($order)->toHaveKeys([
        'total_amount_minor',
        'reporting_amount_minor',
        'fx_rate',
        'currency',
        'placed_at',
        'source_updated_at',
        'received_at',
        'arrival_lag_days',
    ]);

    // Los renglones vienen ENVUELTOS en `data` y el producto va anidado
    // dentro de cada uno. El envoltorio no es un descuido: es el `$wrap` de
    // los resources de Laravel, el mismo que ya tiene la ficha del cliente y
    // la API publica v1. Se afirma acá para que quede escrito cuál es el
    // shape, porque es donde una pantalla nueva se equivoca primero.
    expect($order['items']['data'][0]['product']['name'])->toBe('Remera')
        ->and($order['items']['data'][0]['quantity'])->toBe(3);

    // Y el cliente viaja sin sus ordenes: esta pantalla muestra una sola.
    expect($props['customer']['external_id'])->toBe('CUS-1')
        ->and($props['customer'])->not->toHaveKey('orders');
});

it('devuelve 404 si la orden es de otro cliente de la misma organización', function () {
    ingestarOrden($this->luna, 'ORD-1', 'CUS-1', 'delivered', '2026-03-01T12:00:00-03:00');
    ingestarOrden($this->luna, 'ORD-2', 'CUS-2', 'delivered', '2026-03-01T12:00:00-03:00');

    [$cliente1] = buscarOrden($this->luna, 'CUS-1', 'ORD-1');
    [, $orden2] = buscarOrden($this->luna, 'CUS-2', 'ORD-2');

    $this->actingAs($this->soporteLuna);

    // Sin el filtro por cliente en la consulta, esta URL devolveria la orden
    // igual y el cliente de la ruta seria decorativo.
    $this->get("/clientes/{$cliente1->id}/ordenes/{$orden2->id}")->assertNotFound();
});

it('devuelve 404 si la orden es de otra organización', function () {
    ingestarOrden($this->andes, 'ORD-A', 'CUS-A', 'delivered', '2026-03-01T12:00:00-03:00');

    [$ajeno, $ordenAjena] = buscarOrden($this->andes, 'CUS-A', 'ORD-A');

    $this->actingAs($this->soporteLuna);

    // Indistinguible de una orden inexistente: Row-Level Security la hace
    // invisible antes de que el controlador la vea.
    $this->get("/clientes/{$ajeno->id}/ordenes/{$ordenAjena->id}")->assertNotFound();
});

it('exige la capacidad de datos de clientes, no sólo una sesión', function () {
    ingestarOrden($this->luna, 'ORD-1', 'CUS-1', 'delivered', '2026-03-01T12:00:00-03:00');

    [$cliente, $orden] = buscarOrden($this->luna, 'CUS-1', 'ORD-1');
    $ruta = "/clientes/{$cliente->id}/ordenes/{$orden->id}";

    // Sin sesión: al login.
    $this->get($ruta)->assertRedirect('/login');

    // Analista: tiene sesión y no tiene por qué ver datos de compradores.
    $analista = User::factory()->create(['tenant_id' => $this->luna->id, 'role' => UserRole::Analyst]);
    $this->actingAs($analista)->get($ruta)->assertStatus(403);
});
