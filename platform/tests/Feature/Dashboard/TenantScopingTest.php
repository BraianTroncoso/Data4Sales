<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Models\User;
use App\Domain\Sales\Models\Customer;
use App\Domain\Tenancy\Models\ApiKey;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Str;

/**
 * "Un Administrador de un tenant no ve nada de otro tenant."
 *
 * Es la regla que combina rol con organización, y la que hace que un
 * dashboard multi-tenant sea seguro o no lo sea.
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

    $this->adminLuna = User::factory()->create([
        'tenant_id' => $this->luna->id,
        'role' => UserRole::Admin,
    ]);
});

function cargarVenta(Tenant $tenant, string $orderId, string $customerId, string $nombre): void
{
    [, $secret] = ApiKey::issue($tenant, 'seed-'.Str::random(4));

    test()->postJson('/api/v1/ingest/orders', [
        'source' => 'daily_stream',
        'records' => [[
            'order_id' => $orderId,
            'status' => 'delivered',
            'currency' => 'ARS',
            'total' => '1000.00',
            'placed_at' => '2026-03-01T12:00:00-03:00',
            'updated_at' => '2026-03-01T12:00:00-03:00',
            'customer' => ['customer_id' => $customerId, 'name' => $nombre, 'email' => strtolower($nombre).'@example.test'],
            'items' => [['product_id' => 'P-1', 'name' => 'Remera', 'category' => 'indumentaria', 'quantity' => 1, 'unit_price' => '1000.00']],
        ]],
    ], ['Authorization' => "Bearer {$secret}"])->assertStatus(202);
}

it('no lista clientes de otra organización', function () {
    cargarVenta($this->luna, 'ORD-L', 'CUS-L', 'Luna');
    cargarVenta($this->andes, 'ORD-A', 'CUS-A', 'Andes');

    $this->actingAs($this->adminLuna);

    $props = $this->get('/clientes')->viewData('page')['props'];
    $externos = array_column($props['customers']['data'], 'external_id');

    expect($externos)->toContain('CUS-L')
        ->and($externos)->not->toContain('CUS-A');
});

it('devuelve 404 al pedir el detalle de un cliente de otra organización', function () {
    cargarVenta($this->andes, 'ORD-A', 'CUS-A', 'Andes');

    $ajeno = app(TenantContext::class)->run(
        $this->andes,
        fn () => Customer::query()->where('external_id', 'CUS-A')->firstOrFail(),
    );

    $this->actingAs($this->adminLuna);

    // Indistinguible de un cliente inexistente: Row-Level Security lo hace
    // invisible antes de que el controlador lo vea, así que la respuesta no
    // filtra que existe.
    $this->get("/clientes/{$ajeno->id}")->assertNotFound();
});

it('no muestra usuarios de otra organización', function () {
    $ajeno = User::factory()->create(['tenant_id' => $this->andes->id, 'role' => UserRole::Admin]);

    $this->actingAs($this->adminLuna);

    $props = $this->get('/usuarios')->viewData('page')['props'];
    $emails = array_column($props['users'], 'email');

    expect($emails)->toContain($this->adminLuna->email)
        ->and($emails)->not->toContain($ajeno->email);
});

it('no deja modificar un usuario de otra organización', function () {
    $ajeno = User::factory()->create(['tenant_id' => $this->andes->id, 'role' => UserRole::Support]);

    $this->actingAs($this->adminLuna);

    $this->patch("/usuarios/{$ajeno->id}", ['role' => 'admin'])->assertNotFound();

    expect($ajeno->fresh()->role)->toBe(UserRole::Support);
});

it('las estadísticas sólo cuentan las ventas de la organización', function () {
    cargarVenta($this->luna, 'ORD-L1', 'CUS-L1', 'Uno');
    cargarVenta($this->luna, 'ORD-L2', 'CUS-L2', 'Dos');
    cargarVenta($this->andes, 'ORD-A1', 'CUS-A1', 'Tres');

    $this->actingAs($this->adminLuna);

    $props = $this->get('/estadisticas')->viewData('page')['props'];

    expect($props['retention']['buyers'])->toBe(2)
        ->and($props['tenant']['slug'])->toBe($this->luna->slug)
        ->and($props['tenant']['currency'])->toBe('ARS');
});
