<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Jobs;

use App\Domain\Analytics\Jobs\RecomputeDirtyAggregates;
use App\Domain\Ingestion\Actions\ApplyNormalizedOrder;
use App\Domain\Ingestion\Enums\BatchStatus;
use App\Domain\Ingestion\Enums\EntityType;
use App\Domain\Ingestion\Enums\RejectReason;
use App\Domain\Ingestion\Models\IngestionBatch;
use App\Domain\Ingestion\Models\IngestionReject;
use App\Domain\Ingestion\Models\RawEvent;
use App\Domain\Ingestion\Pipeline\OrderNormalizer;
use App\Domain\Ingestion\Pipeline\RejectBag;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Normaliza los registros crudos de un lote y los vuelca a la zona curada.
 *
 * Esta REANUDABLE, que es la propiedad que hace manejable un envio
 * interrumpido. El job no lleva la cuenta de por donde iba: cada vez que
 * corre, busca los eventos crudos que todavia no tienen `processed_at` y
 * sigue por ahi. Si el proceso muere a la mitad, reintentar no reprocesa lo
 * ya hecho ni pierde lo que falta, y no hace falta ningun estado externo
 * para lograrlo.
 *
 * Es tambien el motivo por el que el modelo de datos separa la zona cruda de
 * la curada: sin un lugar donde el registro quede guardado sin interpretar,
 * "reanudar" significaria pedirle al retailer que reenvie.
 *
 * NOTA sobre el tenant: se recibe el identificador, no el modelo. Serializar
 * un modelo en la cola guardaria una copia del tenant como estaba al
 * encolar; resolverlo al ejecutar garantiza que se usa la configuracion
 * vigente, incluida la estrategia de aislamiento si cambio entre medio.
 *
 * Es UNICO POR LOTE MIENTRAS CORRE, no solo mientras espera. Que el trabajo
 * sea reanudable no lo hace seguro de correr dos veces a la vez: dos workers
 * sobre el mismo lote se reparten los eventos pendientes al azar y los dos
 * llegan a `finalise`, con lo que el estado final del lote lo escribe el que
 * termine ultimo. El candado se suelta al terminar, y `uniqueFor` existe para
 * que un worker muerto no deje el lote bloqueado para siempre.
 */
