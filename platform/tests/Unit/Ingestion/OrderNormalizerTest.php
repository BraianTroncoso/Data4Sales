<?php

declare(strict_types=1);

use App\Domain\Ingestion\Enums\RejectReason;
use App\Domain\Ingestion\Pipeline\OrderNormalizer;
use App\Domain\Ingestion\Pipeline\RejectBag;
use App\Domain\Ingestion\Support\ExchangeRateProvider;
use App\Domain\Sales\Enums\OrderStatus;
use App\Domain\Tenancy\Models\Tenant;

/**
 * Tenant en memoria: el normalizador solo lee zona horaria, moneda de
 * reporte e identidad, asi que no hace falta base de datos para probarlo.
 */
function tenantDePrueba(string $timezone = 'America/Argentina/Buenos_Aires', string $currency = 'ARS'): Tenant
{
    $tenant = new Tenant([
        'slug' => 'luna',
        'name' => 'Casa Luna',
        'timezone' => $timezone,
        'reporting_currency' => $currency,
    ]);

    $tenant->id = '11111111-1111-4111-8111-111111111111';

    return $tenant;
}

function ordenValida(array $overrides = []): array
{
    return array_replace_recursive([
        'order_id' => 'ORD-1',
        'status' => 'paid',
        'currency' => 'ARS',
        'total' => '12345.67',
        'placed_at' => '2026-03-01T10:00:00-03:00',
        'updated_at' => '2026-03-01T11:00:00-03:00',
        'customer' => [
            'customer_id' => 'CUS-1',
            'name' => 'María González',
            'email' => 'maria@example.com',
            'country' => 'AR',
        ],
        'items' => [
            ['product_id' => 'P-1', 'sku' => 'SKU-1', 'name' => 'Remera', 'category' => 'indumentaria', 'quantity' => 2, 'unit_price' => '1000.00'],
        ],
    ], $overrides);
}

function normalizar(array $payload, ?Tenant $tenant = null): array
{
    $bag = new RejectBag;
    $normalizer = new OrderNormalizer(new ExchangeRateProvider);
    $order = $normalizer->normalize($payload, $tenant ?? tenantDePrueba(), $bag);

    return [$order, $bag];
}

function motivos(RejectBag $bag): array
{
    return array_map(fn (array $r) => $r['reason'], $bag->all());
}

// ─────────────────────────────────────────────────────────────────
// Camino feliz
// ─────────────────────────────────────────────────────────────────

it('normaliza una orden limpia', function () {
    [$order, $bag] = normalizar(ordenValida());

    expect($bag->isEmpty())->toBeTrue()
        ->and($order->externalId)->toBe('ORD-1')
        ->and($order->status)->toBe(OrderStatus::Paid)
        ->and($order->currency)->toBe('ARS')
        ->and($order->totalAmountMinor)->toBe(1234567)
        ->and($order->items)->toHaveCount(1)
        ->and($order->items[0]->quantity)->toBe(2)
        ->and($order->items[0]->totalAmountMinor)->toBe(200000);
});

it('calcula el dia del tenant y no el dia UTC', function () {
    // 22:30 en Buenos Aires es 01:30 UTC del dia siguiente. La venta tiene
    // que contarse el 1 de marzo, que es cuando el comprador compro.
    [$order] = normalizar(ordenValida(['placed_at' => '2026-03-01T22:30:00-03:00']));

    expect($order->placedAt->toIso8601ZuluString())->toBe('2026-03-02T01:30:00Z')
        ->and($order->placedOn)->toBe('2026-03-01');
});

it('convierte a la moneda de reporte y deja la tasa guardada', function () {
    $tenant = tenantDePrueba(currency: 'USD');

    [$order] = normalizar(ordenValida(['currency' => 'ARS', 'total' => '1350.00']), $tenant);

    // 1350 ARS a 1350 ARS por dolar es un dolar: 100 centavos.
    expect($order->currency)->toBe('ARS')
        ->and($order->totalAmountMinor)->toBe(135000)
        ->and($order->reportingAmountMinor)->toBe(100)
        ->and($order->fxRate)->toBeGreaterThan(0.0);
});

