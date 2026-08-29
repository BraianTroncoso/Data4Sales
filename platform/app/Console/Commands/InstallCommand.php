<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantConnectionResolver;
use Database\Seeders\ControlPlaneSeeder;
use Database\Seeders\OutreachSeeder;
use Illuminate\Console\Command;
use Throwable;

/**
 * Deja el sistema listo para usar. Es lo que corre el servicio `init` del
 * compose, y el unico proceso que se ejecuta con el rol dueno del esquema.
 *
 * El orden no es arbitrario, hay una dependencia real entre los pasos:
 *
 *   1. Migrar el plano de control. Sin la tabla `tenants` no existe el
 *      concepto de tenant.
 *   2. Sembrar el plano de control. Recien despues de esto el sistema sabe
 *      cuales son sus tenants y cuales pidieron base propia.
 *   3. Migrar el plano de datos compartido.
 *   4. Migrar la base de cada tenant dedicado. Este paso solo se puede
 *      hacer al final, porque la lista de bases a migrar sale del paso 2.
 *
 * Ese cuarto paso es la version chica del problema que vuelve inviable la
 * estrategia de base-por-tenant a gran escala: migrar es un recorrido sobre
 * N bases. Con un dedicado es trivial; con mil, es un orquestador con
 * reintentos y control de version por tenant. Aca se ve el costo en
 * miniatura, que es justamente el argumento para que el default sea
 * compartido.
 */
class InstallCommand extends Command
{
    protected $signature = 'd4s:install
                            {--seed : Sembrar el plano de control (tenants, API keys y usuarios)}
                            {--fresh : Descartar y recrear el esquema antes de migrar}';

    protected $description = 'Migra el plano de control y todos los planos de datos, y opcionalmente los siembra';

    private const SYSTEM_MIGRATIONS = 'database/migrations/system';

    private const TENANT_MIGRATIONS = 'database/migrations/tenant';

    public function handle(TenantConnectionResolver $resolver): int
    {
        try {
            $this->migrateControlPlane();

            if ($this->option('seed')) {
                $this->seedControlPlane();
            }

            $this->migrateSharedDataPlane();
            $this->migrateDedicatedDataPlanes($resolver);

            if ($this->option('seed')) {
                $this->seedDataPlane();
            }
        } catch (Throwable $e) {
            $this->newLine();
            $this->error('La instalacion fallo: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Sistema listo.');
        $this->summarise();

        return self::SUCCESS;
    }

    private function migrateControlPlane(): void
    {
        $this->components->info('Plano de control (d4s_system)');

        $this->runMigrations('system', self::SYSTEM_MIGRATIONS);
    }

    private function seedControlPlane(): void
    {
        $this->components->info('Sembrando tenants, API keys y usuarios');

        $this->callSilently('db:seed', ['--class' => ControlPlaneSeeder::class, '--force' => true]);

        $this->components->twoColumnDetail('tenants', (string) Tenant::count());
    }

    /**
     * Siembra las campanas de ejemplo.
     *
     * Va DESPUES de migrar los planos de datos y no junto al resto del seed,
     * porque las tablas del motor de acciones viven en el plano de datos: al
     * momento de sembrar el plano de control todavia no existen.
     */
    private function seedDataPlane(): void
    {
        $this->components->info('Sembrando campañas de ejemplo');

        $this->callSilently('db:seed', ['--class' => OutreachSeeder::class, '--force' => true]);
    }

    private function migrateSharedDataPlane(): void
    {
        $this->components->info('Plano de datos compartido (d4s_shared)');

        $this->runMigrations(TenantConnectionResolver::SHARED_CONNECTION, self::TENANT_MIGRATIONS);
    }

    /**
     * Migra la base propia de cada tenant dedicado, con el mismo juego de
     * migraciones que el compartido. Que sea literalmente el mismo path es
     * lo que garantiza que los esquemas no puedan divergir.
     */
    private function migrateDedicatedDataPlanes(TenantConnectionResolver $resolver): void
    {
        $dedicated = Tenant::query()->where('isolation_strategy', 'dedicated')->get();

        if ($dedicated->isEmpty()) {
            return;
        }

        foreach ($dedicated as $tenant) {
            $connection = $resolver->resolve($tenant);

            $this->components->info("Plano de datos dedicado — {$tenant->name} ({$tenant->database_name})");

            $this->runMigrations($connection, self::TENANT_MIGRATIONS);
        }
    }

    private function runMigrations(string $connection, string $path): void
    {
        $command = $this->option('fresh') ? 'migrate:fresh' : 'migrate';

        $this->call($command, [
            '--database' => $connection,
            '--path' => $path,
            '--realpath' => false,
            '--force' => true,
        ]);
    }

    /**
     * Resumen final con las credenciales del seed, para que quien levanta el
     * sistema por primera vez no tenga que buscarlas en ningun lado.
     */
    private function summarise(): void
    {
        if (! $this->option('seed')) {
            return;
        }

        $this->newLine();
        $this->components->info('Credenciales de desarrollo');

        foreach (Tenant::query()->orderBy('slug')->get() as $tenant) {
            $this->newLine();
            $this->line("  <fg=cyan>{$tenant->name}</> (slug: <fg=yellow>{$tenant->slug}</>, {$tenant->isolation_strategy->value})");
            $this->line('    API key : '.ControlPlaneSeeder::demoApiKey($tenant->slug));
            $this->line("    Dashboard: analyst@{$tenant->slug}.test / support@{$tenant->slug}.test / admin@{$tenant->slug}.test");
            $this->line('    Password : '.ControlPlaneSeeder::DEMO_PASSWORD);
        }
    }
}
