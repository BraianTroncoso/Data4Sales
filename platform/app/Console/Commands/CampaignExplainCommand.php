<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Outreach\Dsl\ConditionParser;
use App\Domain\Outreach\Dsl\ExplainCompiler;
use App\Domain\Outreach\Models\CampaignRun;
use App\Domain\Outreach\Models\RecipientDecision;
use App\Domain\Outreach\Models\SendIntent;
use App\Domain\Sales\Models\Customer;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * "¿Por que este cliente recibio, o no recibio, este correo?"
 *
 * El enunciado pide tratar esa pregunta como requisito de PRODUCTO, no de
 * infraestructura. La diferencia practica: la respuesta no sale de leer logs
 * ni de correr consultas a mano, sale de un comando que cualquiera puede
 * ejecutar y entender.
 *
 * La respuesta combina dos fuentes, y distingue cual es cual:
 *
 *   REGISTRADO      lo que efectivamente paso: si entro en la audiencia, si
 *                   se genero una intencion, si salio el correo, si fallo.
 *                   Son hechos guardados en el momento.
 *
 *   RECONSTRUIDO    si cumple o no la condicion, evaluado AHORA. Los datos
 *                   pudieron cambiar desde la ejecucion. Va etiquetado como
 *                   tal, siempre: presentar una reconstruccion como si fuera
 *                   un registro es peor que no responder.
 */
class CampaignExplainCommand extends Command
{
    protected $signature = 'd4s:campaign:explain
        {tenant : Slug del tenant}
        {run : Identificador de la ejecución}
        {customer : Identificador de origen del cliente (external_id) o su uuid}';

    protected $description = 'Explica por qué un cliente recibió, o no recibió, el correo de una ejecución';

    public function handle(TenantContext $context, ConditionParser $parser, ExplainCompiler $explainer): int
    {
        $tenant = Tenant::query()->where('slug', $this->argument('tenant'))->first();

        if ($tenant === null) {
            $this->error('El tenant no existe.');

            return self::INVALID;
        }

        return $context->run($tenant, function () use ($parser, $explainer): int {
            $run = CampaignRun::query()->with('campaign')->find($this->argument('run'));

            if ($run === null) {
                $this->error('La ejecución no existe.');

                return self::INVALID;
            }

            $ref = (string) $this->argument('customer');

            // Sólo se compara contra `id` si la cadena parece un uuid: la
            // columna ES uuid, y PostgreSQL falla al castear un identificador
            // de origen como "LUNA-CUS-000205". Un `orWhere` ingenuo convierte
            // una búsqueda que no encuentra nada en un error de base de datos.
            $esUuid = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $ref) === 1;

            $customer = Customer::query()
                ->where(function ($q) use ($ref, $esUuid): void {
                    $q->where('external_id', $ref);

                    if ($esUuid) {
                        $q->orWhere('id', $ref);
                    }
                })
                ->first();

            if ($customer === null) {
                $this->error("No existe el cliente [{$ref}] en este tenant.");

                return self::INVALID;
            }

            $this->components->info("Cliente {$customer->external_id} · campaña {$run->campaign->name}");

            $this->renderRegistrado($run, $customer->id);
            $this->renderReconstruido($run, $customer->id, $parser, $explainer);

            return self::SUCCESS;
        });
    }

    private function renderRegistrado(CampaignRun $run, string $customerId): void
    {
        $this->newLine();
        $this->line('  <fg=yellow;options=bold>REGISTRADO</> — hechos guardados en el momento de la ejecución');
        $this->newLine();

        $decisions = RecipientDecision::query()
            ->where('run_id', $run->id)
            ->where('customer_id', $customerId)
            ->orderBy('decided_at')
            ->get();

        if ($decisions->isEmpty()) {
            $this->components->twoColumnDetail(
                'Decisión',
                '<fg=gray>sin registro: no entró en la audiencia</>',
            );
        }

        foreach ($decisions as $decision) {
            $this->components->twoColumnDetail(
                ucfirst($decision->decision),
                $decision->reason_code->explain(),
            );
        }

        $intent = SendIntent::query()
            ->where('run_id', $run->id)
            ->where('customer_id', $customerId)
            ->latest('created_at')
            ->first();

        if ($intent === null) {
            $this->components->twoColumnDetail('Envío', '<fg=gray>no se generó ninguna intención</>');

            return;
        }

        $this->components->twoColumnDetail('Estado del envío', $intent->state->value);
        $this->components->twoColumnDetail('Clave de idempotencia', $intent->idempotency_key);

        foreach ($intent->attemptLog()->orderBy('attempt_no')->get() as $attempt) {
            $this->components->twoColumnDetail(
                "Intento #{$attempt->attempt_no}",
                ($attempt->outcome ?? 'sin terminar').($attempt->error_message ? " — {$attempt->error_message}" : ''),
            );
        }
    }

    private function renderReconstruido(
        CampaignRun $run,
        string $customerId,
        ConditionParser $parser,
        ExplainCompiler $explainer,
    ): void {
        $this->newLine();
        $this->line('  <fg=cyan;options=bold>RECONSTRUIDO</> — evaluado AHORA, no en el momento de la ejecución');
        $this->line('  <fg=gray>Los datos del cliente pudieron cambiar desde entonces.</>');
        $this->newLine();

        // Se usa la copia guardada en la ejecución, no la definición actual:
        // si alguien editó la condición después, esto sigue explicando contra
        // qué se resolvió realmente.
        $node = $parser->parse($run->dsl_snapshot);
        $result = $explainer->explain($node, $customerId, $run->as_of);

        $this->components->twoColumnDetail(
            'Cumple la condición',
            $result['matches_condition'] ? '<fg=green>sí</>' : '<fg=red>no</>',
        );

        foreach ($result['nodes'] as $nodo) {
            $marca = $nodo['matched'] ? '<fg=green>✓</>' : '<fg=red>✗</>';

            if (($nodo['type'] ?? null) === 'attr') {
                $this->components->twoColumnDetail(
                    "  {$marca} {$nodo['node_id']} · {$nodo['field']}",
                    "esperado {$nodo['expected']} · actual ".var_export($nodo['actual'], true),
                );

                continue;
            }

            $this->components->twoColumnDetail(
                "  {$marca} {$nodo['node_id']} · órdenes",
                'ventana '.substr($nodo['window']['from'], 0, 10).' → '.substr($nodo['window']['to'], 0, 10),
            );

            foreach ($nodo['clauses'] ?? [] as $clause) {
                $sub = $clause['matched'] ? '<fg=green>✓</>' : '<fg=red>✗</>';
                $this->components->twoColumnDetail(
                    "      {$sub} {$clause['agg']}",
                    "esperado {$clause['expected']} · actual {$clause['actual']}",
                );
            }
        }
    }
}
