<?php

declare(strict_types=1);

use App\Domain\Outreach\Actions\DispatchIntents;
use App\Domain\Outreach\Actions\MaterializeStepIntents;
use App\Domain\Outreach\Actions\ResolveAudience;
use App\Domain\Outreach\Dsl\ConditionParser;
use App\Domain\Outreach\Enums\IntentState;
use App\Domain\Outreach\Models\AudienceDefinition;
use App\Domain\Outreach\Models\Campaign;
use App\Domain\Outreach\Models\CampaignRecipient;
use App\Domain\Outreach\Models\CampaignRun;
use App\Domain\Outreach\Models\CampaignStep;
use App\Domain\Outreach\Models\RecipientDecision;
use App\Domain\Outreach\Models\SendAttempt;
use App\Domain\Outreach\Models\SendIntent;
use App\Domain\Outreach\Models\Suppression;
use App\Domain\Outreach\Models\Template;
use App\Domain\Outreach\Models\TemplateVersion;
use App\Domain\Tenancy\Models\ApiKey;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create([
        'slug' => 'luna-'.Str::lower(Str::random(6)),
        'timezone' => 'America/Argentina/Buenos_Aires',
        'reporting_currency' => 'ARS',
    ]);

    [, $this->secret] = ApiKey::issue($this->tenant, 'test');
    $this->context = app(TenantContext::class);
});

/** Carga ventas por la API, que es el único camino de entrada. */
function cargarVentas(string $secret, array $records): void
{
    test()->postJson('/api/v1/ingest/orders', ['source' => 'daily_stream', 'records' => $records], [
        'Authorization' => "Bearer {$secret}",
    ])->assertStatus(202);
}

function ventaDe(string $customerId, string $email, string $orderId, string $fecha, string $total = '2000.00'): array
{
    return [
        'order_id' => $orderId,
        'status' => 'delivered',
        'currency' => 'ARS',
        'total' => $total,
        'placed_at' => "{$fecha}T12:00:00-03:00",
        'updated_at' => "{$fecha}T12:00:00-03:00",
        'customer' => ['customer_id' => $customerId, 'name' => 'Cliente '.$customerId, 'email' => $email],
        'items' => [['product_id' => 'P-1', 'name' => 'Remera', 'category' => 'indumentaria', 'quantity' => 1, 'unit_price' => $total]],
    ];
}

/** Cuatro compras recientes: entra en la audiencia. */
function compradorFrecuente(string $id, string $email): array
{
    $hoy = CarbonImmutable::now();

    return array_map(
        fn (int $i) => ventaDe($id, $email, "{$id}-ORD-{$i}", $hoy->subDays($i * 5)->format('Y-m-d')),
        [1, 2, 3, 4],
    );
}

function montarCampania(Tenant $tenant, TenantContext $context): array
{
    return $context->run($tenant, function () use ($tenant) {
        $dsl = [
            'schema_version' => 1,
            'root' => [
                'op' => 'orders',
                'id' => 'frec_90d',
                'window' => ['kind' => 'relative', 'last_days' => 90],
                'filter' => ['status_in' => ['delivered']],
                'having' => [['agg' => 'count', 'cmp' => 'gt', 'value' => 3]],
            ],
        ];

        $audience = AudienceDefinition::create([
            'tenant_id' => $tenant->id,
            'name' => 'Frecuentes',
            'dsl' => $dsl,
            'dsl_hash' => ConditionParser::hash((new ConditionParser)->parse($dsl)),
            'schema_version' => 1,
        ]);

        $template = Template::create(['tenant_id' => $tenant->id, 'name' => 'Gracias', 'content_mode' => 'personalized']);

        $version = TemplateVersion::create([
            'tenant_id' => $tenant->id,
            'template_id' => $template->id,
            'version' => 1,
            'subject' => 'Gracias, {{ nombre }}',
            'body_text' => 'Hola {{ nombre }}, compraste {{ ordenes }} veces.',
            'declared_variables' => ['nombre', 'ordenes'],
            'content_hash' => hash('sha256', 'x'),
            'published_at' => now(),
        ]);

        $campaign = Campaign::create([
            'tenant_id' => $tenant->id,
            'slug' => 'gracias',
            'name' => 'Gracias',
            'mode' => 'static',
            'audience_definition_id' => $audience->id,
            'lifecycle_state' => 'ready',
            'control_state' => 'running',
        ]);

        $step = CampaignStep::create([
            'tenant_id' => $tenant->id,
            'campaign_id' => $campaign->id,
            'position' => 1,
            'wait_before_seconds' => 0,
            'valid_for_seconds' => 3 * 86400,
            'template_version_id' => $version->id,
        ]);

        return [$audience, $campaign, $step];
    });
}

