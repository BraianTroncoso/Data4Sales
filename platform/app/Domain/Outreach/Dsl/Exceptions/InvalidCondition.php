<?php

declare(strict_types=1);

namespace App\Domain\Outreach\Dsl\Exceptions;

use RuntimeException;

/**
 * La condicion no se puede interpretar, o usa algo que este recorte no
 * soporta.
 *
 * Es deliberadamente ruidosa. Un motor que acepta un nodo que no entiende y
 * lo compila "lo mejor que puede" manda correos a la gente equivocada sin
 * que nadie se entere. Fallar fuerte al definir la condicion es infinitamente
 * mas barato que descubrirlo despues del envio.
 */
final class InvalidCondition extends RuntimeException
{
    public static function unsupportedNode(string $op, string $path): self
    {
        return new self(
            "El nodo [{$op}] en {$path} no está soportado por este compilador. ".
            'El recorte implementa `and`, `attr` y `orders` con ventana relativa. '.
            'El resto está diseñado en docs/parte-3-motor-de-acciones.md pero no implementado.'
        );
    }

    public static function unknownField(string $field, string $path): self
    {
        return new self("El campo [{$field}] en {$path} no existe o no es consultable.");
    }

    public static function malformed(string $detail, string $path): self
    {
        return new self("Condición mal formada en {$path}: {$detail}");
    }
}
