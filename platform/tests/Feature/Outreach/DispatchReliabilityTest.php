<?php

declare(strict_types=1);

use App\Domain\Outreach\Actions\DispatchIntents;
use App\Domain\Outreach\Actions\MaterializeStepIntents;
use App\Domain\Outreach\Actions\ReapExpiredClaims;
use App\Domain\Outreach\Actions\ResolveAudience;
use App\Domain\Outreach\Dsl\ConditionParser;
use App\Domain\Outreach\Enums\DecisionReason;
use App\Domain\Outreach\Enums\IntentState;
use App\Domain\Outreach\Models\AudienceDefinition;
use App\Domain\Outreach\Models\Campaign;
use App\Domain\Outreach\Models\CampaignRecipient;
use App\Domain\Outreach\Models\CampaignRun;
use App\Domain\Outreach\Models\CampaignStep;
use App\Domain\Outreach\Models\RecipientDecision;
use App\Domain\Outreach\Models\SendAttempt;
use App\Domain\Outreach\Models\SendIntent;
use App\Domain\Outreach\Models\Template;
use App\Domain\Outreach\Models\TemplateVersion;
use App\Domain\Sales\Models\Customer;
use App\Domain\Tenancy\Models\ApiKey;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Lo que pasa cuando algo sale mal en el medio del envío
|--------------------------------------------------------------------------
|
| CampaignSliceTest cubre el camino feliz y la idempotencia. Este archivo
| cubre lo otro: el worker que muere, el proveedor que no contesta y el
| destinatario que ya no está. Son los tres casos sobre los que D-24 hace
| afirmaciones fuertes, y los tres estaban rotos.
|
| Los ayudantes están duplicados a propósito y con prefijo: Pest carga todos
| los archivos de prueba en el mismo espacio de nombres global, y depender del
| orden de carga para reusar una función declarada en otro archivo es una
| dependencia invisible que rompe cuando alguien corre un solo test.
|
*/

beforeEach(function () {
    $this->tenant = Tenant::factory()->create([
        'slug' => 'luna-'.Str::lower(Str::random(6)),
        'timezone' => 'America/Argentina/Buenos_Aires',
        'reporting_currency' => 'ARS',
    ]);

    [, $this->secret] = ApiKey::issue($this->tenant, 'test');
    $this->context = app(TenantContext::class);
});

function drCargarVentas(string $secret, array $records): void
{
    test()->postJson('/api/v1/ingest/orders', ['source' => 'daily_stream', 'records' => $records], [
        'Authorization' => "Bearer {$secret}",
    ])->assertStatus(202);
}

function drVenta(string $customerId, string $email, string $orderId, string $fecha, string $total = '2000.00'): array
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

function drCompradorFrecuente(string $id, string $email): array
{
    $hoy = CarbonImmutable::now();

    return array_map(
        fn (int $i) => drVenta($id, $email, "{$id}-ORD-{$i}", $hoy->subDays($i * 5)->format('Y-m-d')),
        [1, 2, 3, 4],
    );
}

function drMontarCampania(Tenant $tenant, TenantContext $context): array
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

        return [$audience, $campaign, $step, $version];
    });
}

function drEjecutar(Tenant $tenant, TenantContext $context, $audience, $campaign): CampaignRun
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
// El recolector de reclamos vencidos
// ─────────────────────────────────────────────────────────────────

