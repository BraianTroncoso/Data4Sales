<?php

declare(strict_types=1);

use App\Domain\Ingestion\Actions\ReceiveBatch;
use App\Domain\Ingestion\Enums\BatchStatus;
use App\Domain\Ingestion\Enums\EntityType;
use App\Domain\Ingestion\Enums\IngestSource;
use App\Domain\Ingestion\Enums\RejectReason;
use App\Domain\Ingestion\Jobs\ProcessIngestionBatch;
use App\Domain\Ingestion\Models\IngestionBatch;
use App\Domain\Ingestion\Models\IngestionReject;
use App\Domain\Ingestion\Models\RawEvent;
use App\Domain\Ingestion\Support\PayloadHasher;
use App\Domain\Sales\Models\Order;
use App\Domain\Tenancy\Database\RowLevelSecurity;
use App\Domain\Tenancy\Models\ApiKey;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Connection;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Lo que le pasa a un lote cuando algo sale mal en el medio.
 *
 * Estos tests no prueban el camino feliz (eso ya lo hace IngestApiTest): se
 * meten en las ventanas entre dos escrituras, que es donde vive la diferencia
 * entre un sistema que se recupera y uno que queda mintiendo para siempre.
 */
beforeEach(function () {
    $this->tenant = Tenant::factory()->create([
        'slug' => 'durable-'.Str::random(6),
        'timezone' => 'America/Argentina/Buenos_Aires',
        'reporting_currency' => 'ARS',
    ]);

    [, $this->secret] = ApiKey::issue($this->tenant, 'test');
});

function ordenDurable(array $overrides = []): array
{
    return array_replace_recursive([
        'order_id' => 'ORD-1',
        'status' => 'paid',
        'currency' => 'ARS',
        'total' => '1000.00',
        'placed_at' => '2026-03-01T10:00:00-03:00',
        'updated_at' => '2026-03-01T10:00:00-03:00',
        'customer' => ['customer_id' => 'CUS-1', 'name' => 'María', 'email' => 'maria@example.test'],
        'items' => [['product_id' => 'P-1', 'name' => 'Remera', 'quantity' => 1, 'unit_price' => '1000.00']],
    ], $overrides);
}

function enTenantDurable(Tenant $tenant, Closure $callback): mixed
{
    return app(TenantContext::class)->run($tenant, $callback);
}

/** La conexion del plano de datos del tenant activo. */
function conexionDelTenant(): Connection
{
    $connection = DB::connection(app(TenantContext::class)->connectionName());

    assert($connection instanceof Connection);

    return $connection;
}

/**
 * Una segunda conexion PDO contra el mismo esquema.
 *
 * Es la unica forma de simular otro proceso trabajando en paralelo: dos
 * consultas sobre la MISMA conexion nunca pueden competir entre si, asi que
 * un test que use una sola no prueba nada sobre concurrencia.
 */
function conexionRivalIngesta(Tenant $tenant): Connection
{
    $nombre = app(TenantContext::class)->connectionName();

    config(['database.connections.rival_ingesta' => config("database.connections.{$nombre}")]);
    DB::purge('rival_ingesta');

    $rival = DB::connection('rival_ingesta');

    assert($rival instanceof Connection);

    // Sin contexto de tenant, RLS le devolveria cero filas a este rival y el
    // test pasaria por el motivo equivocado.
    $rival->statement('SELECT set_config(?, ?, false)', [RowLevelSecurity::CONTEXT_SETTING, $tenant->id]);

    return $rival;
}

// ─────────────────────────────────────────────────────────────────
// Idempotencia bajo concurrencia
// ─────────────────────────────────────────────────────────────────

