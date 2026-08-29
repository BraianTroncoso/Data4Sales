<?php

declare(strict_types=1);

namespace App\Domain\Outreach\Dsl;

use App\Domain\Outreach\Dsl\Exceptions\InvalidCondition;

/**
 * Lista blanca de lo que una condicion puede mirar.
 *
 * Es una lista blanca y no una traduccion generica de `customer.loquesea` a
 * una columna. Dos razones, y la segunda es la importante:
 *
 * 1. Sin lista, el nombre de la columna vendria del JSON que escribio un
 *    usuario, y eso es inyeccion SQL por mas prepared statements que haya
 *    alrededor: un identificador no se puede parametrizar.
 *
 * 2. Sin lista, una condicion podria filtrar por cualquier columna que exista
 *    hoy en la tabla. Que `customers` gane manana una columna interna no
 *    deberia convertirla automaticamente en algo sobre lo que se segmenta.
 */
final class FieldRegistry
{
    /**
     * Campo del DSL => [tabla, columna, tipo].
     *
     * @var array<string, array{string, string, string}>
     */
    private const FIELDS = [
        'customer.email_status' => ['customers', 'email_status', 'string'],
        'customer.country' => ['customers', 'country', 'string'],
        'customer.segment' => ['customer_metrics', 'segment', 'string'],
        'customer.orders_count' => ['customer_metrics', 'net_orders_count', 'int'],
        'customer.total_net_minor' => ['customer_metrics', 'total_net_reporting_minor', 'int'],
        'customer.avg_order_minor' => ['customer_metrics', 'avg_order_reporting_minor', 'int'],
        'customer.recency_days' => ['customer_metrics', 'recency_days', 'int'],
    ];

    /**
     * @return array{table: string, column: string, type: string, sql: string}
     */
    public static function resolve(string $field, string $path): array
    {
        if (! isset(self::FIELDS[$field])) {
            throw InvalidCondition::unknownField($field, $path);
        }

        [$table, $column, $type] = self::FIELDS[$field];

        return [
            'table' => $table,
            'column' => $column,
            'type' => $type,
            // Seguro de interpolar: el valor sale de esta constante, nunca
            // del JSON del usuario.
            'sql' => "{$table}.{$column}",
        ];
    }

    /**
     * @return list<string>
     */
    public static function available(): array
    {
        return array_keys(self::FIELDS);
    }

    /**
     * Si el campo vive en la tabla de metricas derivadas.
     *
     * Importa para la guarda de frescura: una condicion que sólo mira
     * columnas de `customers` es exacta al instante; una que mira métricas
     * derivadas es tan fresca como el último recómputo.
     */
    public static function usesDerivedMetrics(string $field): bool
    {
        return (self::FIELDS[$field][0] ?? null) === 'customer_metrics';
    }
}