it('devuelve a la cola un reclamo vencido y no toca el de un worker vivo', function () {
    Mail::fake();

    drCargarVentas($this->secret, [
        ...drCompradorFrecuente('CUS-1', 'uno@example.test'),
        ...drCompradorFrecuente('CUS-2', 'dos@example.test'),
        ...drCompradorFrecuente('CUS-3', 'tres@example.test'),
    ]);

    [$audience, $campaign, $step] = drMontarCampania($this->tenant, $this->context);
    $run = drEjecutar($this->tenant, $this->context, $audience, $campaign);

    $this->context->run($this->tenant, function () use ($run, $step) {
        app(MaterializeStepIntents::class)->execute($run, $step);

        $intents = SendIntent::query()->where('run_id', $run->id)->orderBy('id')->get();
        expect($intents)->toHaveCount(3);

        // Murió con el reclamo tomado y SIN haber llamado al proveedor.
        $intents[0]->update([
            'state' => IntentState::Claimed,
            'claimed_by' => 'worker-muerto',
            'claim_expires_at' => now()->subMinute(),
        ]);

        // Llamó al proveedor y no volvió: no sabemos si el correo salió.
        $intents[1]->update([
            'state' => IntentState::InFlight,
            'claimed_by' => 'worker-muerto',
            'claim_expires_at' => now()->subMinute(),
        ]);

        // Un worker VIVO la tiene tomada ahora mismo. Devolverla a la cola
        // sería habilitar que dos workers manden el mismo correo.
        $intents[2]->update([
            'state' => IntentState::Claimed,
            'claimed_by' => 'worker-vivo',
            'claim_expires_at' => now()->addMinutes(5),
        ]);

        // El ensayo cuenta exactamente lo mismo que el barrido va a tocar, y
        // no toca nada: es la forma de mirar cuánta incertidumbre hay
        // acumulada sin decidir nada todavía.
        expect(app(ReapExpiredClaims::class)->execute($run, dryRun: true))
            ->toBe(['requeued' => 1, 'unresolved' => 1])
            ->and($intents[0]->fresh()->state)->toBe(IntentState::Claimed);

        $stats = app(ReapExpiredClaims::class)->execute($run);

        expect($stats)->toBe(['requeued' => 1, 'unresolved' => 1])
            // Nadie recibió nada: reintentar es seguro.
            ->and($intents[0]->fresh()->state)->toBe(IntentState::Queued)
            ->and($intents[0]->fresh()->claimed_by)->toBeNull()
            // Se llamó al proveedor: NO se reintenta sola, la mira una persona.
            ->and($intents[1]->fresh()->state)->toBe(IntentState::Unresolved)
            ->and($intents[2]->fresh()->state)->toBe(IntentState::Claimed)
            ->and($intents[2]->fresh()->claimed_by)->toBe('worker-vivo');
    });
});

it('lo que el recolector devuelve a la cola vuelve a ser elegible', function () {
    Mail::fake();

    drCargarVentas($this->secret, drCompradorFrecuente('CUS-1', 'uno@example.test'));

    [$audience, $campaign, $step] = drMontarCampania($this->tenant, $this->context);
    $run = drEjecutar($this->tenant, $this->context, $audience, $campaign);

    $this->context->run($this->tenant, function () use ($run, $step) {
        app(MaterializeStepIntents::class)->execute($run, $step);

        $intent = SendIntent::query()->where('run_id', $run->id)->firstOrFail();
        $intent->update([
            'state' => IntentState::Claimed,
            'claimed_by' => 'worker-muerto',
            'claim_expires_at' => now()->subMinute(),
        ]);

        // Sin recolector esto era una fuga PERMANENTE: `claim()` sólo mira
        // `queued`, así que una intención abandonada en `claimed` no volvía a
        // ser elegible nunca. No era "una intención visible", era un correo
        // que no salía y nadie notaba.
        expect(app(DispatchIntents::class)->execute($run, $step)['sent'])->toBe(0);

        app(ReapExpiredClaims::class)->execute($run);

        expect(app(DispatchIntents::class)->execute($run, $step)['sent'])->toBe(1)
            ->and($intent->fresh()->state)->toBe(IntentState::Sent);
    });

    Mail::assertSentCount(1);
});

// ─────────────────────────────────────────────────────────────────
// La distinción que D-24 llama "toda la garantía de entrega"
// ─────────────────────────────────────────────────────────────────

it('un fallo del transporte deja la intención `in_flight`, no `failed`', function () {
    drCargarVentas($this->secret, drCompradorFrecuente('CUS-1', 'uno@example.test'));

    [$audience, $campaign, $step] = drMontarCampania($this->tenant, $this->context);
    $run = drEjecutar($this->tenant, $this->context, $audience, $campaign);

    // Un timeout de SMTP: el servidor pudo haber aceptado el mensaje y haber
    // cortado al responder. Es el caso motivador de D-24, y terminaba marcado
    // `failed` — o sea, afirmando que el correo NO salió.
    Mail::shouldReceive('to')->once()->andReturnSelf();
    Mail::shouldReceive('send')->once()->andThrow(new RuntimeException('Connection could not be established: timeout'));

    $this->context->run($this->tenant, function () use ($run, $step) {
        app(MaterializeStepIntents::class)->execute($run, $step);

        $stats = app(DispatchIntents::class)->execute($run, $step);

        expect($stats['unresolved'])->toBe(1)
            ->and($stats['failed'])->toBe(0)
            ->and($stats['sent'])->toBe(0);

        $intent = SendIntent::query()->where('run_id', $run->id)->firstOrFail();

        expect($intent->state)->toBe(IntentState::InFlight)
            // El reclamo sigue vivo y vencible: es lo que le permite al
            // recolector encontrarla más tarde.
            ->and($intent->claim_expires_at)->not->toBeNull();

        $attempt = SendAttempt::query()->where('intent_id', $intent->id)->firstOrFail();

        expect($attempt->outcome)->toBe('unresolved')
            ->and($attempt->error_message)->toContain('timeout');

        // Y cuando vence el reclamo, el recolector la deja donde una persona
        // la ve, en vez de reintentarla y arriesgar un duplicado.
        $intent->update(['claim_expires_at' => now()->subMinute()]);

        expect(app(ReapExpiredClaims::class)->execute($run)['unresolved'])->toBe(1)
            ->and($intent->fresh()->state)->toBe(IntentState::Unresolved);
    });
});