it('devuelve el lote original cuando otro request con la misma clave se le adelanta', function () {
    $clave = 'carrera-'.Str::random(8);

    [$batch, $idRival] = enTenantDurable($this->tenant, function () use ($clave) {
        $rival = conexionRivalIngesta($this->tenant);
        $idRival = (string) Str::orderedUuid();
        $yaCorrio = false;

        // Justo ANTES de que este request inserte su lote, el otro request
        // del mismo cliente (el reintento tras un timeout de red) gana la
        // carrera y commitea el suyo. Es exactamente el hueco entre el SELECT
        // y el INSERT: los dos leyeron que no habia lote, los dos escriben.
        conexionDelTenant()->beforeExecuting(function (string $query) use (&$yaCorrio, $rival, $clave, $idRival) {
            if ($yaCorrio || preg_match('/insert into "?ingestion_batches/i', $query) !== 1) {
                return;
            }

            $yaCorrio = true;

            $rival->insert(
                'INSERT INTO ingestion_batches (id, tenant_id, idempotency_key, entity_type, source, status, '
                .'payload_hash, received_count, received_at, created_at, updated_at) '
                .'VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $idRival,
                    app(TenantContext::class)->currentOrFail()->id,
                    $clave,
                    EntityType::Orders->value,
                    IngestSource::DailyStream->value,
                    BatchStatus::Received->value,
                    PayloadHasher::hash([]),
                    1,
                    CarbonImmutable::now(),
                    CarbonImmutable::now(),
                    CarbonImmutable::now(),
                ],
            );
        });

        $batch = app(ReceiveBatch::class)->execute(
            EntityType::Orders,
            IngestSource::DailyStream,
            [ordenDurable()],
            $clave,
        );

        return [$batch, $idRival];
    });

    // El perdedor de la carrera devuelve el lote del ganador. Antes, el
    // INSERT se estrellaba contra el índice único y el cliente recibía un 500
    // justo en el caso que la idempotencia existe para resolver.
    expect($batch->id)->toBe($idRival)
        ->and($batch->idempotency_key)->toBe($clave);

    enTenantDurable($this->tenant, fn () => expect(IngestionBatch::query()->count())->toBe(1));
});

// ─────────────────────────────────────────────────────────────────
// Atomicidad de la recepción
// ─────────────────────────────────────────────────────────────────

it('no deja un lote fantasma si falla la escritura de los registros crudos', function () {
    enTenantDurable($this->tenant, function () {
        $armado = true;

        // El proceso se muere entre insertar el lote y sus registros crudos.
        conexionDelTenant()->beforeExecuting(function (string $query) use (&$armado) {
            if ($armado && preg_match('/insert into "?raw_events/i', $query) === 1) {
                $armado = false;

                throw new RuntimeException('el proceso se murió a mitad de la recepción');
            }
        });

        expect(fn () => app(ReceiveBatch::class)->execute(
            EntityType::Orders,
            IngestSource::DailyStream,
            [ordenDurable()],
            null,
        ))->toThrow(RuntimeException::class);

        // Sin transacción quedaba un lote en estado `received` que ningún
        // reintento completa: visible en la API, sin un solo evento adentro.
        expect(IngestionBatch::query()->count())->toBe(0)
            ->and(RawEvent::query()->count())->toBe(0);
    });
});

it('encola el trabajo recién cuando el lote ya está commiteado', function () {
    $visibleDesdeAfuera = enTenantDurable($this->tenant, function () {
        $rival = conexionRivalIngesta($this->tenant);
        $tenantId = $this->tenant->id;
        $visible = null;

        // El worker que toma el trabajo es otro proceso: si el lote se
        // encolara dentro de la transacción, buscaría una fila que todavía no
        // existe para nadie más que para quien la está escribiendo.
        Queue::before(function (JobProcessing $event) use (&$visible, $rival, $tenantId) {
            if ($visible !== null || ! str_contains($event->job->payload()['displayName'] ?? '', 'ProcessIngestionBatch')) {
                return;
            }

            $visible = (int) $rival->selectOne(
                'SELECT COUNT(*) AS total FROM ingestion_batches WHERE tenant_id = ?',
                [$tenantId],
            )->total;
        });

        app(ReceiveBatch::class)->execute(
            EntityType::Orders,
            IngestSource::DailyStream,
            [ordenDurable()],
            null,
        );

        return $visible;
    });

    expect($visibleDesdeAfuera)->toBe(1);
});

// ─────────────────────────────────────────────────────────────────
// Cuentas del lote
// ─────────────────────────────────────────────────────────────────

