<?php

declare(strict_types=1);

namespace App\Domain\Outreach\Dsl\Node;

/**
 * Un nodo del arbol de condiciones.
 *
 * El arbol es una estructura de datos, no codigo: se guarda como JSON, se
 * hashea para darle identidad estable, y se compila a SQL en un paso aparte.
 * Esa separacion es lo que permite que la misma condicion se compile de dos
 * formas distintas — una para resolver una audiencia entera y otra para
 * evaluar a un cliente individual — sin duplicar la semantica.
 */
interface ConditionNode
{
    /** Identificador del nodo dentro del arbol, para poder explicar decisiones. */
    public function id(): string;

    /** @return array<string, mixed> */
    public function toArray(): array;
}
