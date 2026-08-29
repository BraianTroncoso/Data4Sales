<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Support;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Interpreta una fecha que llego en cualquier formato y la devuelve en UTC.
 *
 * La decision que mas consecuencias tiene esta en el timestamp SIN zona
 * horaria. "2026-03-01 22:30:00" no significa nada por si solo: son las
 * 22:30 de algun lugar. Se interpreta en la zona del TENANT, no del
 * servidor, porque un retailer argentino que vende a las 22:30 espera ver
 * esa venta el mismo dia, y en UTC ya seria el dia siguiente.
 *
 * Elegir la zona del servidor seria un bug silencioso: funcionaria perfecto
 * en desarrollo con todo en UTC y correria las ventas de medianoche al dia
 * equivocado en produccion.
 *
 * Formatos ambiguos: se soporta d/m/Y, no m/d/Y. "03/04/2026" se lee como 3
 * de abril. Aceptar los dos seria imposible de desambiguar y produciria
 * fechas incorrectas sin ningun sintoma. Un retailer que use el formato
 * estadounidense tiene que declararlo, y por ahora eso significa que sus
 * fechas se rechazan de forma visible en vez de guardarse mal en silencio.
 */
final class TimestampParser
{
    /**
     * Formatos aceptados cuando el valor no trae zona horaria. El orden
     * importa: se prueba del mas especifico al mas general.
     *
     * @var list<string>
     */
    private const NAIVE_FORMATS = [
        'Y-m-d\TH:i:s.u',
        'Y-m-d\TH:i:s',
        'Y-m-d\TH:i',
        'Y-m-d H:i:s',
        'Y-m-d H:i',
        'Y-m-d',
        'd/m/Y H:i:s',
        'd/m/Y H:i',
        'd/m/Y',
        'd-m-Y H:i:s',
        'd-m-Y',
        'Y/m/d H:i:s',
        'Y/m/d',
    ];

    /** Detecta un desplazamiento explicito: Z, +03:00, -0300. */
    private const HAS_OFFSET = '/(?:Z|[+-]\d{2}:?\d{2})$/i';

    public static function parse(mixed $value, string $tenantTimezone): ?CarbonImmutable
    {
        if ($value instanceof CarbonImmutable) {
            return $value->utc();
        }

        if (is_int($value) || (is_string($value) && self::looksLikeUnixTimestamp($value))) {
            return self::fromUnix((int) $value);
        }

        if (! is_string($value)) {
            return null;
        }

        $raw = trim($value);

        if ($raw === '') {
            return null;
        }

        return preg_match(self::HAS_OFFSET, $raw) === 1
            ? self::fromOffsetAware($raw)
            : self::fromNaive($raw, $tenantTimezone);
    }

    /**
     * Solo 10 o 13 digitos cuentan como epoch. Sin ese limite, "20260301"
     * (una fecha compacta) se leeria como un timestamp de 1970.
     */
    private static function looksLikeUnixTimestamp(string $value): bool
    {
        $raw = trim($value);

        return preg_match('/^\d{10}$|^\d{13}$/', $raw) === 1;
    }

    private static function fromUnix(int $value): ?CarbonImmutable
    {
        // Milisegundos si tiene 13 digitos.
        $seconds = $value > 99999999999 ? intdiv($value, 1000) : $value;

        try {
            return CarbonImmutable::createFromTimestampUTC($seconds);
        } catch (Throwable) {
            return null;
        }
    }

    private static function fromOffsetAware(string $raw): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($raw)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private static function fromNaive(string $raw, string $tenantTimezone): ?CarbonImmutable
    {
        // La precision sub-segundo no aporta nada a un dato de venta y
        // multiplicaria los formatos a soportar. Se descarta antes de
        // intentar interpretar.
        $raw = preg_replace('/(\d{2}:\d{2}:\d{2})\.\d+$/', '$1', $raw) ?? $raw;

        foreach (self::NAIVE_FORMATS as $format) {
            try {
                // El prefijo '!' pone en cero todo lo que el formato no
                // especifica. Sin el, "2026-03-01" se completaria con la
                // hora ACTUAL, y la misma fecha daria un instante distinto
                // en cada ejecucion.
                $parsed = CarbonImmutable::createFromFormat('!'.$format, $raw, $tenantTimezone);
            } catch (Throwable) {
                continue;
            }

            // createFromFormat acepta de mas: "2026-13-45" no explota, se
            // desborda a otra fecha. Reformatear y comparar es lo que detecta
            // ese desborde.
            //
            // La comprobacion es `instanceof` y no `!== false`: Carbon 3 lanza
            // excepcion en vez de devolver false, asi que aquella guarda estaba
            // muerta, y si alguna vez devolviera null la linea siguiente seria
            // un error fatal. `instanceof` cubre los dos casos.
            if ($parsed instanceof CarbonImmutable && $parsed->format($format) === $raw) {
                return $parsed->utc();
            }
        }

        return null;
    }
}
