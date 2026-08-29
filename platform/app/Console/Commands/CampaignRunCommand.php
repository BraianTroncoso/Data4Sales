<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Outreach\Actions\DispatchIntents;
use App\Domain\Outreach\Actions\MaterializeStepIntents;
use App\Domain\Outreach\Actions\ResolveAudience;
use App\Domain\Outreach\Dsl\SqlCompiler;
use App\Domain\Outreach\Models\Campaign;
use App\Domain\Outreach\Models\CampaignRun;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Ejecuta una campana de punta a punta.
 *
 * Es el recorte vertical de la Parte 3: una condicion se resuelve contra el
 * dataset real y los correos salen contra Mailpit.
 *
 *   php artisan d4s:campaign:run luna win-back --dry-run
 *   php artisan d4s:campaign:run luna win-back --send
 */
class CampaignRunCommand extends Command
{
    protected $signature = 'd4s:campaign:run
        {tenant : Slug del tenant}
        {campaign : Slug de la campaña}
        {--dry-run : Sólo resolver la audiencia y mostrar el conteo}
        {--send : Congelar la audiencia y enviar}
        {--chunk=100 : Destinatarios por tanda de envío}';

    protected $description = 'Resuelve la audiencia de una campaña y, opcionalmente, envía';

    public function handle(
        TenantContext $context,
        ResolveAudience $resolve,
        MaterializeStepIntents $materialize,
        DispatchIntents $dispatch,
    ): int {
        $tenant = Tenant::query()->where('slug', $this->argument('tenant'))->first();

        if ($tenant === null) {
            $this->error("El tenant [{$this->argument('tenant')}] no existe.");

            return self::INVALID;
        }

        if (! $this->option('dry-run') && ! $this->option('send')) {
            $this->error('Elegí --dry-run o --send. Sin bandera no hago nada, a propósito.');

            return self::INVALID;
        }

        try {
            return $context->run($tenant, fn () => $this->runCampaign($tenant, $resolve, $materialize, $dispatch));
        } catch (Throwable $e) {
            $this->newLine();
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * El nombre no es `execute`: ese metodo ya existe en la clase base de
     * Symfony Console con visibilidad protegida, y redeclararlo privado hace
     * fallar la carga de la aplicacion entera.
     */
    private function runCampaign(
        Tenant $tenant,
        ResolveAudience $resolve,
        MaterializeStepIntents $materialize,
        DispatchIntents $dispatch,
    ): int {
        $campaign = Campaign::query()
            ->with(['audienceDefinition', 'steps.templateVersion'])
            ->where('slug', $this->argument('campaign'))
            ->first();

        if ($campaign === null) {
            $this->error("La campaña [{$this->argument('campaign')}] no existe en [{$tenant->slug}].");

            return self::INVALID;
        }

        // El instante congelado. Todo lo que sigue se decide contra esto y no
        // contra "ahora": si el proceso se demora, la ventana temporal de la
        // condición no se corre sola.
        $asOf = CarbonImmutable::now();

        $this->components->info("Campaña [{$campaign->name}] · tenant [{$tenant->name}] · as_of {$asOf->toIso8601String()}");

        $preview = $resolve->preview($campaign->audienceDefinition, $asOf);

        $this->newLine();
        $this->components->twoColumnDetail('Audiencia', (string) $preview['count'].' destinatarios');
        $this->components->twoColumnDetail(
            'Frescura de métricas',
            $preview['metrics_lag_seconds'] === null
                ? 'al día'
                : $preview['metrics_lag_seconds'].' s de atraso',
        );

        if ($preview['stale']) {
            // No se bloquea el envío: se avisa. Bloquearlo automáticamente
            // convertiría un retraso de agregados en una campaña que no sale,
            // que a veces es peor que una que sale con datos de hace un rato.
            $this->newLine();
            $this->warn('  Los agregados están atrasados. La condición usa métricas derivadas, así que');
            $this->warn('  algunos destinatarios podrían no reflejar sus compras más recientes.');
        }

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->line('  Ensayo: no se creó ninguna ejecución ni se envió nada.');

            return self::SUCCESS;
        }

        $step = $campaign->steps->first();

        if ($step === null) {
            $this->error('La campaña no tiene ningún paso configurado.');

            return self::INVALID;
        }

        // La ejecución guarda una COPIA de la condición, no una referencia.
        // Si alguien edita la definición después, esta ejecución sigue
        // pudiendo explicar contra qué se resolvió.
        $run = CampaignRun::create([
            'tenant_id' => $tenant->id,
            'campaign_id' => $campaign->id,
            'as_of' => $asOf,
            'dsl_snapshot' => $campaign->audienceDefinition->dsl,
            'compiler_version' => SqlCompiler::VERSION,
            'sql_fingerprint' => $preview['query']->fingerprint,
            'status' => 'resolving',
            'metrics_lag_seconds' => $preview['metrics_lag_seconds'],
            'started_at' => CarbonImmutable::now(),
        ]);

        $frozen = $resolve->freeze($run, $preview['query']);

        $this->newLine();
        $this->components->twoColumnDetail('<fg=green>Congelados</>', (string) $frozen['included']);
        $this->components->twoColumnDetail('Excluidos por baja', (string) $frozen['suppressed']);
        $this->components->twoColumnDetail('Excluidos por email repetido', (string) $frozen['duplicates']);

        $creadas = $materialize->execute($run, $step);
        $run->update(['status' => 'sending']);

        $this->newLine();
        $this->components->info("Enviando {$creadas} mensajes…");

        $totales = ['sent' => 0, 'suppressed' => 0, 'expired' => 0, 'failed' => 0, 'unresolved' => 0, 'cancelled' => 0];
        $bar = $this->output->createProgressBar($creadas);
        $bar->start();

        // Tandas hasta vaciar. Cada vuelta relee la orden de pausa.
        while (true) {
            $stats = $dispatch->execute($run, $step, (int) $this->option('chunk'));

            if ($stats['paused'] === true) {
                $this->newLine(2);
                $this->warn('  La campaña está pausada. Se detiene el despacho.');
                break;
            }

            // Todos los desenlaces cuentan como procesados, incluidos los que
            // no son un envio. Si `unresolved` quedara afuera, una tanda entera
            // caida por timeout daria cero procesados, el bucle cortaria y el
            // resto de la cola no se enviaria nunca.
            $procesados = $stats['sent'] + $stats['suppressed'] + $stats['expired']
                + $stats['failed'] + $stats['unresolved'] + $stats['cancelled'];

            if ($procesados === 0) {
                break;
            }

            foreach ($totales as $k => $_) {
                $totales[$k] += $stats[$k];
            }

            $bar->advance($procesados);
        }

        $bar->finish();
        $run->update(['status' => 'completed', 'finished_at' => CarbonImmutable::now()]);

        $this->newLine(2);
        $this->components->twoColumnDetail('<fg=green>Enviados</>', (string) $totales['sent']);
        $this->components->twoColumnDetail('Suprimidos al enviar', (string) $totales['suppressed']);
        $this->components->twoColumnDetail('Vencidos', (string) $totales['expired']);
        $this->components->twoColumnDetail('<fg=red>Fallidos</>', (string) $totales['failed']);
        $this->components->twoColumnDetail(
            '<fg=yellow>Sin resolver</>',
            (string) $totales['unresolved'].' <fg=gray>(se llamó al proveedor y no sabemos cómo terminó)</>',
        );
        $this->components->twoColumnDetail(
            'Cancelados',
            (string) $totales['cancelled'].' <fg=gray>(el destinatario ya no existe)</>',
        );

        if ($totales['unresolved'] > 0) {
            $this->newLine();
            // No se reintentan solas: reintentar puede mandar el mismo correo
            // dos veces. El recolector las deja a la vista de una persona.
            $this->warn('  Quedaron intenciones sin resolver. Pasalas a revisión con:');
            $this->line("  <fg=cyan>php artisan d4s:campaign:reap {$tenant->slug} --run={$run->id}</>");
        }

        $this->newLine();
        $this->line('  Los correos están en Mailpit: <fg=cyan>http://localhost:8025</>');
        $this->line('  Por qué recibió (o no) un cliente:');
        $this->line("  <fg=cyan>php artisan d4s:campaign:explain {$tenant->slug} {$run->id} {CLIENTE}</>");

        return self::SUCCESS;
    }
}
