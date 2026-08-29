<?php

declare(strict_types=1);

namespace App\Domain\Outreach\Dsl\Node;

/**
 * Predicado sobre un atributo del cliente.
 *
 * El campo se resuelve contra FieldRegistry, que es lista blanca; el valor
 * siempre viaja como binding. Nada de lo que escribio el usuario termina
 * interpolado en el SQL.
 */
final readonly class AttributeNode implements ConditionNode
{
    public function __construct(
        private string $id,
        public string $field,
        public string $cmp,
        public mixed $value,
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
            'op' => 'attr',
            'id' => $this->id,
            'field' => $this->field,
            'cmp' => $this->cmp,
            'value' => $this->value,
        ];
    }
}