function ejecutar(Tenant $tenant, TenantContext $context, $audience, $campaign): CampaignRun
{
    return $context->run($tenant, function () use ($tenant, $audience, $campaign) {
        $asOf = CarbonImmutable::now();
        $preview = app(ResolveAudience::class)->preview($audience, $asOf);

        $run = CampaignRun::create([
            'tenant_id' => $tenant->id,
            'campaign_id' => $campaign->id,
            'as_of' => $asOf,
            'dsl_snapshot' => $audience->dsl,
            'compiler_version' => 'slice-1',
            'sql_fingerprint' => $preview['query']->fingerprint,
            'status' => 'resolving',
        ]);

        app(ResolveAudience::class)->freeze($run, $preview['query']);

        return $run;
    });
}

// ─────────────────────────────────────────────────────────────────
// Resolución de audiencia
// ─────────────────────────────────────────────────────────────────

it('resuelve la audiencia contra el dataset real', function () {
    cargarVentas($this->secret, [
        ...compradorFrecuente('CUS-1', 'uno@example.test'),
        // Sólo dos compras: no llega al umbral de más de tres.
        ...array_slice(compradorFrecuente('CUS-2', 'dos@example.test'), 0, 2),
    ]);

    [$audience, $campaign, $step] = montarCampania($this->tenant, $this->context);

    $preview = $this->context->run($this->tenant, fn () => app(ResolveAudience::class)->preview($audience));

    expect($preview['count'])->toBe(1);
});

it('excluye a quien no tiene email válido, aunque la condición no lo pida', function () {
    cargarVentas($this->secret, compradorFrecuente('CUS-1', 'esto no es un mail'));

    [$audience] = montarCampania($this->tenant, $this->context);

    // No se le puede escribir a quien no tiene dirección. Es requisito
    // estructural, no parte de la condición: el usuario no puede quitarlo.
    $preview = $this->context->run($this->tenant, fn () => app(ResolveAudience::class)->preview($audience));

    expect($preview['count'])->toBe(0);
});

it('deduplica por dirección cuando dos clientes comparten email', function () {
    // D-08: el email es dato de contacto, no identidad. Dos clientes pueden
    // compartir casilla, y la persona detrás no debería recibir dos copias.
    cargarVentas($this->secret, [
        ...compradorFrecuente('CUS-1', 'familia@example.test'),
        ...compradorFrecuente('CUS-2', 'familia@example.test'),
    ]);

    [$audience, $campaign] = montarCampania($this->tenant, $this->context);
    $run = ejecutar($this->tenant, $this->context, $audience, $campaign);

    $this->context->run($this->tenant, function () use ($run) {
        expect(CampaignRecipient::query()->where('run_id', $run->id)->count())->toBe(1);
    });
});

