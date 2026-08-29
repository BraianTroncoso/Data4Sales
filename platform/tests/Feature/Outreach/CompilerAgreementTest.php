<?php

declare(strict_types=1);

use App\Domain\Outreach\Dsl\ConditionParser;
use App\Domain\Outreach\Dsl\ExplainCompiler;
use App\Domain\Outreach\Dsl\SqlCompiler;
use App\Domain\Sales\Models\Customer;
use App\Domain\Tenancy\Models\ApiKey;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Las dos formas de evaluar una condición tienen que decir lo mismo
|--------------------------------------------------------------------------
|
| `SqlCompiler` resuelve un conjunto y `ExplainCompiler` evalúa un cliente.
| El diseño dice que comparten el árbol justamente para que no puedan
| contradecirse — y se contradecían: el promedio se comparaba exacto en uno y
| redondeado a entero en el otro.
|
| El caso de borde no es un detalle: es el ÚNICO lugar donde la divergencia se
| ve. Con un cliente que promedia 250.000 los dos dicen que sí, y el bug queda
| invisible hasta que alguien pregunta por uno que está justo en el filo.
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

function caCargarVentas(string $secret, array $records): void
{
    test()->postJson('/api/v1/ingest/orders', ['source' => 'daily_stream', 'records' => $records], [
        'Authorization' => "Bearer {$secret}",
    ])->assertStatus(202);
}

function caVenta(string $orderId, string $fecha, string $total): array
{
    return [
        'order_id' => $orderId,
        'status' => 'delivered',
        'currency' => 'ARS',
        'total' => $total,
        'placed_at' => "{$fecha}T12:00:00-03:00",
        'updated_at' => "{$fecha}T12:00:00-03:00",
        'customer' => ['customer_id' => 'CUS-FILO', 'name' => 'Cliente Filo', 'email' => 'filo@example.test'],
        'items' => [['product_id' => 'P-1', 'name' => 'Remera', 'category' => 'indumentaria', 'quantity' => 1, 'unit_price' => $total]],
    ];
}

/** Condición: promedio de compra por encima de un umbral, en los últimos 90 días. */
function caDsl(int $umbral): array
{
    return [
        'schema_version' => 1,
        'root' => [
            'op' => 'orders',
            'id' => 'ticket',
            'window' => ['kind' => 'relative', 'last_days' => 90],
            'filter' => ['status_in' => ['delivered']],
            'having' => [['agg' => 'avg_reporting_minor', 'cmp' => 'gte', 'value' => $umbral]],
        ],
    ];
}

it('el compilador y el explicador coinciden sobre un promedio en el filo del umbral', function () {
    $hoy = CarbonImmutable::now();

    // Cinco compras: cuatro de 999,99 y una de 1000,02. Suma 4.999,98 sobre
    // cinco órdenes = promedio 99.999,6 centavos. El umbral es 100.000.
    //
    // Redondeado a entero da exactamente 100.000 y "cumple"; exacto, no llega.
    // Un centésimo de peso decide si esta persona recibe el correo, y las dos
    // implementaciones tienen que decidirlo igual.
    caCargarVentas($this->secret, [
        caVenta('ORD-1', $hoy->subDays(5)->format('Y-m-d'), '999.99'),
        caVenta('ORD-2', $hoy->subDays(10)->format('Y-m-d'), '999.99'),
        caVenta('ORD-3', $hoy->subDays(15)->format('Y-m-d'), '999.99'),
        caVenta('ORD-4', $hoy->subDays(20)->format('Y-m-d'), '999.99'),
        caVenta('ORD-5', $hoy->subDays(25)->format('Y-m-d'), '1000.02'),
    ]);

    $this->context->run($this->tenant, function () {
        $asOf = CarbonImmutable::now();
        $node = (new ConditionParser)->parse(caDsl(100000));

        $customer = Customer::query()->where('external_id', 'CUS-FILO')->firstOrFail();

        // Control: el promedio es el que creemos que es. Si esto cambia, el
        // caso de borde deja de serlo y el test dejaría de probar nada.
        $promedio = DB::connection($this->context->connectionName())->selectOne(
            'SELECT AVG(reporting_amount_minor) AS avg FROM orders WHERE customer_id = ?',
            [$customer->id],
        )->avg;

        expect((float) $promedio)->toBe(99999.6);

        // Lado conjunto: ¿entra en la audiencia?
        $query = (new SqlCompiler)->compile($node, $this->tenant->id, $asOf);

        $entra = DB::connection($this->context->connectionName())->select(
            "SELECT customer_id FROM ({$query->sql}) AS audiencia",
            $query->bindings,
        );

        // Lado individual: ¿la pantalla que explica dice que cumple?
        $explicacion = app(ExplainCompiler::class)->explain($node, $customer->id, $asOf);

        // La afirmación central: no importa cuál de las dos sea, tienen que
        // ser la MISMA. Contradecirse acá significa decirle a alguien "cumplís
        // la condición" mientras se le explica por qué no recibió el correo.
        expect($explicacion['matches_condition'])->toBe($entra !== []);

        // Y el criterio elegido, explícito: el promedio no se redondea, así
        // que 99.999,6 no alcanza un umbral de 100.000.
        expect($entra)->toBe([])
            ->and($explicacion['matches_condition'])->toBeFalse()
            ->and($explicacion['nodes'][0]['clauses'][0]['actual'])->toBe(99999.6);
    });
});

it('coinciden también cuando el promedio llega justo al umbral', function () {
    $hoy = CarbonImmutable::now();

    // Dos compras de 1000,00: promedio exacto 100.000. `gte` tiene que dar
    // verdadero de los dos lados — el arreglo no puede haberse pasado de
    // estricto y dejar afuera a quien sí cumple.
    caCargarVentas($this->secret, [
        caVenta('ORD-1', $hoy->subDays(5)->format('Y-m-d'), '1000.00'),
        caVenta('ORD-2', $hoy->subDays(10)->format('Y-m-d'), '1000.00'),
    ]);

    $this->context->run($this->tenant, function () {
        $asOf = CarbonImmutable::now();
        $node = (new ConditionParser)->parse(caDsl(100000));
        $customer = Customer::query()->where('external_id', 'CUS-FILO')->firstOrFail();

        $query = (new SqlCompiler)->compile($node, $this->tenant->id, $asOf);

        $entra = DB::connection($this->context->connectionName())->select(
            "SELECT customer_id FROM ({$query->sql}) AS audiencia",
            $query->bindings,
        );

        $explicacion = app(ExplainCompiler::class)->explain($node, $customer->id, $asOf);

        expect($explicacion['matches_condition'])->toBe($entra !== [])
            ->and($entra)->toHaveCount(1)
            ->and($explicacion['matches_condition'])->toBeTrue();
    });
});
