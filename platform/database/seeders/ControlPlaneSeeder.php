<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Enums\IsolationStrategy;
use App\Domain\Tenancy\Models\ApiKey;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seed del plano de control: tenants, sus API keys y los usuarios del
 * dashboard.
 *
 * Importante lo que este seeder NO hace: no crea una sola venta. Los datos
 * de ventas entran unicamente por la API de ingesta, empujados por el
 * emisor. Si este seeder escribiera ordenes directo en la base, estaria
 * violando la regla central del enunciado ("la API es el unico punto de
 * entrada") justo en el lugar mas facil de pasar por alto.
 *
 * Los tres tenants tienen perfiles deliberadamente distintos, no solo en
 * volumen: distinta zona horaria y distinta moneda de reporte, para que los
 * bordes de fecha y de conversion se ejerciten de verdad en vez de quedar
 * como codigo muerto.
 *
 * MegaRetail nace `dedicated`: es el tenant grande, y tenerlo en su propia
 * base desde el arranque demuestra las dos estrategias conviviendo en un
 * solo `docker compose up`.
 */
class ControlPlaneSeeder extends Seeder
{
    /**
     * Contrasena unica para todos los usuarios del seed. Es de desarrollo y
     * esta documentada como tal en el README.
     */
    public const DEMO_PASSWORD = 'password';

    /**
     * @var list<array<string, mixed>>
     */
    private const TENANTS = [
        [
            'slug' => 'luna',
            'name' => 'Casa Luna',
            'profile' => 'chico — miles de ordenes',
            'strategy' => IsolationStrategy::Shared,
            'timezone' => 'America/Argentina/Buenos_Aires',
            'currency' => 'ARS',
            'connection' => null,
            'database' => null,
        ],
        [
            'slug' => 'andes',
            'name' => 'Andes Sport',
            'profile' => 'mediano — cientos de miles de ordenes',
            'strategy' => IsolationStrategy::Shared,
            'timezone' => 'America/Santiago',
            'currency' => 'USD',
            'connection' => null,
            'database' => null,
        ],
        [
            'slug' => 'mega',
            'name' => 'MegaRetail',
            'profile' => 'grande — millones de ordenes',
            'strategy' => IsolationStrategy::Dedicated,
            'timezone' => 'Europe/Madrid',
            'currency' => 'EUR',
            'connection' => 'tenant_mega',
            'database' => 'd4s_tenant_mega',
        ],
    ];

    public function run(): void
    {
        foreach (self::TENANTS as $definition) {
            $tenant = $this->upsertTenant($definition);

            $this->upsertApiKey($tenant);
            $this->upsertUsers($tenant);
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function upsertTenant(array $definition): Tenant
    {
        return Tenant::updateOrCreate(
            ['slug' => $definition['slug']],
            [
                'name' => $definition['name'],
                'isolation_strategy' => $definition['strategy'],
                'connection_name' => $definition['connection'],
                'database_name' => $definition['database'],
                'timezone' => $definition['timezone'],
                'reporting_currency' => $definition['currency'],
                'is_active' => true,
            ],
        );
    }

    /**
     * Las keys del seed son deterministas, derivadas del slug.
     *
     * En produccion serian aleatorias e irrecuperables. Aca hacen falta
     * fijas por una razon practica: el README documenta las credenciales, y
     * una credencial documentada que cambia en cada seed no sirve para que
     * alguien evalue el sistema. El prefijo `d4s_dev_` marca sin ambiguedad
     * que estas llaves no son de produccion.
     */
    private function upsertApiKey(Tenant $tenant): void
    {
        $secret = self::demoApiKey($tenant->slug);

        ApiKey::updateOrCreate(
            ['token_hash' => ApiKey::hash($secret)],
            [
                'tenant_id' => $tenant->id,
                'name' => 'Clave de desarrollo',
                'prefix' => substr($secret, 0, 16),
                'scopes' => ['ingest:write', 'query:read'],
                'revoked_at' => null,
            ],
        );
    }

    /**
     * Un usuario de cada rol por cada tenant, como pide el enunciado.
     *
     * El email lleva el slug del tenant adentro para que sean legibles en el
     * README, pero el indice unico de la tabla es (tenant_id, email): dos
     * tenants podrian tener el mismo email sin conflicto.
     */
    private function upsertUsers(Tenant $tenant): void
    {
        foreach (UserRole::cases() as $role) {
            User::updateOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'email' => "{$role->value}@{$tenant->slug}.test",
                ],
                [
                    'name' => $role->label().' '.$tenant->name,
                    'password' => Hash::make(self::DEMO_PASSWORD),
                    'role' => $role,
                    'is_active' => true,
                    'email_verified_at' => now(),
                ],
            );
        }
    }

    /**
     * Clave de desarrollo reproducible para un tenant.
     */
    public static function demoApiKey(string $slug): string
    {
        return ApiKey::TOKEN_PREFIX.'dev_'.$slug.'_'.hash('sha256', 'data4sales-demo:'.$slug);
    }
}
