<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Support;

/**
 * Valida y normaliza un email de comprador.
 *
 * Devuelve tres estados en vez de un booleano, porque las tres situaciones
 * exigen respuestas distintas del sistema:
 *
 *   missing  no vino. No es un error del retailer, hay ventas sin email.
 *   invalid  vino algo que no es un email. Se conserva el original para que
 *            soporte pueda ver que mando el retailer, y se marca.
 *   valid    contactable.
 *
 * Un email invalido NUNCA descarta al cliente. Perder un comprador entero
 * porque su casilla tiene un espacio de mas seria peor que quedarse sin esa
 * casilla, y ademas el cliente sigue teniendo ordenes que hay que contar.
 *
 * LIMITACION CONOCIDA: la validacion exige ASCII, asi que rechaza
 * direcciones internacionalizadas como maria.alvarez@correo.ñandu. Es lo que
 * hace filter_var, y es coherente con SMTP sin la extension SMTPUTF8, que es
 * lo que soportan la mayoria de los proveedores de correo. Se marca como
 * invalido, no se descarta el cliente, asi que el costo del falso positivo
 * es un comprador que no recibe campanas, no un comprador perdido.
 */
final class EmailNormalizer
{
    public const STATUS_VALID = 'valid';

    public const STATUS_INVALID = 'invalid';

    public const STATUS_MISSING = 'missing';

    /**
     * @return array{original: string|null, normalized: string|null, status: string}
     */
    public static function normalize(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return ['original' => null, 'normalized' => null, 'status' => self::STATUS_MISSING];
        }

        $original = trim($value);

        // Espacios internos, que son el error de tipeo mas comun en una caja
        // de tienda, y que de otro modo invalidarian un email recuperable.
        $candidate = strtolower(preg_replace('/\s+/u', '', $original) ?? '');

        $isValid = $candidate !== ''
            && filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false
            // filter_var acepta dominios sin punto ("a@b"), que no existen
            // fuera de una red interna. Para un padron de compradores eso es
            // un dato malo, no un caso valido.
            && str_contains(explode('@', $candidate)[1] ?? '', '.');

        return [
            'original' => $original,
            'normalized' => $isValid ? $candidate : null,
            'status' => $isValid ? self::STATUS_VALID : self::STATUS_INVALID,
        ];
    }
}