it('un fallo ANTES de llamar al proveedor sí marca `failed`', function () {
    Mail::fake();

    drCargarVentas($this->secret, drCompradorFrecuente('CUS-1', 'uno@example.test'));

    [$audience, $campaign, $step, $version] = drMontarCampania($this->tenant, $this->context);
    $run = drEjecutar($this->tenant, $this->context, $audience, $campaign);

    $this->context->run($this->tenant, function () use ($run, $step, $version) {
        app(MaterializeStepIntents::class)->execute($run, $step);

        // La plantilla usa una variable que no está declarada: el renderizado
        // revienta. Es un fallo SEGURO — nadie recibió nada y nunca se llamó
        // al proveedor —, así que `failed` lo afirma con razón.
        $version->update(['body_text' => 'Hola {{ inexistente }}']);

        $stats = app(DispatchIntents::class)->execute($run, $step);

        expect($stats['failed'])->toBe(1)
            ->and($stats['unresolved'])->toBe(0)
            ->and(SendIntent::query()->where('run_id', $run->id)->value('state'))->toBe(IntentState::Failed)
            ->and(SendAttempt::query()->where('outcome', 'failed')->count())->toBe(1);
    });

    Mail::assertNothingSent();
});

// ─────────────────────────────────────────────────────────────────
// Un destinatario que ya no existe
// ─────────────────────────────────────────────────────────────────

it('un destinatario borrado entre el congelamiento y el envío no se lleva puesta la tanda', function () {
    Mail::fake();

    drCargarVentas($this->secret, [
        ...drCompradorFrecuente('CUS-1', 'uno@example.test'),
        ...drCompradorFrecuente('CUS-2', 'dos@example.test'),
    ]);

    [$audience, $campaign, $step] = drMontarCampania($this->tenant, $this->context);
    $run = drEjecutar($this->tenant, $this->context, $audience, $campaign);

    $this->context->run($this->tenant, function () use ($run, $step) {
        app(MaterializeStepIntents::class)->execute($run, $step);

        // Derecho al olvido ejercido entre el congelamiento y el envío: la
        // fila de la audiencia queda apuntando a alguien que ya no está.
        $huerfano = CampaignRecipient::query()->where('run_id', $run->id)->orderBy('id')->firstOrFail();
        Customer::query()->where('id', $huerfano->customer_id)->delete();

        // Antes esto tiraba un TypeError FUERA del try y reventaba `execute()`
        // entero: el otro destinatario de la tanda se quedaba colgado en
        // `claimed` y, sin recolector, no salía nunca.
        $stats = app(DispatchIntents::class)->execute($run, $step);

        expect($stats['cancelled'])->toBe(1)
            ->and($stats['sent'])->toBe(1);

        $intent = SendIntent::query()
            ->where('run_id', $run->id)
            ->where('recipient_id', $huerfano->id)
            ->firstOrFail();

        // Estado terminal y con su motivo: la pregunta "por qué no le llegó"
        // tiene respuesta, y no es "falló el envío".
        expect($intent->state)->toBe(IntentState::Cancelled)
            ->and($intent->state->isTerminal())->toBeTrue()
            ->and(RecipientDecision::query()
                ->where('run_id', $run->id)
                ->where('customer_id', $huerfano->customer_id)
                ->where('reason_code', DecisionReason::RecipientGone->value)
                ->exists())->toBeTrue();
    });

    Mail::assertSentCount(1);
});