it('recupera las cuentas del lote cuando un reintento retoma eventos ya marcados', function () {
    $batchId = $this->postJson('/api/v1/ingest/orders', [
        'source' => 'daily_stream',
        'records' => [ordenDurable(['order_id' => 'ORD-1']), ordenDurable(['order_id' => 'ORD-2'])],
    ], ['Authorization' => "Bearer {$this->secret}"])->assertStatus(202)->json('data.batch_id');

    enTenantDurable($this->tenant, function () use ($batchId) {
        // El worker anterior murió DESPUÉS de marcar los eventos como
        // procesados y ANTES de guardar los contadores. El reintento se
        // saltea esos eventos, porque ya están marcados.
        IngestionBatch::query()->where('id', $batchId)->update([
            'status' => BatchStatus::Processing->value,
            'accepted_count' => 0,
        ]);

        ProcessIngestionBatch::dispatch($this->tenant->id, $batchId);

        $batch = IngestionBatch::query()->findOrFail($batchId);

        // Con los contadores acumulados en memoria, el subconteo quedaba para
        // siempre y se publicaba como `counts.pending` en la API.
        expect($batch->accepted_count)->toBe(2)
            ->and($batch->unprocessedCount())->toBe(0)
            ->and($batch->status)->toBe(BatchStatus::Completed);
    });
});

// ─────────────────────────────────────────────────────────────────
// Un solo worker por lote
// ─────────────────────────────────────────────────────────────────

it('no procesa un lote que otro worker ya tiene entre manos', function () {
    enTenantDurable($this->tenant, function () {
        $batch = IngestionBatch::query()->create([
            'entity_type' => EntityType::Orders,
            'source' => IngestSource::DailyStream,
            'status' => BatchStatus::Received,
            'payload_hash' => PayloadHasher::hash([ordenDurable()]),
            'received_count' => 1,
            'received_at' => CarbonImmutable::now(),
        ]);

        RawEvent::query()->create([
            'batch_id' => $batch->id,
            'entity_type' => EntityType::Orders,
            'source_id' => 'ORD-1',
            'payload' => ordenDurable(),
            'content_hash' => PayloadHasher::hash(ordenDurable()),
            'received_at' => CarbonImmutable::now(),
        ]);

        $candado = new UniqueLock(app(CacheRepository::class));
        $job = new ProcessIngestionBatch($this->tenant->id, $batch->id);

        expect($candado->acquire($job))->toBeTrue();

        ProcessIngestionBatch::dispatch($this->tenant->id, $batch->id);

        // El segundo worker no toca nada: sin el candado los dos se reparten
        // los eventos pendientes al azar y el estado final del lote lo
        // escribe el que termine último.
        expect(IngestionBatch::query()->findOrFail($batch->id)->status)->toBe(BatchStatus::Received)
            ->and(Order::query()->count())->toBe(0);

        $candado->release($job);

        ProcessIngestionBatch::dispatch($this->tenant->id, $batch->id);

        expect(IngestionBatch::query()->findOrFail($batch->id)->status)->toBe(BatchStatus::Completed)
            ->and(Order::query()->count())->toBe(1);
    });
});

// ─────────────────────────────────────────────────────────────────
// Entidades que todavía no se normalizan
// ─────────────────────────────────────────────────────────────────

it('no cuenta como aceptado un lote de clientes que no persiste en ningún lado', function () {
    $response = $this->postJson('/api/v1/ingest/customers', [
        'source' => 'daily_stream',
        'records' => [['customer_id' => 'CUS-1', 'name' => 'María', 'email' => 'maria@example.test']],
    ], ['Authorization' => "Bearer {$this->secret}"])->assertStatus(202);

    // Responder `accepted: 1` para un cliente que no se guarda en ningún lado
    // es la clase de mentira que sólo se descubre cuando alguien consulta y
    // no encuentra nada.
    expect($response->json('data.counts.accepted'))->toBe(0)
        ->and($response->json('data.counts.rejected'))->toBe(1);

    enTenantDurable($this->tenant, function () {
        $reject = IngestionReject::query()->where('reason', RejectReason::UnsupportedEntity)->first();

        expect($reject)->not->toBeNull()
            ->and($reject->entity_type)->toBe(EntityType::Customers);
    });
});
