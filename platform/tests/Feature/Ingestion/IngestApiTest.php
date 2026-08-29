<?php

declare(strict_types=1);

use App\Domain\Ingestion\Enums\BatchStatus;
use App\Domain\Ingestion\Enums\RejectReason;
use App\Domain\Ingestion\Models\IngestionBatch;
use App\Domain\Ingestion\Models\IngestionReject;
use App\Domain\Sales\Enums\OrderStatus;
use App\Domain\Sales\Models\Customer;
use App\Domain\Sales\Models\Order;
use App\Domain\Tenancy\Models\ApiKey;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create([
        'slug' => 'luna-'.Str::random(6),
        'timezone' => 'America/Argentina/Buenos_Aires',
        'reporting_currency' => 'ARS',
    ]);

    [$key, $secret] = ApiKey::issue($this->tenant, 'test');
    $this->secret = $secret;
});

function orden(array $overrides = []): array
{
    return array_replace_recursive([
        'order_id' => 'ORD-1',
        'status' => 'paid',
        'currency' => 'ARS',
        'total' => '1000.00',
        'placed_at' => '2026-03-01T10:00:00-03:00',
        'updated_at' => '2026-03-01T10:00:00-03:00',
        'customer' => ['customer_id' => 'CUS-1', 'name' => 'María', 'email' => 'maria@example.com'],
        'items' => [['product_id' => 'P-1', 'name' => 'Remera', 'category' => 'indumentaria', 'quantity' => 1, 'unit_price' => '1000.00']],
    ], $overrides);
}

function ingestar(string $secret, array $records, array $extra = [], array $headers = []): TestResponse
{
    return test()->postJson('/api/v1/ingest/orders', array_merge([
        'source' => 'daily_stream',
        'records' => $records,
    ], $extra), array_merge(['Authorization' => "Bearer {$secret}"], $headers));
}

/** Ejecuta aserciones dentro del contexto del tenant, que es lo que exige RLS. */
function comoTenant(Tenant $tenant, Closure $callback): mixed
{
    return app(TenantContext::class)->run($tenant, $callback);
}

// ─────────────────────────────────────────────────────────────────
// Autenticación
// ─────────────────────────────────────────────────────────────────

it('rechaza el request sin credencial', function () {
    $this->postJson('/api/v1/ingest/orders', ['source' => 'daily_stream', 'records' => [orden()]])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthenticated');
});

it('rechaza una credencial inventada', function () {
    ingestar('d4s_no_existe', [orden()])->assertStatus(401);
});

it('rechaza una credencial revocada', function () {
    ApiKey::query()->where('tenant_id', $this->tenant->id)->update(['revoked_at' => now()]);

    ingestar($this->secret, [orden()])->assertStatus(401);
});

it('rechaza una credencial sin el alcance de escritura', function () {
    [, $soloLectura] = ApiKey::issue($this->tenant, 'solo lectura', ['query:read']);

    ingestar($soloLectura, [orden()])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'forbidden');
});

it('rechaza la credencial de un tenant desactivado', function () {
    $this->tenant->update(['is_active' => false]);

    ingestar($this->secret, [orden()])->assertStatus(401);
});

// ─────────────────────────────────────────────────────────────────
// Camino feliz
// ─────────────────────────────────────────────────────────────────

it('acepta un lote y responde 202 con el estado', function () {
    // 202 y no 201: el lote quedó aceptado para procesar, pero decir que
    // fue creado sería mentir sobre el estado del sistema.
    $response = ingestar($this->secret, [orden()])
        ->assertStatus(202)
        ->assertJsonPath('data.counts.received', 1);

    $batchId = $response->json('data.batch_id');

    comoTenant($this->tenant, function () use ($batchId) {
        $batch = IngestionBatch::query()->find($batchId);

        expect($batch->status)->toBe(BatchStatus::Completed)
            ->and($batch->accepted_count)->toBe(1);

        $order = Order::query()->where('external_id', 'ORD-1')->first();

        expect($order)->not->toBeNull()
            ->and($order->status)->toBe(OrderStatus::Paid)
            ->and($order->total_amount_minor)->toBe(100000)
            ->and($order->placed_on->format('Y-m-d'))->toBe('2026-03-01')
            ->and($order->items)->toHaveCount(1);
    });
});

it('crea el comprador junto con su primera orden', function () {
    ingestar($this->secret, [orden()])->assertStatus(202);

    comoTenant($this->tenant, function () {
        $customer = Customer::query()->where('external_id', 'CUS-1')->first();

        expect($customer)->not->toBeNull()
            ->and($customer->email_normalized)->toBe('maria@example.com')
            ->and($customer->email_status)->toBe('valid');
    });
});

// ─────────────────────────────────────────────────────────────────
// Idempotencia y duplicados
// ─────────────────────────────────────────────────────────────────

it('devuelve el mismo lote si se reenvía con la misma clave de idempotencia', function () {
    $headers = ['Idempotency-Key' => 'reintento-1'];

    $primero = ingestar($this->secret, [orden()], headers: $headers)->json('data.batch_id');
    $segundo = ingestar($this->secret, [orden()], headers: $headers)->json('data.batch_id');

    expect($segundo)->toBe($primero);

    comoTenant($this->tenant, fn () => expect(IngestionBatch::query()->count())->toBe(1));
});

