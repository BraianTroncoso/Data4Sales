<?php

declare(strict_types=1);

namespace App\Domain\Outreach\Dsl;

use App\Domain\Outreach\Dsl\Exceptions\InvalidCondition;
use App\Domain\Outreach\Dsl\Node\AndNode;
use App\Domain\Outreach\Dsl\Node\AttributeNode;
use App\Domain\Outreach\Dsl\Node\ConditionNode;
use App\Domain\Outreach\Dsl\Node\OrdersAggregateNode;
use Carbon\CarbonImmutable;

/**
 * Traduce un arbol de condiciones a una consulta SQL.
 *
 * La decision central: **ninguna hoja agregada se compila como subconsulta
 * correlacionada**. Cada una es un CTE con una sola pasada agrupada por
 * cliente, y el arbol booleano se reconstruye uniendo esos CTEs.
 *
 * Con ciento cincuenta mil clientes, una subconsulta correlacionada por hoja
 * significa ciento cincuenta mil ejecuciones de esa subconsulta. Con CTEs son
 * dos recorridos de indice y un hash join, sin importar cuantos clientes haya.
 *
 * Bajo un `and` sin negacion, toda hoja agregada es OBLIGATORIA: el cliente
 * tiene que aparecer en su CTE. Eso permite empujar el filtro al HAVING del
 * propio CTE y unir con INNER JOIN, que descarta lo mas caro lo antes posible.
 * Si el recorte soportara `or`, esas hojas dejarian de ser obligatorias y
 * habria que pasar a LEFT JOIN y evaluar el booleano sobre columnas nulas.
 */
final class SqlCompiler
{
    public const VERSION = 'slice-1';

    /** @var array<string, string> */
    private const CMP_SQL = [
        'eq' => '=',
        'neq' => '<>',
        'gt' => '>',
        'gte' => '>=',
        'lt' => '<',
        'lte' => '<=',
    ];

    /**
     * Compila la condicion en una consulta que devuelve los clientes que la
     * cumplen, junto con su email ya normalizado.
     */
    public function compile(ConditionNode $root, string $tenantId, CarbonImmutable $asOf): CompiledQuery
    {
        // El recorte sólo compila un `and` en la raíz (o una hoja suelta).
        $leaves = $root instanceof AndNode ? $root->nodes : [$root];

        $ctes = [];
        $joins = [];
        $wheres = [];

        // Los bindings se acumulan en TRES listas separadas y se concatenan al
        // final en el orden en que aparecen en el texto del SQL. PostgreSQL los
        // liga por posición, no por nombre: mezclarlos produce una consulta
        // sintácticamente válida que compara el tenant contra un umbral de
        // dinero. Es un bug que no rompe, devuelve resultados incorrectos.
        $cteBindings = [];
        $whereBindings = [];

        foreach ($leaves as $leaf) {
            match (true) {
                $leaf instanceof AttributeNode => $this->compileAttribute($leaf, $wheres, $whereBindings),
                $leaf instanceof OrdersAggregateNode => $this->compileOrders($leaf, $tenantId, $asOf, $ctes, $joins, $cteBindings),
                default => throw InvalidCondition::unsupportedNode(
                    $leaf::class,
                    'raíz: sólo se admiten hojas `attr` y `orders` bajo un `and`.',
                ),
            };
        }

        // El email es requisito estructural, no parte de la condición: no se
        // le puede escribir a quien no tiene dirección válida. Va acá y no
        // como un nodo del DSL para que el usuario no pueda quitarlo.
        $wheres[] = "customers.email_status = 'valid'";
        $wheres[] = 'customers.email_normalized IS NOT NULL';

        $cteSql = $ctes === [] ? '' : "WITH \n".implode(",\n", $ctes)."\n";

        $sql = $cteSql.
            "SELECT customers.id AS customer_id,\n".
            "       customers.email_normalized AS email,\n".
            "       customers.name AS name\n".
            "FROM customers\n".
            "LEFT JOIN customer_metrics ON customer_metrics.customer_id = customers.id\n".
            (($joins === []) ? '' : implode("\n", $joins)."\n").
            "WHERE customers.tenant_id = ?\n".
            // Sin ternario: `$wheres` nunca esta vacio porque las dos
            // condiciones de email se empujan siempre, unas lineas mas arriba.
            // La rama vacia era codigo muerto.
            '  AND '.implode("\n  AND ", $wheres)."\n".
            'ORDER BY customers.id';

        // Orden del texto: los CTEs, después el filtro de tenant del WHERE,
        // después el resto del WHERE.
        //
        // El tenant va como binding aunque Row-Level Security ya lo garantice.
        // Defensa en profundidad: si alguien corriera esta consulta con un rol
        // que se saltea RLS, el filtro de la aplicación sigue puesto.
        $all = [...$cteBindings, $tenantId, ...$whereBindings];

        return new CompiledQuery(
            sql: $sql,
            bindings: $all,
            fingerprint: hash('sha256', $sql),
        );
    }