it('no convierte cuando la moneda ya es la de reporte', function () {
    [$order] = normalizar(ordenValida());

    expect($order->fxRate)->toBe(1.0)
        ->and($order->reportingAmountMinor)->toBe($order->totalAmountMinor);
});

// ─────────────────────────────────────────────────────────────────
// Suciedad que DESCARTA el registro
// ─────────────────────────────────────────────────────────────────

it('descarta un registro que dice pertenecer a otro tenant', function () {
    [$order, $bag] = normalizar(ordenValida(['tenant_id' => 'otro-tenant-cualquiera']));

    expect($order)->toBeNull()
        ->and(motivos($bag))->toContain(RejectReason::ForeignTenant);
});

it('acepta el registro si el tenant declarado es el propio', function () {
    $tenant = tenantDePrueba();

    [$order, $bag] = normalizar(ordenValida(['tenant_id' => $tenant->id]), $tenant);

    expect($order)->not->toBeNull()->and($bag->isEmpty())->toBeTrue();
});

it('descarta una orden sin identificador de origen', function (array $payload) {
    [$order, $bag] = normalizar($payload);

    expect($order)->toBeNull()
        ->and(motivos($bag))->toContain(RejectReason::MissingIdentifier);
})->with([
    'ausente' => [fn () => array_diff_key(ordenValida(), ['order_id' => null])],
    'vacio' => [fn () => ordenValida(['order_id' => ''])],
    'solo espacios' => [fn () => ordenValida(['order_id' => '   '])],
]);

it('descarta una moneda desconocida en vez de inventar una tasa', function () {
    [$order, $bag] = normalizar(ordenValida(['currency' => 'XXX']));

    expect($order)->toBeNull()
        ->and(motivos($bag))->toContain(RejectReason::UnknownCurrency);
});

it('descarta un total negativo', function () {
    [$order, $bag] = normalizar(ordenValida(['total' => '-500.00']));

    expect($order)->toBeNull()
        ->and(motivos($bag))->toContain(RejectReason::InvalidAmount);
});

it('descarta un total que no es numero', function () {
    [$order, $bag] = normalizar(ordenValida(['total' => 'no disponible']));

    expect($order)->toBeNull()
        ->and(motivos($bag))->toContain(RejectReason::InvalidType);
});

it('descarta una fecha ilegible', function () {
    [$order, $bag] = normalizar(ordenValida(['placed_at' => 'el martes pasado']));

    expect($order)->toBeNull()
        ->and(motivos($bag))->toContain(RejectReason::MalformedDate);
});

it('descarta un estado que no se puede mapear', function () {
    [$order, $bag] = normalizar(ordenValida(['status' => 'en_veremos']));

    expect($order)->toBeNull()
        ->and(motivos($bag))->toContain(RejectReason::UnknownStatus);
});

it('mapea sinonimos habituales de estado antes de rechazar', function (string $entrada, OrderStatus $esperado) {
    [$order] = normalizar(ordenValida(['status' => $entrada]));

    expect($order->status)->toBe($esperado);
})->with([
    'complete' => ['complete', OrderStatus::Delivered],
    'canceled con una ele' => ['canceled', OrderStatus::Cancelled],
    'refunded' => ['refunded', OrderStatus::Returned],
    'mayusculas' => ['PAID', OrderStatus::Paid],
    'con espacios' => ['  shipped  ', OrderStatus::Shipped],
]);

it('descarta una orden sin comprador', function () {
    [$order, $bag] = normalizar(array_diff_key(ordenValida(), ['customer' => null]));

    expect($order)->toBeNull()
        ->and(motivos($bag))->toContain(RejectReason::MissingField);
});

// ─────────────────────────────────────────────────────────────────
// Suciedad que NO descarta el registro
// ─────────────────────────────────────────────────────────────────

