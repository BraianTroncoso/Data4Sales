<?php

declare(strict_types=1);

namespace App\Domain\Outreach\Dsl\Node;

/**
 * Conjuncion. Es el unico operador booleano del recorte.
 *
 * `or` y `not` estan disenados y no implementados, y el parser los rechaza
 * por nombre. La razon esta en el documento: bajo un `or`, una hoja agregada
 * deja de ser obligatoria y no se puede resolver con un INNER JOIN — hay que
 * pasar a LEFT JOIN y reconstruir el booleano sobre columnas nulas. Eso es
 * correcto y es mas codigo; medio implementarlo produciria audiencias
 * silenciosamente equivocadas.
 */
final readonly class AndNode implements ConditionNode
{
    /**
     * @param  list<ConditionNode>  $nodes
     */
    public function __construct(
        private string $id,
        public array $nodes,
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
            'op' => 'and',
            'id' => $this->id,
            'nodes' => array_map(static fn (ConditionNode $n) => $n->toArray(), $this->nodes),
        ];
    }
}