it('congelar dos veces la misma ejecución no duplica NADA', function () {
    // Este test asertaba sólo sobre `campaign_recipients` y pasaba con el bug
    // adentro: los destinatarios estaban protegidos por sus índices únicos,
    // pero las decisiones se duplicaban una por una (186 → 372 medido) y
    // `audience_size` se reescribía en 0 sobre una audiencia intacta.
    //
    // Congelar es una operación que se REINTENTA: si una tanda se corta por la
    // mitad, alguien la vuelve a correr. Que sea idempotente en una tabla y no
    // en las otras dos es peor que no serlo en ninguna, porque el número que
    // queda mintiendo es el que se muestra.
    cargarVentas($this->secret, [
        ...compradorFrecuente('CUS-1', 'uno@example.test'),
        // Comparte casilla con CUS-1: queda excluido por duplicado y deja su
        // propia decisión. Sin él, el congelamiento no escribiría exclusiones.
        ...compradorFrecuente('CUS-2', 'uno@example.test'),
    ]);

    [$audience, $campaign] = montarCampania($this->tenant, $this->context);
    $run = ejecutar($this->tenant, $this->context, $audience, $campaign);

    $this->context->run($this->tenant, function () use ($run, $audience) {
        $destinatarios = CampaignRecipient::query()->where('run_id', $run->id)->count();
        $decisiones = RecipientDecision::query()->where('run_id', $run->id)->count();
        $tamano = (int) $run->fresh()->audience_size;

        expect($destinatarios)->toBe(1)
            ->and($decisiones)->toBe(2)
            ->and($tamano)->toBe(1);

        $preview = app(ResolveAudience::class)->preview($audience, $run->as_of);
        app(ResolveAudience::class)->freeze($run, $preview['query']);

        // Los índices únicos lo garantizan en el motor, no en un `if` de la
        // aplicación: (run_id, customer_id) y (run_id, email_hash) para los
        // destinatarios, (run_id, customer_id, reason_code) para las
        // decisiones.
        expect(CampaignRecipient::query()->where('run_id', $run->id)->count())->toBe($destinatarios)
            ->and(RecipientDecision::query()->where('run_id', $run->id)->count())->toBe($decisiones)
            // `audience_size` se deriva de la tabla, no de cuántas filas
            // insertó esta pasada: con ON CONFLICT DO NOTHING la segunda
            // inserta cero, y contar eso escribía cero.
            ->and((int) $run->fresh()->audience_size)->toBe($tamano);
    });
});

// ─────────────────────────────────────────────────────────────────
// Idempotencia del envío
// ─────────────────────────────────────────────────────────────────

it('materializar dos veces no crea intenciones duplicadas', function () {
    cargarVentas($this->secret, compradorFrecuente('CUS-1', 'uno@example.test'));

    [$audience, $campaign, $step] = montarCampania($this->tenant, $this->context);
    $run = ejecutar($this->tenant, $this->context, $audience, $campaign);

    $this->context->run($this->tenant, function () use ($run, $step) {
        $primera = app(MaterializeStepIntents::class)->execute($run, $step);
        $segunda = app(MaterializeStepIntents::class)->execute($run, $step);

        // La clave es un uuid v5 determinista de (ejecución, paso, cliente):
        // la segunda pasada produce las mismas claves y el índice único las
        // rechaza.
        expect($primera)->toBe(1)
            ->and($segunda)->toBe(0)
            ->and(SendIntent::query()->where('run_id', $run->id)->count())->toBe(1);
    });
});

it('envía una sola vez aunque se despache de nuevo', function () {
    Mail::fake();

    cargarVentas($this->secret, compradorFrecuente('CUS-1', 'uno@example.test'));

    [$audience, $campaign, $step] = montarCampania($this->tenant, $this->context);
    $run = ejecutar($this->tenant, $this->context, $audience, $campaign);

    $this->context->run($this->tenant, function () use ($run, $step) {
        app(MaterializeStepIntents::class)->execute($run, $step);

        $primera = app(DispatchIntents::class)->execute($run, $step);
        // Segunda pasada: ya no queda nada en estado `queued`.
        $segunda = app(DispatchIntents::class)->execute($run, $step);

        expect($primera['sent'])->toBe(1)
            ->and($segunda['sent'])->toBe(0)
            ->and(SendAttempt::query()->where('outcome', 'sent')->count())->toBe(1);
    });

    Mail::assertSentCount(1);
});