it('cuenta como duplicados los registros idénticos ya ingeridos', function () {
    ingestar($this->secret, [orden()])->assertStatus(202);

    // Sin clave de idempotencia se crea un lote nuevo, pero el índice único
    // sobre el hash de contenido impide que el registro entre dos veces.
    $segundo = ingestar($this->secret, [orden()])->assertStatus(202);

    expect($segundo->json('data.counts.duplicate'))->toBe(1);

    comoTenant($this->tenant, fn () => expect(Order::query()->count())->toBe(1));
});

it('aplica la actualización más nueva y descarta la vieja sin importar el orden de llegada', function () {
    // Llega primero la actualización NUEVA...
    ingestar($this->secret, [orden([
        'status' => 'delivered',
        'updated_at' => '2026-03-05T10:00:00-03:00',
    ])])->assertStatus(202);

    // ...y después una vieja, como pasa con un reintento desordenado.
    ingestar($this->secret, [orden([
        'status' => 'paid',
        'updated_at' => '2026-03-02T10:00:00-03:00',
    ])])->assertStatus(202);

    comoTenant($this->tenant, function () {
        $order = Order::query()->where('external_id', 'ORD-1')->first();

        // Sin guarda de versión, la orden habría vuelto a "pagada".
        expect($order->status)->toBe(OrderStatus::Delivered);

        expect(IngestionReject::query()->where('reason', RejectReason::StaleUpdate)->count())
            ->toBeGreaterThan(0);
    });
});

it('avanza el estado cuando la actualización es más nueva', function () {
    ingestar($this->secret, [orden(['status' => 'paid', 'updated_at' => '2026-03-01T10:00:00-03:00'])]);
    ingestar($this->secret, [orden(['status' => 'delivered', 'updated_at' => '2026-03-04T10:00:00-03:00'])]);

    comoTenant($this->tenant, function () {
        $order = Order::query()->where('external_id', 'ORD-1')->first();

        expect($order->status)->toBe(OrderStatus::Delivered)
            ->and($order->statusHistory)->toHaveCount(2);
    });
});

// ─────────────────────────────────────────────────────────────────
// Aislamiento
// ─────────────────────────────────────────────────────────────────

it('no deja consultar el lote de otro tenant', function () {
    $batchId = ingestar($this->secret, [orden()])->json('data.batch_id');

    $otro = Tenant::factory()->create(['slug' => 'otro-'.Str::random(6)]);
    [, $otroSecret] = ApiKey::issue($otro, 'test');

    // Indistinguible de un lote inexistente: la respuesta no filtra que existe.
    $this->getJson("/api/v1/ingest/batches/{$batchId}", ['Authorization' => "Bearer {$otroSecret}"])
        ->assertStatus(404);
});

it('descarta registros que dicen pertenecer a otro tenant', function () {
    $otro = Tenant::factory()->create(['slug' => 'ajeno-'.Str::random(6)]);

    ingestar($this->secret, [orden(['tenant_id' => $otro->id])])->assertStatus(202);

    comoTenant($this->tenant, function () {
        expect(Order::query()->count())->toBe(0)
            ->and(IngestionReject::query()->where('reason', RejectReason::ForeignTenant)->count())->toBe(1);
    });
});

// ─────────────────────────────────────────────────────────────────
// Envío incompleto
// ─────────────────────────────────────────────────────────────────

it('marca como parcial un envío que llegó incompleto', function () {
    ingestar($this->secret, [orden()], ['expected_count' => 3])
        ->assertStatus(202);

    comoTenant($this->tenant, function () {
        $batch = IngestionBatch::query()->first();

        expect($batch->status)->toBe(BatchStatus::Partial)
            ->and($batch->failure_reason)->toContain('Faltan 2');
    });
});

it('reenviar un envío interrumpido no duplica lo que ya había entrado', function () {
    $completo = [orden(['order_id' => 'ORD-1']), orden(['order_id' => 'ORD-2']), orden(['order_id' => 'ORD-3'])];

    // El envío se corta: sólo llegan los dos primeros.
    ingestar($this->secret, array_slice($completo, 0, 2), ['expected_count' => 3]);

    // El retailer reenvía el lote entero.
    $reenvio = ingestar($this->secret, $completo)->assertStatus(202);

    expect($reenvio->json('data.counts.duplicate'))->toBe(2);

    comoTenant($this->tenant, fn () => expect(Order::query()->count())->toBe(3));
});

// ─────────────────────────────────────────────────────────────────
// Validación del sobre
// ─────────────────────────────────────────────────────────────────

it('rechaza un lote vacío', function () {
    ingestar($this->secret, [])->assertStatus(422);
});

it('rechaza un lote que excede el máximo del tenant', function () {
    $this->tenant->update(['ingest_max_batch_size' => 2]);

    ingestar($this->secret, [orden(['order_id' => 'A']), orden(['order_id' => 'B']), orden(['order_id' => 'C'])])
        ->assertStatus(422)
        ->assertJsonPath('errors.records.0', 'El lote excede el máximo de 2 registros acordado para este tenant. Dividí el envío.');
});

it('rechaza un tipo de entidad desconocido', function () {
    $this->postJson('/api/v1/ingest/facturas', ['source' => 'daily_stream', 'records' => [orden()]], [
        'Authorization' => "Bearer {$this->secret}",
    ])->assertStatus(404)->assertJsonPath('error.code', 'unknown_entity');
});
