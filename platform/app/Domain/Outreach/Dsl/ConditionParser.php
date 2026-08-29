<?php

declare(strict_types=1);

namespace App\Domain\Outreach\Dsl;

use App\Domain\Outreach\Dsl\Exceptions\InvalidCondition;
use App\Domain\Outreach\Dsl\Node\AndNode;
use App\Domain\Outreach\Dsl\Node\AttributeNode;
use App\Domain\Outreach\Dsl\Node\ConditionNode;
use App\Domain\Outreach\Dsl\Node\OrdersAggregateNode;
use App\Domain\Sales\Enums\OrderStatus;

/**
 * Convierte el JSON de una condicion en un arbol de objetos, o explica por
 * que no puede.
 *
 * Valida ANTES de compilar, y valida todo. Un parser permisivo que deja pasar
 * un nodo desconocido para que el compilador lo ignore produce una audiencia
 * mas grande de la que el usuario pidio, y el sintoma es gente recibiendo
 * correos que no deberia — descubierto por el destinatario, no por nosotros.
 */
final class ConditionParser
{
    /**
     * Version del esquema del DSL.
     *
     * Va guardada en cada condicion y copiada en cada ejecucion. Cuando el
     * lenguaje cambie, las condiciones viejas van a poder decir con que
     * version se escribieron en vez de fallar de formas creativas.
     */
    public const SCHEMA_VERSION = 1;