it('conserva la venta cuando el email es invalido', function () {
    [$order, $bag] = normalizar(ordenValida(['customer' => ['email' => 'esto no es un mail']]));

    // Perder un comprador entero porque su casilla esta mal seria peor que
    // quedarse sin la casilla, y ademas el comprador sigue teniendo ordenes.
    expect($order)->not->toBeNull()
        ->and($order->customer->emailStatus)->toBe('invalid')
        ->and($order->customer->emailNormalized)->toBeNull()
        ->and(motivos($bag))->toContain(RejectReason::EmailInvalid);
});

it('acepta un comprador sin email', function () {
    [$order, $bag] = normalizar(ordenValida(['customer' => ['email' => null]]));

    expect($order)->not->toBeNull()
        ->and($order->customer->emailStatus)->toBe('missing')
        ->and($bag->isEmpty())->toBeTrue();
});

it('acepta el mismo email en compradores distintos', function () {
    [$uno] = normalizar(ordenValida(['order_id' => 'ORD-1', 'customer' => ['customer_id' => 'CUS-1', 'email' => 'familia@example.com']]));
    [$dos] = normalizar(ordenValida(['order_id' => 'ORD-2', 'customer' => ['customer_id' => 'CUS-2', 'email' => 'familia@example.com']]));

    // El email es dato de contacto, no identidad: familias que comparten
    // casilla y cuentas corporativas son casos reales, no errores.
    expect($uno->customer->externalId)->toBe('CUS-1')
        ->and($dos->customer->externalId)->toBe('CUS-2')
        ->and($uno->customer->emailNormalized)->toBe($dos->customer->emailNormalized);
});

it('acepta una orden sin renglones', function () {
    // Sin array_replace_recursive: ese helper conserva el array original en
    // vez de vaciarlo, y el test pasaria midiendo otra cosa.
    $payload = ordenValida();
    $payload['items'] = [];

    [$order, $bag] = normalizar($payload);

    expect($order)->not->toBeNull()
        ->and($order->items)->toBe([])
        ->and($bag->isEmpty())->toBeTrue();
});

it('asume que el estado se genero al momento de la venta si no viene marca temporal', function () {
    $payload = array_diff_key(ordenValida(), ['updated_at' => null]);

    [$order] = normalizar($payload);

    expect($order->sourceUpdatedAt->toIso8601ZuluString())
        ->toBe($order->placedAt->toIso8601ZuluString());
});

it('acumula todos los problemas en vez de cortar en el primero', function () {
    [$order, $bag] = normalizar(ordenValida([
        'currency' => 'XXX',
        'status' => 'vaya uno a saber',
        'placed_at' => 'cuando fue',
    ]));

    expect($order)->toBeNull()
        ->and(motivos($bag))->toContain(RejectReason::UnknownCurrency)
        ->and(motivos($bag))->toContain(RejectReason::UnknownStatus)
        ->and(motivos($bag))->toContain(RejectReason::MalformedDate);
});

// ─────────────────────────────────────────────────────────────────
// Ausente vs vacío: no son el mismo problema
// ─────────────────────────────────────────────────────────────────

it('distingue un campo ausente de uno presente pero vacío', function (string $campo) {
    $ausente = ordenValida();
    unset($ausente[$campo]);

    $vacio = ordenValida();
    $vacio[$campo] = '   ';

    [, $bagAusente] = normalizar($ausente);
    [, $bagVacio] = normalizar($vacio);

    // Un retailer que no manda el campo puede simplemente no tenerlo; uno
    // que manda "" tiene un hueco en su propio sistema. Decirle cuál de los
    // dos problemas tiene es la diferencia entre un reporte útil y uno que
    // lo manda a buscar.
    expect(motivos($bagAusente))->toContain(RejectReason::MissingField)
        ->and(motivos($bagVacio))->toContain(RejectReason::EmptyField);
})->with(['status', 'total']);

it('trata la falta del identificador de origen como un caso aparte', function () {
    $payload = ordenValida();
    unset($payload['order_id']);

    [$order, $bag] = normalizar($payload);

    // No es un campo faltante más: sin identificador no hay deduplicación ni
    // actualización posible, así que el registro no sirve para nada.
    expect($order)->toBeNull()
        ->and(motivos($bag))->toContain(RejectReason::MissingIdentifier)
        ->and(motivos($bag))->not->toContain(RejectReason::MissingField);
});

