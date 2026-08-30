<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\ApiKey;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Str;

/**
 * Los controles del listado de clientes.
 *
 * Dos cosas que la pantalla necesita del servidor y que no puede deducir de la
 * pagina que esta viendo: cuantos paises tiene el padron ENTERO, y cuantas
 * filas pidio el agente. Deducir cualquiera de las dos del lado del navegador
 * da respuestas distintas segun donde estes paginando.
 */
beforeEach(function () {
    $this->luna = Tenant::factory()->create([
        'slug' => 'luna-'.Str::random(6),
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
 * Siembra un padron entero en UNA sola ingesta.
 *
 * @param  list<array{0: string, 1: string|null}>  $clientes  pares (external_id, pais)
 */
function ingestarPadron(Tenant $tenant, array $clientes): void
{
    [, $secret] = ApiKey::issue($tenant, 'seed-'.Str::random(4));

    $records = [];

    foreach ($clientes as [$externalId, $pais]) {
        $customer = [
            'customer_id' => $externalId,
            'name' => 'Cliente '.$externalId,
            'email' => strtolower($externalId).'@example.test',
        ];

        // Sin la clave cuando el pais es nulo: es el caso real de un retailer
        // que no manda el dato, no el de uno que manda vacio.
        if ($pais !== null) {
            $customer['country'] = $pais;
        }

        $records[] = [
            'order_id' => 'ORD-'.$externalId,
            'status' => 'delivered',
            'currency' => 'ARS',
            'total' => '1000.00',
            'placed_at' => '2026-03-01T12:00:00-03:00',
            'updated_at' => '2026-03-01T12:00:00-03:00',
            'customer' => $customer,
            'items' => [[
                'product_id' => 'P-1',
                'name' => 'Remera',
                'category' => 'indumentaria',
                'quantity' => 1,
                'unit_price' => '1000.00',
            ]],
        ];
    }

    test()->postJson('/api/v1/ingest/orders', [
        'source' => 'daily_stream',
        'records' => $records,
    ], ['Authorization' => "Bearer {$secret}"])->assertStatus(202);
}

it('devuelve los países del padrón, ordenados y sin repetir', function () {
    ingestarPadron($this->luna, [
        ['CUS-1', 'ES'],
        ['CUS-2', 'AR'],
        ['CUS-3', 'ES'],
        ['CUS-4', null],
    ]);

    $this->actingAs($this->soporteLuna);

    $props = $this->get('/clientes')->viewData('page')['props'];

    // Sin repetidos, ordenado, y sin un hueco por el cliente sin país.
    expect($props['countryOptions'])->toBe(['AR', 'ES']);
});

it('no incluye países de otra organización', function () {
    ingestarPadron($this->luna, [['CUS-L', 'AR']]);
    ingestarPadron($this->andes, [['CUS-A', 'ES']]);

    $this->actingAs($this->soporteLuna);

    $props = $this->get('/clientes')->viewData('page')['props'];

    expect($props['countryOptions'])->toBe(['AR']);
});

it('acepta un per_page de la lista blanca y lo devuelve resuelto', function (int $pedido) {
    ingestarPadron($this->luna, [['CUS-1', 'AR']]);

    $this->actingAs($this->soporteLuna);

    $props = $this->get("/clientes?per_page={$pedido}")->viewData('page')['props'];

    expect($props['filters']['per_page'])->toBe($pedido);
})->with([25, 50, 100]);

it('ignora un per_page fuera de la lista blanca y vuelve al default', function (string $pedido) {
    ingestarPadron($this->luna, [['CUS-1', 'AR']]);

    $this->actingAs($this->soporteLuna);

    // Sin lista blanca, `per_page=100000` se lleva el padrón entero en una
    // sola respuesta. Y un valor raro no merece un 422: es un enlace viejo.
    $props = $this->get("/clientes?per_page={$pedido}")->viewData('page')['props'];

    expect($props['filters']['per_page'])->toBe(25);
})->with(['7', '100000', 'abc', '-1', '0']);

it('el per_page cambia de verdad el tamaño de la página', function () {
    // 26 clientes: uno más que el default, para que la diferencia entre 25 y
    // 50 sea observable. Que el filtro vuelva resuelto no prueba que se use.
    $padron = [];

    for ($i = 1; $i <= 26; $i++) {
        $padron[] = ['CUS-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'AR'];
    }

    ingestarPadron($this->luna, $padron);

    $this->actingAs($this->soporteLuna);

    $porDefecto = $this->get('/clientes')->viewData('page')['props'];
    expect($porDefecto['customers']['data'])->toHaveCount(25)
        ->and($porDefecto['customers']['next_cursor'])->not->toBeNull();

    $ampliado = $this->get('/clientes?per_page=50')->viewData('page')['props'];
    expect($ampliado['customers']['data'])->toHaveCount(26)
        ->and($ampliado['customers']['next_cursor'])->toBeNull();
});