    /** @var list<string> */
    private const COMPARATORS = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'in'];

    /** @var list<string> */
    private const AGGREGATES = ['count', 'sum_reporting_minor', 'avg_reporting_minor'];

    /**
     * @param  array<string, mixed>  $dsl
     */
    public function parse(array $dsl): ConditionNode
    {
        $version = $dsl['schema_version'] ?? null;

        if ($version !== self::SCHEMA_VERSION) {
            throw InvalidCondition::malformed(
                'Se esperaba `schema_version: '.self::SCHEMA_VERSION.'`, llegó '.var_export($version, true).'.',
                '$',
            );
        }

        if (! is_array($dsl['root'] ?? null)) {
            throw InvalidCondition::malformed('Falta el nodo `root`.', '$');
        }

        return $this->node($dsl['root'], '$.root');
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function node(array $raw, string $path): ConditionNode
    {
        $op = $raw['op'] ?? null;

        if (! is_string($op)) {
            throw InvalidCondition::malformed('Todo nodo necesita un `op`.', $path);
        }

        return match ($op) {
            'and' => $this->andNode($raw, $path),
            'attr' => $this->attributeNode($raw, $path),
            'orders' => $this->ordersNode($raw, $path),
            default => throw InvalidCondition::unsupportedNode($op, $path),
        };
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function andNode(array $raw, string $path): AndNode
    {
        $nodes = $raw['nodes'] ?? null;

        if (! is_array($nodes) || $nodes === []) {
            throw InvalidCondition::malformed('`and` necesita al menos un nodo hijo.', $path);
        }

        return new AndNode(
            $this->id($raw, 'and'),
            array_map(
                fn (mixed $child, int $i) => is_array($child)
                    ? $this->node($child, "{$path}.nodes[{$i}]")
                    : throw InvalidCondition::malformed('Cada hijo tiene que ser un objeto.', "{$path}.nodes[{$i}]"),
                $nodes,
                array_keys($nodes),
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function attributeNode(array $raw, string $path): AttributeNode
    {
        $field = $raw['field'] ?? null;

        if (! is_string($field)) {
            throw InvalidCondition::malformed('`attr` necesita un `field`.', $path);
        }

        // Falla acá si el campo no está en la lista blanca.
        FieldRegistry::resolve($field, $path);

        $cmp = $raw['cmp'] ?? 'eq';

        if (! in_array($cmp, self::COMPARATORS, strict: true)) {
            throw InvalidCondition::malformed(
                "Comparador [{$cmp}] desconocido. Disponibles: ".implode(', ', self::COMPARATORS).'.',
                $path,
            );
        }

        if (! array_key_exists('value', $raw)) {
            throw InvalidCondition::malformed('`attr` necesita un `value`.', $path);
        }

        if ($cmp === 'in' && ! is_array($raw['value'])) {
            throw InvalidCondition::malformed('El comparador `in` necesita una lista de valores.', $path);
        }

        return new AttributeNode($this->id($raw, 'attr'), $field, $cmp, $raw['value']);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function ordersNode(array $raw, string $path): OrdersAggregateNode
    {
        if (! is_array($raw['window'] ?? null)) {
            throw InvalidCondition::malformed('`orders` necesita una `window`.', $path);
        }

        $window = Window::parse($raw['window'], "{$path}.window");

        $statuses = $raw['filter']['status_in'] ?? [];

        if (! is_array($statuses)) {
            throw InvalidCondition::malformed('`filter.status_in` tiene que ser una lista.', $path);
        }

        foreach ($statuses as $status) {
            if (! is_string($status) || OrderStatus::tryFrom($status) === null) {
                throw InvalidCondition::malformed(
                    'Estado de orden desconocido: '.var_export($status, true).'.',
                    "{$path}.filter.status_in",
                );
            }
        }

        $having = $raw['having'] ?? [];

        if (! is_array($having) || $having === []) {
            throw InvalidCondition::malformed(
                '`orders` sin `having` no filtra nada; sería un nodo que siempre da verdadero.',
                $path,
            );
        }

        $parsed = [];

        foreach ($having as $i => $clause) {
            $parsed[] = $this->havingClause($clause, "{$path}.having[{$i}]");
        }

        return new OrdersAggregateNode(
            $this->id($raw, 'orders'),
            $window,
            array_values(array_map(strval(...), $statuses)),
            $parsed,
        );
    }

    /**
     * @return array{agg: string, cmp: string, value: int|float}
     */
    private function havingClause(mixed $clause, string $path): array
    {
        if (! is_array($clause)) {
            throw InvalidCondition::malformed('Cada cláusula de `having` tiene que ser un objeto.', $path);
        }

        $agg = $clause['agg'] ?? null;

        if (! in_array($agg, self::AGGREGATES, strict: true)) {
            throw InvalidCondition::malformed(
                'Agregación ['.var_export($agg, true).'] desconocida. Disponibles: '.implode(', ', self::AGGREGATES).'.',
                $path,
            );
        }

        $cmp = $clause['cmp'] ?? null;

        // `in` no tiene sentido sobre un agregado numérico.
        if (! in_array($cmp, ['gt', 'gte', 'lt', 'lte', 'eq'], strict: true)) {
            throw InvalidCondition::malformed("Comparador [{$cmp}] no aplica a una agregación.", $path);
        }

        if (! is_numeric($clause['value'] ?? null)) {
            throw InvalidCondition::malformed('`value` tiene que ser numérico.', $path);
        }

        return ['agg' => $agg, 'cmp' => $cmp, 'value' => $clause['value'] + 0];
    }

    /**
     * Identificador del nodo, para poder explicar despues que lo decidio.
     *
     * Si el usuario no lo pone, se genera uno estable por posicion. Estable
     * importa: la explicacion de por que alguien no entro cita el id del
     * nodo, y no serviria si cambiara entre ejecuciones.
     *
     * @param  array<string, mixed>  $raw
     */
    private function id(array $raw, string $fallbackPrefix): string
    {
        $id = $raw['id'] ?? null;

        if (is_string($id) && preg_match('/^[a-z0-9_\-]{1,64}$/i', $id) === 1) {
            return $id;
        }

        return $fallbackPrefix.'_'.substr(hash('sha256', json_encode($raw) ?: ''), 0, 8);
    }

    /**
     * Huella estable de una condicion.
     *
     * Se calcula sobre el arbol ya parseado y re-serializado, no sobre el JSON
     * crudo: asi dos condiciones que sólo difieren en espacios o en el orden
     * de las claves dan el mismo hash. De ahi sale la clave que impide
     * reenviarle a alguien por la misma causa.
     */
    public static function hash(ConditionNode $root): string
    {
        return hash('sha256', json_encode([
            'schema_version' => self::SCHEMA_VERSION,
            'root' => $root->toArray(),
        ], JSON_UNESCAPED_UNICODE) ?: '');
    }
}
