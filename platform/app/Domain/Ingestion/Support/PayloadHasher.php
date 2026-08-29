<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Support;

/**
 * Huella estable de un registro, para deteccion de duplicados exactos.
 *
 * La canonicalizacion es lo que hace que la huella sirva: dos envios del
 * mismo registro con las claves en distinto orden, o con espacios distintos
 * en el JSON, son el mismo registro y tienen que dar el mismo hash. Sin
 * ordenar recursivamente, un cliente que serializa con otra libreria
 * parecería estar mandando datos nuevos en cada reintento.
 *
 * La huella se guarda con un indice unico por tenant, asi que la
 * deduplicacion la garantiza el motor de forma atomica y no la aplicacion.
 * Dos workers procesando el mismo lote en paralelo no pueden insertar el
 * mismo evento dos veces.
 */
final class PayloadHasher
{
    public static function hash(mixed $payload): string
    {
        return hash('sha256', self::canonicalize($payload));
    }

    private static function canonicalize(mixed $value): string
    {
        return json_encode(
            self::sortRecursively($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        ) ?: '';
    }

    private static function sortRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        // Las listas conservan su orden: [a, b] y [b, a] son distintos como
        // items de una orden. Solo se ordenan las claves de los objetos.
        if (array_is_list($value)) {
            return array_map(self::sortRecursively(...), $value);
        }

        ksort($value);

        return array_map(self::sortRecursively(...), $value);
    }
}
