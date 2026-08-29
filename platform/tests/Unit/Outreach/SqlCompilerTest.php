<?php

declare(strict_types=1);

use App\Domain\Outreach\Dsl\CompiledQuery;
use App\Domain\Outreach\Dsl\ConditionParser;
use App\Domain\Outreach\Dsl\SqlCompiler;
use Carbon\CarbonImmutable;

const TENANT = '11111111-1111-4111-8111-111111111111';

function compilar(array $root): CompiledQuery
{
    $node = (new ConditionParser)->parse(['schema_version' => 1, 'root' => $root]);

    return (new SqlCompiler)->compile($node, TENANT, CarbonImmutable::parse('2026-08-27T12:00:00Z'));
}

it('compila una hoja agregada como CTE y no como subconsulta correlacionada', function () {
    $q = compilar([
        'op' => 'orders', 'id' => 'frec',
        'window' => ['kind' => 'relative', 'last_days' => 90],
        'filter' => ['status_in' => ['delivered']],
        'having' => [['agg' => 'count', 'cmp' => 'gt', 'value' => 3]],
    ]);

    // Una subconsulta correlacionada se ejecuta una vez por cliente. Con
    // 150.000 clientes son 150.000 ejecuciones. El CTE es una sola pasada.
    expect($q->sql)->toContain('WITH')
        ->and($q->sql)->toContain('agg_frec AS (')
        ->and($q->sql)->toContain('GROUP BY o.customer_id')
        ->and($q->sql)->toContain('INNER JOIN agg_frec ON agg_frec.customer_id = customers.id')
        // La marca de una correlacionada sería referenciar la tabla externa
        // desde adentro de la subconsulta.
        ->and($q->sql)->not->toContain('WHERE o.customer_id = customers.id');
});

it('empuja el filtro al HAVING del CTE, no al WHERE de afuera', function () {
    $q = compilar([
        'op' => 'orders', 'id' => 'frec',
        'window' => ['kind' => 'relative', 'last_days' => 90],
        'filter' => ['status_in' => []],
        'having' => [['agg' => 'count', 'cmp' => 'gt', 'value' => 3]],
    ]);

    // Descartar dentro del CTE deja menos filas para el join. Filtrarlo
    // afuera obligaría a materializar todos los clientes con órdenes.
    expect($q->sql)->toContain('HAVING COUNT(*) > ?');
});

it('nunca interpola valores en el SQL: todo va como binding', function () {
    $q = compilar([
        'op' => 'and',
        'nodes' => [
            ['op' => 'attr', 'field' => 'customer.country', 'cmp' => 'eq', 'value' => "AR'; DROP TABLE orders; --"],
            [
                'op' => 'orders', 'id' => 'frec',
                'window' => ['kind' => 'relative', 'last_days' => 90],
                'filter' => ['status_in' => ['delivered']],
                'having' => [['agg' => 'count', 'cmp' => 'gt', 'value' => 3]],
            ],
        ],
    ]);

    expect($q->sql)->not->toContain('DROP TABLE')
        ->and($q->bindings)->toContain("AR'; DROP TABLE orders; --");
});

it('resuelve la ventana contra el instante congelado, no contra ahora', function () {
    $asOf = CarbonImmutable::parse('2026-08-27T12:00:00Z');

    $node = (new ConditionParser)->parse(['schema_version' => 1, 'root' => [
        'op' => 'orders', 'id' => 'frec',
        'window' => ['kind' => 'relative', 'last_days' => 90],
        'filter' => ['status_in' => []],
        'having' => [['agg' => 'count', 'cmp' => 'gte', 'value' => 1]],
    ]]);

    $q = (new SqlCompiler)->compile($node, TENANT, $asOf);

    // Si un worker retoma la tanda diez minutos después, "los últimos 90
    // días" tiene que seguir significando lo mismo.
    $fechas = array_values(array_filter($q->bindings, fn ($b) => $b instanceof CarbonImmutable));

    expect($fechas[0]->toIso8601ZuluString())->toBe('2026-05-29T12:00:00Z')
        ->and($fechas[1]->toIso8601ZuluString())->toBe('2026-08-27T12:00:00Z');
});

