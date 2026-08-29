<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Actions;

use App\Domain\Ingestion\Enums\BatchStatus;
use App\Domain\Ingestion\Enums\EntityType;
use App\Domain\Ingestion\Enums\IngestSource;
use App\Domain\Ingestion\Jobs\ProcessIngestionBatch;
use App\Domain\Ingestion\Models\IngestionBatch;
use App\Domain\Ingestion\Support\PayloadHasher;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Recibe un lote de la API y lo deja listo para procesar.
 *
 * El reparto entre sincronico y asincronico es la decision central:
 *
 *   SINCRONICO   guardar el lote y sus registros crudos en disco.
 *   ASINCRONICO  normalizarlos y volcarlos a las tablas de negocio.
 *
 * Va en ese orden porque responder 202 significa "me hago cargo de esto".
 * Si el guardado fuera asincronico, una caida entre la respuesta y la
 * escritura perderia datos que el retailer ya considera entregados, y el
 * sintoma seria un faltante que nadie puede explicar.
 *
 * Persistir crudo es barato: es una insercion masiva sin interpretar nada.
 * Normalizar es lo caro, y es lo que se difiere.
 */
final class ReceiveBatch
{
    /**
     * Cuantos registros se insertan por sentencia.
     *
     * Con lotes de decenas de miles, una sola insercion gigante hace crecer
     * la memoria del proceso y bloquea la tabla mas tiempo del necesario.
     */
    private const INSERT_CHUNK = 500;

    public function __construct(
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $records
     */
    public function execute(
        EntityType $entityType,
        IngestSource $source,
        array $records,
        ?string $idempotencyKey,
        ?int $expectedCount = null,
    ): IngestionBatch {
        $tenant = $this->context->currentOrFail();
        $now = CarbonImmutable::now();

        // El lote y sus registros crudos entran en la MISMA transaccion. Si
        // el proceso muriera entre las dos escrituras, quedaria un lote
        // fantasma que nunca se completa: visible en la API, con estado
        // `received` y sin un solo evento que procesar. Lo caro sigue
        // ocurriendo por trozos adentro, que es lo que evita cargar decenas
        // de miles de filas en memoria de una vez.
        $batchId = DB::connection($this->context->connectionName())->transaction(
            function () use ($tenant, $entityType, $source, $records, $idempotencyKey, $expectedCount, $now): ?string {
                $batchId = $this->insertBatch($tenant->id, $entityType, $source, $records, $idempotencyKey, $expectedCount, $now);

                if ($batchId === null) {
                    return null;
                }

                $duplicates = $this->persistRawEvents($batchId, $tenant->id, $entityType, $records, $now);

                if ($duplicates > 0) {
                    DB::connection($this->context->connectionName())->update(
                        'UPDATE ingestion_batches SET duplicate_count = ?, updated_at = ? WHERE id = ?',
                        [$duplicates, $now, $batchId],
                    );
                }

                return $batchId;
            },
        );

        if ($batchId === null) {
            // Otro request gano la carrera con la misma clave: el lote que
            // corresponde devolver es el suyo, no uno nuevo.
            return $this->batchWithKey($idempotencyKey);
        }

        // El trabajo se encola DESPUES del commit, nunca adentro. Encolarlo
        // adentro abre la ventana en la que un worker toma el trabajo y busca
        // un lote que todavia no existe para nadie mas que para esta
        // transaccion.
        ProcessIngestionBatch::dispatch($tenant->id, $batchId);

        // Se relee despues de encolar y no antes: con la cola en modo
        // sincronico el trabajo ya corrio, y devolver la fila vieja mostraria
        // las cuentas en cero para un lote que ya esta procesado.
        return IngestionBatch::query()->findOrFail($batchId);
    }

    /**
     * Inserta el lote, o devuelve null si ya existe uno con esa clave.
     *
     * ── Idempotencia a nivel lote ─────────────────────────────────
     * Reenviar el mismo lote tras un timeout de red devuelve el lote original
     * en vez de crear uno nuevo. Sin esto, un reintento de un cliente
     * impaciente duplicaria el trabajo (aunque no los datos, que igual estan
     * protegidos por el hash de contenido).
     *
     * La decide el motor con el indice unico, no la aplicacion con un SELECT
     * previo, y por el mismo motivo que la deduplicacion de eventos: entre
     * leer y escribir hay una ventana, y dos reintentos simultaneos del mismo
     * cliente caben ahi. El que perdia la carrera se estrellaba contra la
     * violacion de unico y devolvia un 500, justo en el caso que esta
     * funcionalidad existe para resolver.
     *
     * @param  list<array<string, mixed>>  $records
     */
    private function insertBatch(
        string $tenantId,
        EntityType $entityType,
        IngestSource $source,
        array $records,
        ?string $idempotencyKey,
        ?int $expectedCount,
        CarbonImmutable $now,
    ): ?string {
        $id = (string) Str::orderedUuid();

        $rows = DB::connection($this->context->connectionName())->select(
            'INSERT INTO ingestion_batches ('
            .'id, tenant_id, idempotency_key, entity_type, source, status, payload_hash, '
            .'expected_count, received_count, received_at, created_at, updated_at'
            .') VALUES (?,?,?,?,?,?,?,?,?,?,?,?) '
            .'ON CONFLICT (tenant_id, idempotency_key) DO NOTHING '
            .'RETURNING id',
            [
                $id,
                $tenantId,
                $idempotencyKey,
                $entityType->value,
                $source->value,
                BatchStatus::Received->value,
                PayloadHasher::hash($records),
                $expectedCount,
                count($records),
                $now,
                $now,
                $now,
            ],
        );

        // Con clave nula no hay conflicto posible: en PostgreSQL dos nulos no
        // son iguales, asi que un envio sin clave de idempotencia siempre
        // estrena lote.
        return $rows === [] ? null : $id;
    }

    /**
     * El lote que gano la carrera por la clave de idempotencia.
     */
    private function batchWithKey(?string $idempotencyKey): IngestionBatch
    {
        $existing = IngestionBatch::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing === null) {
            // Inalcanzable en la practica: el ON CONFLICT solo no devuelve
            // fila si hay un lote con esa clave, y los lotes no se borran.
            // Se prefiere fallar ruidosamente antes que devolver un lote
            // inventado que el emisor tomaria por bueno.
            throw new RuntimeException(
                "El lote con clave de idempotencia [{$idempotencyKey}] desaparecio entre el conflicto y la relectura."
            );
        }

        return $existing;
    }