// ─────────────────────────────────────────────────────────────────
// Renglones: los importes no se corrigen en silencio
// ─────────────────────────────────────────────────────────────────

it('descarta la orden cuando el precio de un renglón no se puede leer', function () {
    $payload = ordenValida();
    $payload['items'][0]['unit_price'] = 'a convenir';

    [$order, $bag] = normalizar($payload);

    // Volverlo cero dejaba a `daily_product_facts` sin cuadrar contra
    // `daily_sales_facts`, y sin una sola fila de rechazo que lo explicara.
    expect($order)->toBeNull()
        ->and(motivos($bag))->toContain(RejectReason::InvalidType);
});

it('descarta la orden cuando el importe de un renglón es negativo', function () {
    $payload = ordenValida();
    $payload['items'][0]['unit_price'] = '-1000.00';

    [$order, $bag] = normalizar($payload);

    expect($order)->toBeNull()
        ->and(motivos($bag))->toContain(RejectReason::InvalidAmount);
});

it('descarta la orden cuando el total de un renglón llegó y no se entiende', function () {
    $payload = ordenValida();
    $payload['items'][0]['total'] = 'no disponible';

    // Derivarlo de precio por cantidad sólo es honesto cuando el total no
    // vino. Si vino ilegible, derivarlo igual tapa el problema con un número
    // que después nadie puede distinguir de uno real.
    [$order, $bag] = normalizar($payload);

    expect($order)->toBeNull()
        ->and(motivos($bag))->toContain(RejectReason::InvalidType);
});

it('descarta la orden cuando la cantidad de un renglón no es un entero positivo', function (mixed $cantidad) {
    $payload = ordenValida();
    $payload['items'][0]['quantity'] = $cantidad;

    [$order, $bag] = normalizar($payload);

    expect($order)->toBeNull()
        ->and(motivos($bag))->toContain(RejectReason::InvalidQuantity);
})->with([
    'texto' => ['dos'],
    'cero' => [0],
    'negativa' => [-3],
    'vacia' => [''],
]);

it('asume una unidad sólo cuando la cantidad no viene', function () {
    $payload = ordenValida();
    unset($payload['items'][0]['quantity']);

    [$order, $bag] = normalizar($payload);

    // Ausente y presente-pero-ilegible no son el mismo problema: un renglón
    // sin cantidad es una unidad, que es la convención de cualquier documento
    // de venta.
    expect($order)->not->toBeNull()
        ->and($order->items[0]->quantity)->toBe(1)
        ->and($bag->isEmpty())->toBeTrue();
});

it('acepta un renglón sin precio sin inventar rechazos', function () {
    $payload = ordenValida();
    unset($payload['items'][0]['unit_price']);

    [$order, $bag] = normalizar($payload);

    expect($order)->not->toBeNull()
        ->and($order->items[0]->unitAmountMinor)->toBe(0)
        ->and($bag->isEmpty())->toBeTrue();
});

// ─────────────────────────────────────────────────────────────────
// El motivo que se reporta tiene que ser el verdadero
// ─────────────────────────────────────────────────────────────────

it('anota la marca de actualización ilegible en vez de dejar que parezca una actualización vieja', function () {
    [$order, $bag] = normalizar(ordenValida(['updated_at' => 'el martes pasado']));

    // La orden sobrevive y se asume el momento de la venta, igual que antes.
    // Lo que cambia es que el motivo queda registrado: sin esto, la orden se
    // descarta más tarde como `stale_update` y el retailer lee en
    // /ingest/batches/{id}/rejects un diagnóstico que culpa al orden de
    // llegada cuando el problema era la fecha.
    expect($order)->not->toBeNull()
        ->and($order->sourceUpdatedAt->toIso8601ZuluString())->toBe($order->placedAt->toIso8601ZuluString())
        ->and(motivos($bag))->toContain(RejectReason::UnreadableUpdateTimestamp)
        ->and(motivos($bag))->not->toContain(RejectReason::StaleUpdate);
});