class ProcessIngestionBatch implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Cuantos eventos se traen a memoria por vuelta.
     *
     * Con millones de registros, cargar el lote entero es la diferencia
     * entre un worker que trabaja y uno que muere por memoria.
     */
    private const CHUNK = 200;

    public int $tries = 3;

    public int $timeout = 600;

    /**
     * Vida maxima del candado de unicidad.
     *
     * Holgado respecto de `timeout` por reintento: si se venciera antes de
     * que el ultimo reintento termine, el candado dejaria de proteger nada
     * justo en el caso que existe para cubrir.
     */
    public int $uniqueFor = 3600;

    public function __construct(
        private readonly string $tenantId,
        private readonly string $batchId,
    ) {}

    /**
     * El tenant va en la clave junto al lote: los identificadores son uuid y
     * no chocan, pero una clave que nombra al tenant se puede leer en Horizon
     * y se entiende sin ir a buscar de quien era el lote.
     */
    public function uniqueId(): string
    {
        return "{$this->tenantId}:{$this->batchId}";
    }

    public function handle(
        TenantContext $context,
        OrderNormalizer $normalizer,
        ApplyNormalizedOrder $apply,
    ): void {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            // El tenant desaparecio entre el encolado y la ejecucion. No es
            // un fallo a reintentar: no hay a donde escribir.
            Log::warning('Lote descartado: el tenant ya no existe.', [
                'tenant_id' => $this->tenantId,
                'batch_id' => $this->batchId,
            ]);

            return;
        }

        $context->run($tenant, function () use ($tenant, $normalizer, $apply): void {
            $batch = IngestionBatch::query()->find($this->batchId);

            if ($batch === null || $batch->status === BatchStatus::Completed) {
                return;
            }

            $this->process($batch, $tenant, $normalizer, $apply);
        });
    }

    private function process(
        IngestionBatch $batch,
        Tenant $tenant,
        OrderNormalizer $normalizer,
        ApplyNormalizedOrder $apply,
    ): void {
        $batch->update([
            'status' => BatchStatus::Processing,
            'started_at' => $batch->started_at ?? CarbonImmutable::now(),
        ]);

        try {
            RawEvent::query()
                ->where('batch_id', $batch->id)
                ->whereNull('processed_at')
                ->orderBy('id')
                ->chunkById(self::CHUNK, function ($events) use ($batch, $tenant, $normalizer, $apply): void {
                    foreach ($events as $event) {
                        $this->processEvent($event, $batch, $tenant, $normalizer, $apply);
                    }

                    // Se persisten las cuentas por trozo, no al final: si el
                    // proceso muere, el progreso parcial sigue siendo
                    // consultable en vez de perderse.
                    $this->refreshCounts($batch);
                });
        } catch (Throwable $e) {
            $batch->update([
                'status' => BatchStatus::Failed,
                'failure_reason' => $e->getMessage(),
                'finished_at' => CarbonImmutable::now(),
            ]);

            throw $e;
        }

        $this->finalise($batch);
    }

    private function processEvent(
        RawEvent $event,
        IngestionBatch $batch,
        Tenant $tenant,
        OrderNormalizer $normalizer,
        ApplyNormalizedOrder $apply,
    ): void {
        // Por ahora solo se normalizan ordenes: traen su cliente y sus items
        // anidados, que es como los mandan los retailers. Clientes y
        // productos sueltos quedan registrados en crudo para una fase
        // posterior.
        //
        // No se cuentan como aceptados. "Aceptado" en esta API significa que
        // el registro llego a la zona curada, y decirselo a un emisor cuyos
        // clientes no se guardaron en ningun lado seria la clase de mentira
        // que solo se descubre cuando alguien consulta y no encuentra nada.
        if ($event->entity_type !== EntityType::Orders) {
            $bag = new RejectBag;

            $bag->add(
                RejectReason::UnsupportedEntity,
                "Los lotes de [{$event->entity_type->value}] se guardan en crudo pero todavía no se normalizan; sólo se aplican órdenes, que ya traen su comprador y sus renglones anidados.",
            );

            $this->recordRejects($bag, $event, $batch);
            $event->update(['processed_at' => CarbonImmutable::now(), 'outcome' => 'rejected']);

            return;
        }

        $bag = new RejectBag;
        $normalized = $normalizer->normalize($event->payload, $tenant, $bag);

        // Los rechazos se guardan SIEMPRE, incluso los que no descartan el
        // registro: es lo que permite que la tabla de suciedad del README
        // sea una consulta sobre datos reales.
        $this->recordRejects($bag, $event, $batch);

        if ($normalized === null) {
            $event->update(['processed_at' => CarbonImmutable::now(), 'outcome' => 'rejected']);

            return;
        }

        $result = $apply->execute($normalized, $event->id);

        if ($result === ApplyNormalizedOrder::RESULT_STALE) {
            IngestionReject::query()->insert([
                'tenant_id' => $batch->tenant_id,
                'batch_id' => $batch->id,
                'raw_event_id' => $event->id,
                'entity_type' => $event->entity_type->value,
                'source_id' => $event->source_id,
                'reason' => RejectReason::StaleUpdate->value,
                'field' => 'updated_at',
                'message' => 'La actualización es más vieja que el estado ya guardado; se conservó el más nuevo.',
                'payload' => null,
                'created_at' => CarbonImmutable::now(),
            ]);
        }

        $event->update(['processed_at' => CarbonImmutable::now(), 'outcome' => 'accepted']);
    }

    /**
     * Recalcula las cuentas del lote desde los eventos, en vez de acumularlas.
     *
     * Acumular en memoria y guardar cada tanto tiene una ventana fatal: si el
     * proceso muere entre marcar los eventos como procesados y guardar el
     * contador, el reintento se saltea esos eventos (ya estan marcados) y la
     * cuenta queda corta PARA SIEMPRE. Y esa cuenta corta se publica como
     * `counts.pending` en la API, o sea que el emisor ve un faltante que ya
     * ingirio.
     *
     * Derivarlas de la tabla las vuelve una consulta sobre el estado real,
     * asi que un reintento las corrige solo.
     */
    private function refreshCounts(IngestionBatch $batch): void
    {
        $row = $batch->getConnection()->selectOne(
            <<<'SQL'
            SELECT
                COUNT(*) FILTER (WHERE outcome = 'accepted') AS accepted,
                COUNT(*) FILTER (WHERE outcome = 'rejected') AS rejected
            FROM raw_events
            WHERE batch_id = ?
            SQL,
            [$batch->id],
        );

        $batch->update([
            'accepted_count' => (int) ($row->accepted ?? 0),
            'rejected_count' => (int) ($row->rejected ?? 0),
        ]);
    }

    private function recordRejects(RejectBag $bag, RawEvent $event, IngestionBatch $batch): void
    {
        if ($bag->isEmpty()) {
            return;
        }

        $now = CarbonImmutable::now();
        $rows = [];

        foreach ($bag->all() as $reject) {
            $rows[] = [
                'tenant_id' => $batch->tenant_id,
                'batch_id' => $batch->id,
                'raw_event_id' => $event->id,
                'entity_type' => $event->entity_type->value,
                'source_id' => $event->source_id,
                'reason' => $reject['reason']->value,
                'field' => $reject['field'],
                'message' => $reject['message'],
                'payload' => $reject['payload'] !== null ? json_encode($reject['payload']) : null,
                'created_at' => $now,
            ];
        }

        IngestionReject::query()->insert($rows);
    }

    /**
     * Decide el estado final del lote.
     *
     * `partial` no es un fallo: es la descripcion honesta de un envio que
     * llego incompleto. Distinguirlo de `completed` es lo que permite que
     * alguien sepa que tiene que reenviar, y distinguirlo de `failed` es lo
     * que evita que descarte lo que si llego.
     */
    private function finalise(IngestionBatch $batch): void
    {
        // Una ultima pasada por las cuentas: si una corrida anterior murio
        // despues de marcar eventos y antes de contarlos, este es el momento
        // en que el lote se cura solo.
        $this->refreshCounts($batch);

        $pending = RawEvent::query()
            ->where('batch_id', $batch->id)
            ->whereNull('processed_at')
            ->count();

        // Dos formas distintas de quedar incompleto, y las dos importan:
        // que falten registros por procesar (el worker murio a mitad) o que
        // hayan llegado menos de los anunciados (el envio se corto en la red).
        $incomplete = $pending > 0 || $batch->arrivedIncomplete();

        $batch->update([
            'status' => $incomplete ? BatchStatus::Partial : BatchStatus::Completed,
            'finished_at' => CarbonImmutable::now(),
            'failure_reason' => $this->describeIncompleteness($batch, $pending),
        ]);

        // El recomputo de agregados se dispara aca y no dentro del bucle: un
        // lote de diez mil ordenes ensucia los mismos dias una y otra vez, y
        // encolar por cada una seria encolar diez mil veces el mismo trabajo.
        RecomputeDirtyAggregates::dispatch($batch->tenant_id);
    }

    /**
     * Explica en una linea por que el lote no quedo completo.
     *
     * Un estado `partial` sin explicacion obliga al retailer a adivinar si
     * tiene que reenviar. Con la explicacion, sabe exactamente que le falta.
     */
    private function describeIncompleteness(IngestionBatch $batch, int $pending): ?string
    {
        $reasons = [];

        if ($batch->arrivedIncomplete()) {
            $missing = $batch->expected_count - $batch->received_count;
            $reasons[] = "Envío incompleto: se anunciaron {$batch->expected_count} registros y llegaron {$batch->received_count}. Faltan {$missing}.";
        }

        if ($pending > 0) {
            $reasons[] = "Quedaron {$pending} registros sin procesar; reintentar el lote los retoma sin duplicar lo ya ingerido.";
        }

        return $reasons === [] ? null : implode(' ', $reasons);
    }
}
