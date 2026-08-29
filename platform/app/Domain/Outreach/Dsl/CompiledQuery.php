<?php

declare(strict_types=1);

namespace App\Domain\Outreach\Dsl;

/**
 * El SQL de una condicion, con sus bindings separados.
 *
 * Que los bindings vengan aparte no es un detalle de implementacion: es la
 * prueba de que ningun valor que escribio el usuario terminó dentro de la
 * cadena SQL. Los tests asertan sobre las dos cosas por separado justamente
 * para poder demostrarlo.
 */
final readonly class CompiledQuery
{
    /**
     * @param  list<mixed>  $bindings
     */
    public function __construct(
        public string $sql,
        public array $bindings,
        public string $fingerprint,
    ) {}
}
