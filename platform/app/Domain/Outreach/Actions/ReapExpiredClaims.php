<?php

declare(strict_types=1);

namespace App\Domain\Outreach\Actions;

use App\Domain\Outreach\Enums\IntentState;
use App\Domain\Outreach\Models\CampaignRun;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * El recolector que la maquina de estados daba por existente.
 *
 * `DispatchIntents` escribe `claim_expires_at` al reclamar y lo borra al
 * terminar, pero nadie lo LEIA. La consecuencia es que la garantia que el
 * diseno enuncia —"un corte deja a lo sumo una intencion en `unresolved`,
 * visible"— no se cumplia por ninguno de los dos lados:
 *
 *   - `claim()` selecciona solo `state = 'queued'`. Una intencion que quedo en
 *     `claimed` porque murio el worker no volvia a ser elegible NUNCA. No era
 *     "una intencion visible": era una fuga permanente y silenciosa, y con el
 *     bug de la tanda que estallaba entera podian ser cien de golpe.
 *   - `Unresolved` no se asignaba en ningun lado. El estado existia en el
 *     enum, en el CHECK de la migracion y en la documentacion, y no habia
 *     forma de llegar a el.
 *
 * Las dos mitades del barrido son deliberadamente asimetricas, y esa asimetria
 * ES la decision de D-24:
 *
 *   claimed vencido  -> `queued`. Todavia no se llamo al proveedor: nadie
 *                      recibio nada y reintentar no puede duplicar. Devolverlo
 *                      a la cola es la opcion barata y segura.
 *   in_flight vencido-> `unresolved`. Ya se llamo y no sabemos como termino.
 *                      Reintentar puede mandar el mensaje dos veces, asi que
 *                      el sistema NO decide: deja el caso donde una persona lo
 *                      ve. Preferir la revision manual sobre el duplicado
 *                      silencioso es todo el punto de separar los dos estados.
 *
 * El barrido esta acotado por tenant y corre bajo el contexto activo: RLS ya
 * lo garantiza, y el filtro explicito por `tenant_id` es la segunda cerradura
 * sobre el mismo punto, como en el resto del sistema.
 */
final class ReapExpiredClaims
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  CampaignRun|null  $run  acota el barrido a una ejecucion; null barre el tenant entero
     * @return array{requeued: int, unresolved: int}
     */
    public function execute(?CampaignRun $run = null, bool $dryRun = false): array
    {
        if ($dryRun) {
            return [
                'requeued' => $this->count(IntentState::Claimed, $run),
                'unresolved' => $this->count(IntentState::InFlight, $run),
            ];
        }

        return [
            'requeued' => $this->sweep(IntentState::Claimed, IntentState::Queued, $run),
            'unresolved' => $this->sweep(IntentState::InFlight, IntentState::Unresolved, $run),
        ];
    }

    /**
     * Mueve las intenciones vencidas de un estado al siguiente.
     *
     * `claimed_by` se limpia siempre: el worker que la tenia ya no la tiene, y
     * dejar su nombre puesto haria que el explicador atribuya el caso a un
     * proceso que puede llevar horas muerto.
     *
     * `claim_expires_at` tambien, porque el reclamo dejo de existir. En la
     * rama `unresolved` eso ademas hace el barrido idempotente: correrlo dos
     * veces no vuelve a contar lo mismo.
     */
    private function sweep(IntentState $from, IntentState $to, ?CampaignRun $run): int
    {
        [$filtroRun, $bindings] = $this->runFilter($run);

        $filas = DB::connection($this->connection())->select(
            <<<SQL
            UPDATE send_intents
            SET state = ?,
                claimed_by = NULL,
                claim_expires_at = NULL,
                updated_at = NOW()
            WHERE tenant_id = ?
              AND state = ?
              AND claim_expires_at IS NOT NULL
              AND claim_expires_at < NOW(){$filtroRun}
            RETURNING id
            SQL,
            [$to->value, $this->tenantId(), $from->value, ...$bindings],
        );

        return count($filas);
    }

    private function count(IntentState $state, ?CampaignRun $run): int
    {
        [$filtroRun, $bindings] = $this->runFilter($run);

        return (int) DB::connection($this->connection())->selectOne(
            <<<SQL
            SELECT COUNT(*) AS n
            FROM send_intents
            WHERE tenant_id = ?
              AND state = ?
              AND claim_expires_at IS NOT NULL
              AND claim_expires_at < NOW(){$filtroRun}
            SQL,
            [$this->tenantId(), $state->value, ...$bindings],
        )->n;
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function runFilter(?CampaignRun $run): array
    {
        return $run === null
            ? ['', []]
            : ["\n              AND run_id = ?", [$run->id]];
    }

    private function tenantId(): string
    {
        return $this->context->currentOrFail()->id;
    }

    private function connection(): string
    {
        return $this->context->connectionName();
    }
}