it('exige email válido siempre, aunque la condición no lo pida', function () {
    $q = compilar(['op' => 'attr', 'field' => 'customer.country', 'cmp' => 'eq', 'value' => 'AR']);

    // No es parte de la condición: es requisito estructural. Va acá y no
    // como nodo del DSL para que el usuario no pueda quitarlo.
    expect($q->sql)->toContain("customers.email_status = 'valid'")
        ->and($q->sql)->toContain('customers.email_normalized IS NOT NULL');
});

it('filtra por tenant aunque RLS ya lo garantice', function () {
    $q = compilar(['op' => 'attr', 'field' => 'customer.country', 'cmp' => 'eq', 'value' => 'AR']);

    // Defensa en profundidad: si alguien corriera esta consulta con un rol
    // que se saltea RLS, el filtro de la aplicación sigue puesto.
    expect($q->sql)->toContain('customers.tenant_id = ?')
        ->and($q->bindings)->toContain(TENANT);
});

it('produce una huella distinta si la forma del SQL cambia', function () {
    $a = compilar(['op' => 'attr', 'field' => 'customer.country', 'cmp' => 'eq', 'value' => 'AR']);
    $b = compilar([
        'op' => 'orders', 'id' => 'frec',
        'window' => ['kind' => 'relative', 'last_days' => 90],
        'filter' => ['status_in' => []],
        'having' => [['agg' => 'count', 'cmp' => 'gt', 'value' => 3]],
    ]);

    // La huella se guarda en la ejecución: permite detectar que el
    // compilador cambió entre dos corridas de la misma condición.
    expect($b->fingerprint)->not->toBe($a->fingerprint)
        ->and($a->fingerprint)->toMatch('/^[0-9a-f]{64}$/');
});

it('maneja el comparador `in` con lista vacía sin generar SQL inválido', function () {
    $q = compilar(['op' => 'attr', 'field' => 'customer.country', 'cmp' => 'in', 'value' => []]);

    expect($q->sql)->toContain('FALSE');
});

it('ordena los bindings igual que el texto del SQL', function () {
    $q = compilar([
        'op' => 'and',
        'nodes' => [
            ['op' => 'attr', 'field' => 'customer.country', 'cmp' => 'eq', 'value' => 'AR'],
            [
                'op' => 'orders', 'id' => 'frec',
                'window' => ['kind' => 'relative', 'last_days' => 90],
                'filter' => ['status_in' => ['delivered']],
                'having' => [['agg' => 'count', 'cmp' => 'gt', 'value' => 3]],
            ],
        ],
    ]);

    // PostgreSQL liga los parámetros POR POSICIÓN. Los CTE aparecen primero
    // en el texto, así que sus bindings van primero — aunque la hoja que los
    // generó se haya procesado después.
    //
    // Este test existe porque la primera versión los concatenaba en orden de
    // procesamiento: producía una consulta sintácticamente válida que
    // comparaba el tenant contra un umbral de dinero. No fallaba, devolvía
    // resultados incorrectos.
    // Las fechas se normalizan: dos CarbonImmutable equivalentes no son el
    // mismo objeto, y acá lo que importa es el valor y la posición.
    $normalizados = array_map(
        static fn ($b) => $b instanceof CarbonImmutable ? $b->toIso8601ZuluString() : $b,
        $q->bindings,
    );

    expect($normalizados)->toBe([
        TENANT,                    // CTE: orders.tenant_id
        '2026-05-29T12:00:00Z',    // CTE: desde
        '2026-08-27T12:00:00Z',    // CTE: hasta
        'delivered',               // CTE: status_in
        3,                         // CTE: having
        TENANT,                    // WHERE: customers.tenant_id
        'AR',                      // WHERE: atributo
    ]);
});

it('pone el tenant del WHERE después de los bindings de los CTE', function () {
    $q = compilar([
        'op' => 'orders', 'id' => 'frec',
        'window' => ['kind' => 'relative', 'last_days' => 30],
        'filter' => ['status_in' => []],
        'having' => [['agg' => 'avg_reporting_minor', 'cmp' => 'gte', 'value' => 100000]],
    ]);

    // El umbral de dinero es el ÚLTIMO binding del CTE; el tenant es el
    // siguiente. Invertirlos es exactamente el bug que este test cierra.
    expect($q->bindings[3])->toBe(100000)
        ->and($q->bindings[4])->toBe(TENANT);
});
