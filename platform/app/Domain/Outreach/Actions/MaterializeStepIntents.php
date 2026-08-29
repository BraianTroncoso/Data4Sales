<?php

declare(strict_types=1);

namespace App\Domain\Outreach\Actions;

use App\Domain\Outreach\Models\CampaignRun;
use App\Domain\Outreach\Models\CampaignStep;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/**
 * Convierte una audiencia congelada en intenciones de envio.
 *
 * La clave de idempotencia es un uuid v5 determinista de (ejecucion, paso,
 * cliente). Determinista importa: materializar dos veces la misma tanda
 * produce exactamente las mismas claves, y el indice unico de la base rechaza
 * la segunda. La barrera contra el doble envio esta en el motor, no en un
 * `if` de la aplicacion que puede perder una carrera.
 *
 * Se hace con INSERT ... SELECT en vez de recorrer en PHP porque con
 * cientos de miles de destinatarios traerlos a memoria para volver a
 * escribirlos es exactamente el trabajo que no hay que hacer.
 */
final class MaterializeStepIntents
{
    /**
     * Espacio de nombres del uuid v5. Fijo y propio del sistema: si cambiara,
     * todas las claves cambiarian y el anti-duplicado dejaria de reconocer lo
     * ya enviado.
     */
    private const NAMESPACE = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';

    public function __construct(
        private readonly TenantContext $context,
    ) {}

    /**
     * @return int cuantas intenciones nuevas se crearon
     */
    public function execute(CampaignRun $run, CampaignStep $step): int
    {
        $tenantId = $this->context->currentOrFail()->id;

        // La espera del paso se aplica sobre el instante congelado, no sobre
        // el momento en que se materializa: si el proceso se demora, el
        // mensaje no se corre solo.
        //
        // `toImmutable()` no es redundante con el cast del modelo: es la
        // garantia de que este calculo no depende de como esté casteado un
        // atributo en otro archivo. Con un Carbon mutable, `addSeconds` muta
        // el objeto y el segundo calculo parte de un valor ya corrido.
        $asOf = $run->as_of->toImmutable();

        $scheduledFor = $asOf->addSeconds($step->wait_before_seconds);

        $expiresAt = $step->valid_for_seconds === null
            ? null
            : $scheduledFor->addSeconds($step->valid_for_seconds);

        $creadas = 0;

        // El uuid v5 se calcula en PHP porque PostgreSQL no trae uuid_generate_v5
        // sin la extensión uuid-ossp, y no vale la pena pedir una extensión
        // para esto. Se insertan en tandas.
        $run->recipients()
            ->select(['id', 'customer_id'])
            ->orderBy('id')
            ->chunkById(1000, function ($recipients) use (&$creadas, $run, $step, $tenantId, $scheduledFor, $expiresAt): void {
                $values = [];
                $params = [];

                foreach ($recipients as $recipient) {
                    // Ramsey directo y no el helper de Laravel: `Str::uuid5`
                    // no existe en Laravel 11, sólo `Str::uuid` (v4, aleatorio).
                    // Un v4 acá rompería toda la idempotencia: la clave dejaría
                    // de ser derivable de (ejecución, paso, cliente) y
                    // materializar dos veces crearía dos intenciones.
                    $key = Uuid::uuid5(
                        self::NAMESPACE,
                        $run->id.'|'.$step->id.'|'.$recipient->customer_id,
                    )->toString();

                    $values[] = '(?,?,?,?,?,?,?,?,?,?,NOW(),NOW())';
                    array_push(
                        $params,
                        (string) Str::uuid(), $tenantId, $run->id, $step->id,
                        $recipient->id, $recipient->customer_id, $key,
                        'queued', $scheduledFor, $expiresAt,
                    );
                }

                $inserted = DB::connection($this->context->connectionName())->select(
                    'INSERT INTO send_intents '
                    .'(id, tenant_id, run_id, step_id, recipient_id, customer_id, idempotency_key, state, scheduled_for, expires_at, created_at, updated_at) '
                    .'VALUES '.implode(',', $values).' '
                    // Si ya existe, no se toca. Es lo que hace seguro
                    // reintentar la materializacion de una tanda a medias.
                    .'ON CONFLICT (tenant_id, idempotency_key) DO NOTHING '
                    .'RETURNING id',
                    $params,
                );

                $creadas += count($inserted);
            });

        return $creadas;
    }
}
