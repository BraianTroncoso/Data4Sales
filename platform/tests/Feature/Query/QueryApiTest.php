<?php

declare(strict_types=1);

use App\Domain\Tenancy\Models\ApiKey;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create([
        'slug' => 'luna-'.Str::random(6),
        'timezone' => 'America/Argentina/Buenos_Aires',
        'reporting_currency' => 'ARS',
    ]);

    [, $this->secret] = ApiKey::issue($this->tenant, 'test');

    $this->cargar = function (array $records) {
        return test()->postJson('/api/v1/ingest/orders', [
            'source' => 'daily_stream',
            'records' => $records,
        ], ['Authorization' => "Bearer {$this->secret}"]);
    };
});

function venta(string $orderId, string $customerId, string $nombre, string $email, string $fecha, string $total, string $status = 'delivered'): array
{
    return [
        'order_id' => $orderId,
        'status' => $status,
        'currency' => 'ARS',
        'total' => $total,
        'placed_at' => "{$fecha}T12:00:00-03:00",
        'updated_at' => "{$fecha}T12:00:00-03:00",
        'customer' => ['customer_id' => $customerId, 'name' => $nombre, 'email' => $email],
        'items' => [['product_id' => 'P-1', 'name' => 'Remera', 'category' => 'indumentaria', 'quantity' => 1, 'unit_price' => $total]],
    ];
}

function conClave(string $secret, string $url): TestResponse
{
    return test()->getJson($url, ['Authorization' => "Bearer {$secret}"]);
}

// ─────────────────────────────────────────────────────────────────
// Alcances
// ─────────────────────────────────────────────────────────────────

it('no deja leer el padrón con una credencial que sólo escribe', function () {
    [, $soloIngesta] = ApiKey::issue($this->tenant, 'pipeline', ['ingest:write']);

    // Es la razón de separar alcances: el pipeline del retailer necesita
    // escribir ventas, no leer los datos personales de sus compradores.
    conClave($soloIngesta, '/api/v1/customers')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'forbidden');
});

// ─────────────────────────────────────────────────────────────────
// Búsqueda
// ─────────────────────────────────────────────────────────────────

it('encuentra por nombre aunque el agente escriba sin acentos', function (string $termino) {
    // El caso normal en una plataforma para retailers de habla hispana: nadie
    // escribe los acentos al atender un llamado. Sin unaccent en el índice y
    // en la consulta, buscar "gonzalez" no encuentra "González" — y el agente
    // concluye que el cliente no existe.
    ($this->cargar)([venta('ORD-1', 'CUS-9', 'María González', 'mgonzalez@example.test', '2026-03-01', '1000.00')]);

    $response = conClave($this->secret, '/api/v1/customers?q='.urlencode($termino))->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.external_id'))->toBe('CUS-9');
})->with([
    'sin acento' => ['gonzalez'],
    'con acento' => ['González'],
    'mayúsculas sin acento' => ['GONZALEZ'],
    'nombre de pila sin acento' => ['maria'],
    'nombre de pila con acento' => ['María'],
]);

it('busca clientes por nombre parcial, email e identificador', function (string $termino) {
    ($this->cargar)([venta('ORD-1', 'CUS-42', 'María González', 'maria.gonzalez@example.test', '2026-03-01', '1000.00')]);

    $response = conClave($this->secret, '/api/v1/customers?q='.urlencode($termino))->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.external_id'))->toBe('CUS-42');
})->with([
    'nombre parcial' => ['gonzal'],
    'nombre con acento' => ['María'],
    'email parcial' => ['maria.gonz'],
    'identificador exacto' => ['CUS-42'],
]);

it('enmascara el email en el listado y lo muestra completo en el detalle', function () {
    ($this->cargar)([venta('ORD-1', 'CUS-1', 'María González', 'maria.gonzalez@example.test', '2026-03-01', '1000.00')]);

    $listado = conClave($this->secret, '/api/v1/customers')->assertOk();

    // Un listado se pagina, se exporta y se copia. Que no lleve el contacto
    // completo hace que una fuga de mil filas no sea una fuga de mil
    // contactos.
    expect($listado->json('data.0.email_masked'))->toBe('ma***ez@example.test')
        ->and($listado->json('data.0'))->not->toHaveKey('phone');

    $id = $listado->json('data.0.id');

    // El detalle sí: es la pantalla donde un agente atiende un caso puntual,
    // y ocultarle el email al que tiene que responder sería seguridad de
    // utilería.
    conClave($this->secret, "/api/v1/customers/{$id}")
        ->assertOk()
        ->assertJsonPath('data.email', 'maria.gonzalez@example.test');
});

