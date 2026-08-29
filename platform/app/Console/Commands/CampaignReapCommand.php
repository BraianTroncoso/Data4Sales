<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Outreach\Actions\ReapExpiredClaims;
use App\Domain\Outreach\Models\CampaignRun;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Barre los reclamos vencidos que un worker muerto dejo atras.
 *
 * Es la pieza que D-24 da por existente cuando dice que un corte deja "a lo
 * sumo una intencion en `unresolved`, visible". Sin este comando la frase era
 * falsa por partida doble: nada volvia a poner en cola lo que quedo en
 * `claimed`, y nada llegaba nunca a `unresolved`.
 *
 *   php artisan d4s:campaign:reap luna
 *   php artisan d4s:campaign:reap luna --run=UUID --dry-run
 *
 * Pensado para correr periodicamente. El intervalo no es libre: tiene que ser
 * comodamente mayor que lo que tarda una tanda de envio, porque devolver a
 * `queued` una intencion que un worker vivo todavia tiene reclamada es
 * habilitar que dos workers la tomen. El TTL del reclamo (cinco minutos) es el
 * margen que define ese "comodamente".
 */
class CampaignReapCommand extends Command
{
    protected $signature = 'd4s:campaign:reap
        {tenant : Slug del tenant}
        {--run= : Acotar a una ejecución concreta}
        {--dry-run : Sólo contar, sin tocar nada}';

    protected $description = 'Devuelve a la cola los reclamos vencidos y marca `unresolved` lo que quedó en vuelo';

    public function handle(TenantContext $context, ReapExpiredClaims $reaper): int
    {
        $tenant = Tenant::query()->where('slug', $this->argument('tenant'))->first();

        if ($tenant === null) {
            $this->error("El tenant [{$this->argument('tenant')}] no existe.");

            return self::INVALID;
        }

        return $context->run($tenant, function () use ($tenant, $reaper): int {
            $run = null;

            if ($this->option('run') !== null) {
                $run = CampaignRun::query()->find($this->option('run'));

                if ($run === null) {
                    $this->error('La ejecución no existe en este tenant.');

                    return self::INVALID;
                }
            }

            $dryRun = (bool) $this->option('dry-run');
            $stats = $reaper->execute($run, $dryRun);

            $this->components->info(
                "Recolector · tenant [{$tenant->slug}]".($run === null ? '' : " · ejecución {$run->id}")
                .($dryRun ? ' · ENSAYO' : '')
            );

            $this->newLine();
            $this->components->twoColumnDetail(
                'Devueltas a la cola <fg=gray>(claimed vencido)</>',
                (string) $stats['requeued'],
            );
            $this->components->twoColumnDetail(
                '<fg=yellow>Sin resolver</> <fg=gray>(in_flight vencido)</>',
                (string) $stats['unresolved'],
            );

            if ($dryRun) {
                $this->newLine();
                $this->line('  Ensayo: no se tocó ninguna intención.');

                return self::SUCCESS;
            }

            // Queda registrado aunque nadie esté mirando la consola: este
            // comando corre desatendido, y el número de `unresolved` es el
            // único dato que dice cuánta incertidumbre acumuló el sistema.
            Log::info('outreach.reap', [
                'tenant' => $tenant->slug,
                'run_id' => $run?->id,
                'requeued' => $stats['requeued'],
                'unresolved' => $stats['unresolved'],
            ]);

            if ($stats['unresolved'] > 0) {
                $this->newLine();
                // No se reintentan solas a propósito: ya se llamó al proveedor
                // y reintentar puede mandar el mismo correo dos veces. El
                // sistema no decide por su cuenta, deja el caso a la vista.
                $this->warn("  {$stats['unresolved']} intención(es) quedaron SIN RESOLVER: se llamó al proveedor");
                $this->warn('  y no sabemos cómo terminó. No se reintentan solas — reintentar puede duplicar.');
                $this->newLine();
                $this->line('  Para ver una: <fg=cyan>php artisan d4s:campaign:explain '.$tenant->slug.' {EJECUCIÓN} {CLIENTE}</>');
            }

            return self::SUCCESS;
        });
    }
}