    /**
     * @param  list<string>  $wheres
     * @param  list<mixed>  $bindings
     */
    private function compileAttribute(AttributeNode $node, array &$wheres, array &$bindings): void
    {
        $field = FieldRegistry::resolve($node->field, $node->id());

        if ($node->cmp === 'in') {
            /** @var list<mixed> $values */
            $values = array_values((array) $node->value);

            if ($values === []) {
                // `in []` no matchea nada. Se emite explícito en vez de
                // producir SQL inválido.
                $wheres[] = 'FALSE';

                return;
            }

            $wheres[] = $field['sql'].' IN ('.implode(',', array_fill(0, count($values), '?')).')';
            $bindings = [...$bindings, ...$values];

            return;
        }

        $wheres[] = $field['sql'].' '.self::CMP_SQL[$node->cmp].' ?';
        $bindings[] = $node->value;
    }

    /**
     * Una hoja agregada = un CTE + un INNER JOIN.
     *
     * @param  list<string>  $ctes
     * @param  list<string>  $joins
     * @param  list<mixed>  $bindings
     */
    private function compileOrders(
        OrdersAggregateNode $node,
        string $tenantId,
        CarbonImmutable $asOf,
        array &$ctes,
        array &$joins,
        array &$bindings,
    ): void {
        // El alias sale del id del nodo, que el parser ya validó contra
        // /^[a-z0-9_-]+$/i. No hay forma de que llegue algo arbitrario acá.
        $alias = 'agg_'.preg_replace('/[^a-z0-9_]/i', '_', $node->id());

        [$from, $to] = $node->window->resolve($asOf);

        $cteBindings = [$tenantId, $from, $to];
        $statusFilter = '';

        if ($node->statusIn !== []) {
            $statusFilter = "\n      AND o.status IN (".implode(',', array_fill(0, count($node->statusIn), '?')).')';
            $cteBindings = [...$cteBindings, ...$node->statusIn];
        }

        $having = [];

        foreach ($node->having as $clause) {
            // La expresion sale de `Aggregates` y no de una tabla local: es
            // la misma que evalua el explicador. Cuando cada uno tenia la
            // suya, el promedio se comparaba exacto aca y redondeado alla, y
            // la audiencia y su explicacion decian cosas distintas del mismo
            // cliente.
            $having[] = Aggregates::expression($clause['agg']).' '.self::CMP_SQL[$clause['cmp']].' ?';
            $cteBindings[] = $clause['value'];
        }

        $proyeccion = Aggregates::selectList();

        $ctes[] = <<<SQL
        {$alias} AS (
            SELECT o.customer_id,
                   {$proyeccion}
            FROM orders o
            WHERE o.tenant_id = ?
              AND o.placed_at >= ?
              AND o.placed_at <  ?{$statusFilter}
            GROUP BY o.customer_id
            HAVING {$this->joinHaving($having)}
        )
        SQL;

        // INNER JOIN: la hoja es obligatoria porque está bajo un `and` sin
        // negación. Descarta lo más caro lo antes posible.
        $joins[] = "INNER JOIN {$alias} ON {$alias}.customer_id = customers.id";

        $bindings = [...$bindings, ...$cteBindings];
    }

    /**
     * @param  list<string>  $having
     */
    private function joinHaving(array $having): string
    {
        return $having === [] ? 'TRUE' : implode("\n               AND ", $having);
    }
}