it('no encuentra clientes de otro tenant', function () {
    ($this->cargar)([venta('ORD-1', 'CUS-1', 'María González', 'maria@example.test', '2026-03-01', '1000.00')]);

    $otro = Tenant::factory()->create(['slug' => 'otro-'.Str::random(6)]);
    [, $otroSecret] = ApiKey::issue($otro, 'test');

    conClave($otroSecret, '/api/v1/customers?q=gonzal')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

// ─────────────────────────────────────────────────────────────────
// Órdenes
// ─────────────────────────────────────────────────────────────────

it('filtra órdenes por rango de fechas y por estado', function () {
    ($this->cargar)([
        venta('ORD-1', 'CUS-1', 'Ana', 'ana@example.test', '2026-03-01', '1000.00', 'delivered'),
        venta('ORD-2', 'CUS-1', 'Ana', 'ana@example.test', '2026-03-15', '2000.00', 'cancelled'),
        venta('ORD-3', 'CUS-1', 'Ana', 'ana@example.test', '2026-04-01', '3000.00', 'delivered'),
    ]);

    conClave($this->secret, '/api/v1/orders?from=2026-03-01&to=2026-03-31')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    conClave($this->secret, '/api/v1/orders?status=cancelled')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.external_id', 'ORD-2');

    conClave($this->secret, '/api/v1/orders?net_only=1')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('devuelve los montos como enteros con su moneda, sin formatear', function () {
    ($this->cargar)([venta('ORD-1', 'CUS-1', 'Ana', 'ana@example.test', '2026-03-01', '1234.56')]);

    $response = conClave($this->secret, '/api/v1/orders')->assertOk();

    // Formatear en la API obligaría a cualquier consumidor que quiera sumar
    // a re-parsear un número que nosotros mismos rompimos.
    expect($response->json('data.0.total_amount_minor'))->toBe(123456)
        ->and($response->json('data.0.currency'))->toBe('ARS');
});

// ─────────────────────────────────────────────────────────────────
// Métricas
// ─────────────────────────────────────────────────────────────────

it('arma la serie temporal desde los agregados', function () {
    ($this->cargar)([
        venta('ORD-1', 'CUS-1', 'Ana', 'ana@example.test', '2026-03-01', '1000.00'),
        venta('ORD-2', 'CUS-2', 'Beto', 'beto@example.test', '2026-03-01', '2000.00'),
        venta('ORD-3', 'CUS-3', 'Caro', 'caro@example.test', '2026-03-05', '3000.00'),
    ]);

    $response = conClave($this->secret, '/api/v1/metrics/sales-series?from=2026-03-01&to=2026-03-31')->assertOk();

    expect($response->json('series'))->toHaveCount(2)
        ->and($response->json('series.0.bucket'))->toBe('2026-03-01')
        ->and($response->json('series.0.orders_count'))->toBe(2)
        ->and($response->json('series.0.net_minor'))->toBe(300000)
        ->and($response->json('currency'))->toBe('ARS');
});

it('agrupa la serie por mes cuando se pide', function () {
    ($this->cargar)([
        venta('ORD-1', 'CUS-1', 'Ana', 'ana@example.test', '2026-03-01', '1000.00'),
        venta('ORD-2', 'CUS-2', 'Beto', 'beto@example.test', '2026-03-28', '2000.00'),
    ]);

    conClave($this->secret, '/api/v1/metrics/sales-series?granularity=month')
        ->assertOk()
        ->assertJsonCount(1, 'series')
        ->assertJsonPath('series.0.orders_count', 2);
});

it('ignora una granularidad inventada en vez de interpolarla', function () {
    // El valor va dentro de DATE_TRUNC, así que si no estuviera en lista
    // blanca sería inyección SQL.
    conClave($this->secret, '/api/v1/metrics/sales-series?granularity=day%3B+DROP+TABLE+orders')
        ->assertOk()
        ->assertJsonPath('range.granularity', 'day');
});

it('reporta el top de categorías por revenue', function () {
    ($this->cargar)([
        venta('ORD-1', 'CUS-1', 'Ana', 'ana@example.test', '2026-03-01', '1000.00'),
        venta('ORD-2', 'CUS-2', 'Beto', 'beto@example.test', '2026-03-02', '2000.00'),
    ]);

    $response = conClave($this->secret, '/api/v1/metrics/top-products?by=category')->assertOk();

    expect($response->json('items.0.label'))->toBe('indumentaria')
        ->and($response->json('items.0.units'))->toBe(2);
});

it('calcula la tasa de recompra', function () {
    ($this->cargar)([
        venta('ORD-1', 'CUS-1', 'Ana', 'ana@example.test', '2026-03-01', '1000.00'),
        venta('ORD-2', 'CUS-1', 'Ana', 'ana@example.test', '2026-03-10', '1000.00'),
        venta('ORD-3', 'CUS-2', 'Beto', 'beto@example.test', '2026-03-01', '1000.00'),
    ]);

    $response = conClave($this->secret, '/api/v1/metrics/retention')->assertOk();

    // Uno de dos compradores volvió.
    expect($response->json('buyers'))->toBe(2)
        ->and($response->json('repeat_buyers'))->toBe(1)
        ->and($response->json('repeat_rate'))->toBe(0.5);
});

it('avisa cuando los agregados están atrasados', function () {
    ($this->cargar)([venta('ORD-1', 'CUS-1', 'Ana', 'ana@example.test', '2026-03-01', '1000.00')]);

    // Un dashboard que no dice cuán viejo es su dato invita a decisiones
    // tomadas sobre números que ya cambiaron.
    conClave($this->secret, '/api/v1/metrics/retention')
        ->assertOk()
        ->assertJsonPath('freshness.is_stale', false)
        ->assertJsonPath('freshness.pending_days', 0);
});
