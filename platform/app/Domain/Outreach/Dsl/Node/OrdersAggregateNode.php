<?php

declare(strict_types=1);

namespace App\Domain\Outreach\Dsl\Node;

use App\Domain\Outreach\Dsl\Window;

/**
 * Predicado agregado sobre las ordenes de un cliente, dentro de una ventana.
 *
 * Cada uno de estos nodos se compila como UN CTE con una sola pasada agrupada
 * por cliente, no como una subconsulta correlacionada. La diferencia importa:
 * una subconsulta correlacionada se ejecuta una vez por cliente, y con
 * ciento cincuenta mil clientes eso son ciento cincuenta mil consultas.
 */
final readonly class OrdersAggregateNode implements ConditionNode
{
    /**
     * @param  list<array{agg: string, cmp: string, value: int|float}>  $having
     * @param  list<string>  $statusIn
     */
    public function __construct(
        private string $id,
        public Window $window,
        public array $statusIn,
        public array $having,
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'op' => 'orders',
            'id' => $this->id,
            'window' => $this->window->toArray(),
            'filter' => ['status_in' => $this->statusIn],
            'having' => $this->having,
        ];
    }
}
