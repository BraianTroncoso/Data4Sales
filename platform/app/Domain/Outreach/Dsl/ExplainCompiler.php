<?php

declare(strict_types=1);

namespace App\Domain\Outreach\Dsl;

use App\Domain\Outreach\Dsl\Node\AndNode;
use App\Domain\Outreach\Dsl\Node\AttributeNode;
use App\Domain\Outreach\Dsl\Node\ConditionNode;
use App\Domain\Outreach\Dsl\Node\OrdersAggregateNode;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Responde "por que este cliente cumple, o no cumple, la condicion".
 *
 * Compila el MISMO arbol de otra forma: en vez de resolver un conjunto,
 * evalua un cliente y devuelve el resultado nodo por nodo. Es deliberado que
 * comparta el arbol y no reimplemente la semantica — tener dos
 * implementaciones de "que significa esta condicion" garantiza que en algun
 * momento se contradigan, y la contradiccion aparece justo cuando alguien
 * pregunta por que recibio un correo que no deberia haber recibido. Es el
 * mismo criterio que D-15 con los segmentos RFM.
 *
 * ADVERTENCIA que viaja en la respuesta: esto se evalua AHORA, no en el
 * instante congelado de la ejecucion. Los datos pudieron cambiar. La
 * respuesta lo dice explicitamente en vez de presentarse como un registro
 * historico: una reconstruccion presentada como hecho es peor que no tener
 * respuesta.
 */
final class ExplainCompiler
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function explain(ConditionNode $root, string $customerId, CarbonImmutable $asOf): array
    {
        $leaves = $root instanceof AndNode ? $root->nodes : [$root];
        $nodos = [];
        $cumpleTodo = true;

        foreach ($leaves as $leaf) {
            $resultado = match (true) {
                $leaf instanceof AttributeNode => $this->explainAttribute($leaf, $customerId),
                $leaf instanceof OrdersAggregateNode => $this->explainOrders($leaf, $customerId, $asOf),
                default => ['node_id' => $leaf->id(), 'matched' => null, 'detail' => 'Nodo no evaluable por este compilador.'],
            };

            $cumpleTodo = $cumpleTodo && ($resultado['matched'] === true);
            $nodos[] = $resultado;
        }

        return [
            'customer_id' => $customerId,
            'matches_condition' => $cumpleTodo,
            // Etiqueta obligatoria. Una reconstrucción presentada como si
            // fuera un registro es peor que no tener respuesta.
            'evaluado' => 'ahora',
            'evaluated_at' => CarbonImmutable::now()->toIso8601String(),
            'window_as_of' => $asOf->toIso8601String(),
            'nodes' => $nodos,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function explainAttribute(AttributeNode $node, string $customerId): array
    {
        $field = FieldRegistry::resolve($node->field, $node->id());

        $row = DB::connection($this->connection())->selectOne(
            <<<SQL
            SELECT {$field['sql']} AS valor
            FROM customers
            LEFT JOIN customer_metrics ON customer_metrics.customer_id = customers.id
            WHERE customers.tenant_id = ? AND customers.id = ?
            SQL,
            [$this->tenantId(), $customerId],
        );

        $actual = $row->valor ?? null;
        $matched = $this->compare($actual, $node->cmp, $node->value);

        return [
            'node_id' => $node->id(),
            'type' => 'attr',
            'field' => $node->field,
            'expected' => "{$node->cmp} ".json_encode($node->value),
            'actual' => $actual,
            'matched' => $matched,
            'uses_derived_metrics' => FieldRegistry::usesDerivedMetrics($node->field),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function explainOrders(OrdersAggregateNode $node, string $customerId, CarbonImmutable $asOf): array
    {
        [$from, $to] = $node->window->resolve($asOf);

        $bindings = [$this->tenantId(), $customerId, $from, $to];
        $statusFilter = '';

        if ($node->statusIn !== []) {
            $statusFilter = ' AND o.status IN ('.implode(',', array_fill(0, count($node->statusIn), '?')).')';
            $bindings = [...$bindings, ...$node->statusIn];
        }

        // Misma proyeccion que el CTE del compilador de conjuntos, y por el
        // mismo motivo: si esta consulta redondeara el promedio y la otra no,
        // la audiencia y su explicacion contestarian distinto sobre el mismo
        // cliente. La definicion vive en un solo lugar.
        $proyeccion = Aggregates::selectList();

        $row = DB::connection($this->connection())->selectOne(
            <<<SQL
            SELECT {$proyeccion}
            FROM orders o
            WHERE o.tenant_id = ?
              AND o.customer_id = ?
              AND o.placed_at >= ?
              AND o.placed_at <  ?{$statusFilter}
            SQL,
            $bindings,
        );

        $clausulas = [];
        $matched = true;

        foreach ($node->having as $clause) {
            // `toPhp` y no un `(int)` a mano: castear el promedio a entero
            // aca reabre la divergencia por el otro lado — el HAVING compara
            // 99.999,6 y PHP compararia 99.999.
            $actual = Aggregates::toPhp($clause['agg'], $row->{$clause['agg']});
            $ok = $this->compare($actual, $clause['cmp'], $clause['value']);
            $matched = $matched && $ok;

            $clausulas[] = [
                'agg' => $clause['agg'],
                'expected' => "{$clause['cmp']} {$clause['value']}",
                'actual' => $actual,
                'matched' => $ok,
            ];
        }

        return [
            'node_id' => $node->id(),
            'type' => 'orders',
            'window' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'status_in' => $node->statusIn,
            'clauses' => $clausulas,
            'matched' => $matched,
        ];
    }

    private function compare(mixed $actual, string $cmp, mixed $expected): bool
    {
        if ($actual === null) {
            return false;
        }

        return match ($cmp) {
            'eq' => $actual == $expected,
            'neq' => $actual != $expected,
            'gt' => $actual > $expected,
            'gte' => $actual >= $expected,
            'lt' => $actual < $expected,
            'lte' => $actual <= $expected,
            'in' => in_array($actual, (array) $expected, strict: false),
            default => false,
        };
    }

    private function tenantId(): string
    {
        return $this->context->currentOrFail()->id;
    }

    private function connection(): string
    {
        return $this->context->connectionName();
    }
}