    /**
     * Guarda los registros crudos y devuelve cuantos eran duplicados exactos.
     *
     * La deduplicacion la hace el motor con un indice unico, no la
     * aplicacion con una consulta previa. Es la diferencia entre una
     * garantia y una probabilidad: dos workers insertando el mismo lote en
     * paralelo no pueden colarse entre el SELECT y el INSERT del otro.
     *
     * @param  list<array<string, mixed>>  $records
     */
    private function persistRawEvents(
        string $batchId,
        string $tenantId,
        EntityType $entityType,
        array $records,
        CarbonImmutable $now,
    ): int {
        $connection = $this->context->connectionName();
        $inserted = 0;

        foreach (array_chunk($records, self::INSERT_CHUNK) as $chunk) {
            $values = [];
            $bindings = [];

            foreach ($chunk as $record) {
                $values[] = '(?,?,?,?,?,?,?)';

                $bindings[] = $tenantId;
                $bindings[] = $batchId;
                $bindings[] = $entityType->value;
                $bindings[] = $this->extractSourceId($record);
                $bindings[] = json_encode($record, JSON_UNESCAPED_UNICODE);
                $bindings[] = PayloadHasher::hash($record);
                $bindings[] = $now;
            }

            $rows = DB::connection($connection)->select(
                'INSERT INTO raw_events (tenant_id, batch_id, entity_type, source_id, payload, content_hash, received_at) '
                .'VALUES '.implode(',', $values).' '
                .'ON CONFLICT (tenant_id, content_hash) DO NOTHING '
                .'RETURNING id',
                $bindings,
            );

            $inserted += count($rows);
        }

        return count($records) - $inserted;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function extractSourceId(array $record): ?string
    {
        foreach (['order_id', 'customer_id', 'product_id', 'external_id', 'id'] as $key) {
            $value = $record[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return Str::limit(trim($value), 128, '');
            }

            if (is_int($value)) {
                return (string) $value;
            }
        }

        return null;
    }
}
