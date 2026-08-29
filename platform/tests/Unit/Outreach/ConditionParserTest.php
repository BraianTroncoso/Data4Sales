<?php

declare(strict_types=1);

use App\Domain\Outreach\Dsl\ConditionParser;
use App\Domain\Outreach\Dsl\Exceptions\InvalidCondition;
use App\Domain\Outreach\Dsl\Node\AndNode;
use App\Domain\Outreach\Dsl\Node\AttributeNode;
use App\Domain\Outreach\Dsl\Node\OrdersAggregateNode;

function condicion(array $root, int $version = 1): array
{
    return ['schema_version' => $version, 'root' => $root];
}

function frecuencia(array $overrides = []): array
{
    return array_replace_recursive([
        'op' => 'orders',
        'id' => 'frec_90d',
        'window' => ['kind' => 'relative', 'last_days' => 90],
        'filter' => ['status_in' => ['paid', 'shipped', 'delivered']],
        'having' => [['agg' => 'count', 'cmp' => 'gt', 'value' => 3]],
    ], $overrides);
}

it('parsea la condición del caso 1 del enunciado', function () {
    $root = (new ConditionParser)->parse(condicion([
        'op' => 'and',
        'nodes' => [
            ['op' => 'attr', 'id' => 'email_ok', 'field' => 'customer.email_status', 'cmp' => 'eq', 'value' => 'valid'],
            frecuencia(['having' => [
                ['agg' => 'count', 'cmp' => 'gt', 'value' => 3],
                ['agg' => 'avg_reporting_minor', 'cmp' => 'gte', 'value' => 8000],
            ]]),
        ],
    ]));

    expect($root)->toBeInstanceOf(AndNode::class)
        ->and($root->nodes[0])->toBeInstanceOf(AttributeNode::class)
        ->and($root->nodes[1])->toBeInstanceOf(OrdersAggregateNode::class)
        ->and($root->nodes[1]->window->lastDays)->toBe(90)
        ->and($root->nodes[1]->having)->toHaveCount(2);
});

it('rechaza explícitamente los operadores que no implementa', function (string $op) {
    // Un motor que acepta un `or` y lo compila mal es peor que uno que dice
    // "todavía no". El mensaje tiene que nombrar el operador.
    expect(fn () => (new ConditionParser)->parse(condicion(['op' => $op, 'nodes' => []])))
        ->toThrow(InvalidCondition::class, $op);
})->with(['or', 'not', 'purchased', 'last_order']);

it('rechaza una ventana absoluta en vez de tratarla como relativa', function () {
    expect(fn () => (new ConditionParser)->parse(condicion(frecuencia([
        'window' => ['kind' => 'absolute', 'from' => '2026-01-01', 'to' => '2026-03-01'],
    ]))))->toThrow(InvalidCondition::class);
});

it('rechaza un campo que no está en la lista blanca', function () {
    // Sin lista blanca, el nombre de la columna vendría del JSON de un
    // usuario: eso es inyección SQL, porque un identificador no se puede
    // parametrizar.
    expect(fn () => (new ConditionParser)->parse(condicion([
        'op' => 'attr', 'field' => 'customers.password', 'cmp' => 'eq', 'value' => 'x',
    ])))->toThrow(InvalidCondition::class, 'customers.password');
});

it('rechaza un estado de orden inventado', function () {
    expect(fn () => (new ConditionParser)->parse(condicion(frecuencia([
        'filter' => ['status_in' => ['entregadisimo']],
    ]))))->toThrow(InvalidCondition::class);
});

it('rechaza un nodo de órdenes sin having, porque siempre daría verdadero', function () {
    $node = frecuencia();
    unset($node['having']);

    expect(fn () => (new ConditionParser)->parse(condicion($node)))
        ->toThrow(InvalidCondition::class);
});

it('rechaza una versión de esquema distinta', function () {
    expect(fn () => (new ConditionParser)->parse(condicion(frecuencia(), version: 99)))
        ->toThrow(InvalidCondition::class, 'schema_version');
});

it('da la misma huella sin importar el orden de las claves', function () {
    $parser = new ConditionParser;

    $a = $parser->parse(condicion(['op' => 'attr', 'id' => 'x', 'field' => 'customer.country', 'cmp' => 'eq', 'value' => 'AR']));
    $b = $parser->parse(condicion(['value' => 'AR', 'cmp' => 'eq', 'field' => 'customer.country', 'id' => 'x', 'op' => 'attr']));

    // La huella le da identidad estable a la condición, y de ahí sale la
    // clave que impide reenviar por la misma causa. Si dependiera del formato
    // del JSON, reformatearlo haría que todos volvieran a recibir.
    expect(ConditionParser::hash($b))->toBe(ConditionParser::hash($a));
});

it('cambia la huella si cambia el umbral', function () {
    $parser = new ConditionParser;

    $a = $parser->parse(condicion(frecuencia()));
    $b = $parser->parse(condicion(frecuencia(['having' => [['agg' => 'count', 'cmp' => 'gt', 'value' => 5]]])));

    expect(ConditionParser::hash($b))->not->toBe(ConditionParser::hash($a));
});

it('genera identificadores estables cuando el usuario no los pone', function () {
    $parser = new ConditionParser;
    $sinId = ['op' => 'attr', 'field' => 'customer.country', 'cmp' => 'eq', 'value' => 'AR'];

    $a = $parser->parse(condicion($sinId));
    $b = $parser->parse(condicion($sinId));

    // La explicación de por qué alguien no entró cita el id del nodo; no
    // serviría si cambiara entre ejecuciones.
    expect($b->id())->toBe($a->id());
});