it('respeta la pausa sin tener que esperar a que termine la tanda', function () {
    Mail::fake();

    cargarVentas($this->secret, compradorFrecuente('CUS-1', 'uno@example.test'));

    [$audience, $campaign, $step] = montarCampania($this->tenant, $this->context);
    $run = ejecutar($this->tenant, $this->context, $audience, $campaign);

    $this->context->run($this->tenant, function () use ($run, $step, $campaign) {
        app(MaterializeStepIntents::class)->execute($run, $step);

        $campaign->update(['control_state' => 'paused']);
        $run->setRelation('campaign', $campaign->fresh());

        $stats = app(DispatchIntents::class)->execute($run->fresh(), $step);

        expect($stats['paused'])->toBeTrue()
            ->and($stats['sent'])->toBe(0);
    });

    Mail::assertNothingSent();
});

// ─────────────────────────────────────────────────────────────────
// Privacidad
// ─────────────────────────────────────────────────────────────────

it('respeta una baja ocurrida ENTRE el congelamiento y el envío', function () {
    Mail::fake();

    cargarVentas($this->secret, compradorFrecuente('CUS-1', 'uno@example.test'));

    [$audience, $campaign, $step] = montarCampania($this->tenant, $this->context);
    $run = ejecutar($this->tenant, $this->context, $audience, $campaign);

    $this->context->run($this->tenant, function () use ($run, $step) {
        app(MaterializeStepIntents::class)->execute($run, $step);

        // La baja llega DESPUÉS de congelar la audiencia. Entre resolver y
        // enviar pueden pasar horas; respetar la baja sólo al congelar sería
        // respetarla a medias.
        Suppression::create([
            'tenant_id' => $this->tenant->id,
            'email_hash' => Suppression::hashFor('uno@example.test'),
            'reason' => 'unsubscribed',
            'scope' => 'tenant',
            'source' => 'test',
            'created_at' => now(),
        ]);

        $stats = app(DispatchIntents::class)->execute($run, $step);

        expect($stats['suppressed'])->toBe(1)
            ->and($stats['sent'])->toBe(0)
            ->and(SendIntent::query()->where('run_id', $run->id)->value('state'))
            ->toBe(IntentState::Suppressed);
    });

    Mail::assertNothingSent();
});

// ─────────────────────────────────────────────────────────────────
// Aislamiento
// ─────────────────────────────────────────────────────────────────

it('no ve las campañas de otro tenant', function () {
    [$audience, $campaign] = montarCampania($this->tenant, $this->context);

    $otro = Tenant::factory()->create(['slug' => 'otro-'.Str::lower(Str::random(6))]);

    $this->context->run($otro, function () {
        expect(Campaign::query()->count())->toBe(0)
            ->and(AudienceDefinition::query()->count())->toBe(0);
    });
});

it('no le manda a clientes de otro tenant aunque cumplan la condición', function () {
    Mail::fake();

    $otro = Tenant::factory()->create(['slug' => 'otro-'.Str::lower(Str::random(6))]);
    [, $otroSecret] = ApiKey::issue($otro, 'test');

    // Los dos tenants tienen un comprador frecuente con la MISMA dirección.
    cargarVentas($this->secret, compradorFrecuente('CUS-MIO', 'mio@example.test'));
    cargarVentas($otroSecret, compradorFrecuente('CUS-AJENO', 'ajeno@example.test'));

    [$audience, $campaign, $step] = montarCampania($this->tenant, $this->context);
    $run = ejecutar($this->tenant, $this->context, $audience, $campaign);

    $this->context->run($this->tenant, function () use ($run) {
        $emails = CampaignRecipient::query()
            ->where('run_id', $run->id)
            ->join('customers', 'customers.id', '=', 'campaign_recipients.customer_id')
            ->pluck('customers.external_id');

        expect($emails)->toContain('CUS-MIO')
            ->and($emails)->not->toContain('CUS-AJENO');
    });
});
