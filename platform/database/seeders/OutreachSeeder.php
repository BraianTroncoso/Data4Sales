<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Outreach\Dsl\ConditionParser;
use App\Domain\Outreach\Models\AudienceDefinition;
use App\Domain\Outreach\Models\Campaign;
use App\Domain\Outreach\Models\CampaignStep;
use App\Domain\Outreach\Models\Template;
use App\Domain\Outreach\Models\TemplateVersion;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

/**
 * Siembra una campana lista para ejecutar en cada tenant.
 *
 * La condicion es el caso 1 del enunciado SIN el nodo de afinidad
 * producto-cliente. Esa exclusion es deliberada y esta explicada en la
 * seccion 9.1 del diseno: el nodo `purchased` necesita
 * `customer_product_affinity`, que es la estructura nueva mas cara de
 * construir y mantener consistente. El recorte demuestra el motor de punta a
 * punta sin depender de ella.
 */
class OutreachSeeder extends Seeder
{
    public function run(): void
    {
        $context = app(TenantContext::class);

        foreach (Tenant::query()->where('is_active', true)->get() as $tenant) {
            $context->run($tenant, fn () => $this->seedTenant($tenant));
        }
    }

    private function seedTenant(Tenant $tenant): void
    {
        $dsl = $this->condition();
        $node = (new ConditionParser)->parse($dsl);

        $audience = AudienceDefinition::updateOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'Compradores frecuentes con ticket alto'],
            [
                'dsl' => $dsl,
                'dsl_hash' => ConditionParser::hash($node),
                'schema_version' => ConditionParser::SCHEMA_VERSION,
            ],
        );

        $template = Template::updateOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'Gracias por comprar'],
            // Personalizado: prohibe el envio en lote al proveedor. Ver la
            // seccion 8.4 del diseno.
            ['content_mode' => 'personalized'],
        );

        $body = <<<'TXT'
        Hola {{ nombre }},

        Vimos que compraste {{ ordenes }} veces con nosotros en los últimos meses,
        y queríamos agradecerte.

        Como cliente frecuente, tenés acceso anticipado a la próxima colección.

        Gracias por elegirnos,
        El equipo
        TXT;

        $version = TemplateVersion::updateOrCreate(
            ['tenant_id' => $tenant->id, 'template_id' => $template->id, 'version' => 1],
            [
                'subject' => 'Gracias por tus compras, {{ nombre }}',
                'body_text' => $body,
                'body_html' => null,
                // Lista blanca: el renderizador rechaza cualquier variable que
                // no este aca, en vez de reemplazarla por vacio en silencio.
                'declared_variables' => ['nombre', 'ordenes'],
                'content_hash' => hash('sha256', $body),
                'published_at' => now(),
            ],
        );

        $campaign = Campaign::updateOrCreate(
            ['tenant_id' => $tenant->id, 'slug' => 'gracias-frecuentes'],
            [
                'name' => 'Gracias a compradores frecuentes',
                'mode' => 'static',
                'audience_definition_id' => $audience->id,
                'lifecycle_state' => 'ready',
                'control_state' => 'running',
            ],
        );

        CampaignStep::updateOrCreate(
            ['tenant_id' => $tenant->id, 'campaign_id' => $campaign->id, 'position' => 1],
            [
                'wait_before_seconds' => 0,
                // Vence a los 3 dias: un mensaje que sale tarde por una caida
                // del proveedor es peor que uno que no sale.
                'valid_for_seconds' => 3 * 86400,
                'template_version_id' => $version->id,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function condition(): array
    {
        return [
            'schema_version' => ConditionParser::SCHEMA_VERSION,
            'root' => [
                'op' => 'and',
                'id' => 'root',
                'nodes' => [
                    [
                        'op' => 'attr',
                        'id' => 'email_ok',
                        'field' => 'customer.email_status',
                        'cmp' => 'eq',
                        'value' => 'valid',
                    ],
                    [
                        'op' => 'orders',
                        'id' => 'frec_90d',
                        'window' => ['kind' => 'relative', 'last_days' => 90],
                        'filter' => ['status_in' => ['paid', 'shipped', 'delivered']],
                        'having' => [
                            ['agg' => 'count', 'cmp' => 'gt', 'value' => 3],
                            ['agg' => 'avg_reporting_minor', 'cmp' => 'gte', 'value' => 100000],
                        ],
                    ],
                ],
            ],
        ];
    }
}
