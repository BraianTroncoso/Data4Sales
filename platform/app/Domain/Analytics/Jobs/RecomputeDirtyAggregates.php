<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Jobs;

use App\Domain\Analytics\Actions\RecomputeCustomerMetrics;
use App\Domain\Analytics\Actions\RecomputeDailyFacts;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Recalcula los agregados de los dias marcados como sucios.
 *
 * Es el otro extremo del mecanismo que responde la pregunta de la orden que
 * llega con 60 dias de atraso. La ingesta marca; este trabajo recalcula. El
 * tiempo que la orden "queda mal" es exactamente lo que tarda en llegarle el
 * turno a esa fila, y eso se puede medir mirando la pendiente mas antigua.
 *
 * Es UNICO POR TENANT MIENTRAS ESPERA EN LA COLA, no mientras corre. La
 * distincion importa: durante una carga historica cada lote termina y quiere
 * encolar un recomputo. Con unicidad hasta el final, todos esos avisos se
 * descartarian mientras el primero trabaja, y los dias que ensuciaron los
 * lotes siguientes se quedarian sin recalcular hasta la corrida programada.
 * Liberando el candado al empezar, siempre hay a lo sumo uno esperando turno,
 * que es exactamente lo que se quiere.
 */
class RecomputeDirtyAggregates implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Cuantos dias se toman por vuelta.
     *
     * Una carga historica de tres meses ensucia noventa dias de una vez.
     * Tomarlos de a tandas deja que otros tenants avancen en vez de esperar
     * a que uno termine su backfill entero.
     */
    private const CLAIM_SIZE = 30;

    /**
     * Tope de tandas por ejecucion.
     *
     * Existe para que un tenant con una cola enorme no monopolice un worker
     * indefinidamente. Al llegar al tope el trabajo se reencola, asi otros
     * tenants avanzan y este retoma donde quedo.
     */
    private const MAX_ROUNDS = 20;

    public int $tries = 3;

    public int $timeout = 900;

    public function __construct(
        private readonly string $tenantId,
    ) {}

    public function uniqueId(): string
    {
        return $this->tenantId;
    }

    public function handle(
        TenantContext $context,
        RecomputeDailyFacts $dailyFacts,
        RecomputeCustomerMetrics $customerMetrics,
    ): void {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        $context->run($tenant, function () use ($context, $dailyFacts, $customerMetrics): void {
            $touched = [];

            // Dias que esta corrida ya no vuelve a mirar: los que fallaron y
            // los que otro worker tiene tomados. Siguen pendientes a
            // proposito, pero sin excluirlos la busqueda los devolveria una y
            // otra vez y la vuelta no terminaria nunca.
            $skipped = [];
            $rounds = 0;

            // Se vacia la cola de dias sucios en tandas. Una carga historica
            // de cuatro meses ensucia mas de cien dias de una vez, y procesar
            // una sola tanda dejaria el resto esperando a la corrida
            // programada: el dashboard mostraria medio dataset sin ninguna
            // senal de que falta el resto.
            while ($rounds < self::MAX_ROUNDS) {
                $days = $this->pendingDays($context, $skipped);

                if ($days === []) {
                    break;
                }

                foreach ($days as $day) {
                    if ($this->recomputeDay($context, $dailyFacts, $day)) {
                        $touched[] = $day;
                    } else {
                        $skipped[] = $day;
                    }
                }

                $rounds++;
            }

            if ($touched === []) {
                return;
            }

            // Las metricas por cliente se recalculan UNA vez para todos los
            // afectados, no una por dia: los quintiles necesitan mirar el
            // tenant completo, asi que repetirlo por dia multiplicaria el
            // trabajo mas caro por la cantidad de dias.
            $customerMetrics->execute($this->customersAffectedBy($context, array_values(array_unique($touched))));

            // Si el tope corto el trabajo antes de vaciar la cola, se encola
            // otra vuelta en vez de dejar dias sin recalcular en silencio.
            if ($rounds >= self::MAX_ROUNDS && $this->pendingCount($context) > 0) {
                self::dispatch($this->tenantId);
            }
        });
    }

    /**
     * Dias pendientes, sin tomarlos todavia.
     *
     * @param  list<string>  $skipped  Dias que esta corrida ya descarto.
     * @return list<string>
     */
    private function pendingDays(TenantContext $context, array $skipped): array
    {
        $rows = DB::connection($context->connectionName())->select(
            <<<'SQL'
            SELECT date
            FROM aggregate_dirty_days
            WHERE tenant_id = ?
              AND processed_at IS NULL
              AND NOT (date = ANY(?::date[]))
            ORDER BY created_at
            LIMIT ?
            SQL,
            [$context->currentOrFail()->id, '{'.implode(',', $skipped).'}', self::CLAIM_SIZE],
        );

        return array_map(
            static fn (object $row) => CarbonImmutable::parse($row->date)->format('Y-m-d'),
            $rows,
        );
    }

    /**
     * Recalcula un dia y lo marca procesado, o lo deja pendiente.
     *
     * TOMAR EL DIA Y RECALCULARLO VAN EN LA MISMA TRANSACCION, y ese es el
     * punto entero de este metodo. Marcar primero y commitear antes de
     * recalcular sobrevive a una excepcion (se puede atrapar), pero no a que
     * el proceso desaparezca: un kill -9 o un OOM del contenedor a mitad del
     * recomputo dejaria el dia marcado como procesado y sus agregados sin
     * recalcular. Peor todavia, `freshness()` no lo reportaria, asi que el
     * dashboard mostraria numeros viejos afirmando estar al dia. Con la marca
     * dentro de la transaccion, morir a la mitad no deja rastro y el dia
     * sigue pendiente para la proxima corrida.
     *
     * El UPDATE va primero igual, pero como CANDADO, no como marca durable:
     * SKIP LOCKED hace que dos workers no se lleven el mismo dia, y deja que
     * una orden que llega para ese mismo dia mientras se recalcula espere al
     * commit y estrene una fila sucia nueva, en vez de perderse contra la que
     * estamos por marcar.
     *
     * @return bool Si este worker lo recalculo. False es "sigue pendiente":
     *              lo tiene otro worker, o el recomputo fallo.
     */
    private function recomputeDay(TenantContext $context, RecomputeDailyFacts $dailyFacts, string $day): bool
    {
        $connection = DB::connection($context->connectionName());
        $tenantId = $context->currentOrFail()->id;

        try {
            return $connection->transaction(function () use ($connection, $dailyFacts, $tenantId, $day): bool {
                $claimed = $connection->update(
                    <<<'SQL'
                    UPDATE aggregate_dirty_days
                    SET processed_at = NOW()
                    WHERE id IN (
                        SELECT id
                        FROM aggregate_dirty_days
                        WHERE tenant_id = ? AND date = ? AND processed_at IS NULL
                        FOR UPDATE SKIP LOCKED
                    )
                    SQL,
                    [$tenantId, $day],
                );

                if ($claimed === 0) {
                    return false;
                }

                $dailyFacts->execute($day);

                return true;
            });
        } catch (Throwable $e) {
            // La transaccion ya volvio atras la marca, asi que el dia queda
            // pendiente solo. El recomputo es idempotente: reintentarlo de
            // mas es inofensivo, perderlo dejaria un numero mal para siempre
            // sin ningun sintoma que lo delate.
            Log::error('Falló el recómputo de un día; queda pendiente.', [
                'tenant_id' => $this->tenantId,
                'date' => $day,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function pendingCount(TenantContext $context): int
    {
        $row = DB::connection($context->connectionName())->selectOne(
            'SELECT COUNT(*) AS total FROM aggregate_dirty_days WHERE tenant_id = ? AND processed_at IS NULL',
            [$context->currentOrFail()->id],
        );

        return (int) $row->total;
    }

    /**
     * Clientes con al menos una orden en los dias que se recalcularon.
     *
     * @param  list<string>  $days
     * @return list<string>
     */
    private function customersAffectedBy(TenantContext $context, array $days): array
    {
        $rows = DB::connection($context->connectionName())->select(
            'SELECT DISTINCT customer_id FROM orders WHERE tenant_id = ? AND placed_on = ANY(?)',
            [$context->currentOrFail()->id, '{'.implode(',', $days).'}'],
        );

        return array_map(static fn (object $row) => $row->customer_id, $rows);
    }
}
