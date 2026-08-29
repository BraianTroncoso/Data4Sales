<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Support;

/**
 * Monedas conocidas y cuantos decimales tiene cada una.
 *
 * El exponente no es un detalle: el peso chileno y el yen no tienen
 * centavos, asi que "1000 CLP" son 1000 unidades minimas, no 100.000.
 * Asumir dos decimales para todo es el bug clasico que multiplica por cien
 * el revenue de un pais entero y nadie lo nota hasta el cierre de mes.
 *
 * La lista es deliberadamente cerrada. Aceptar cualquier codigo de tres
 * letras significaria aceptar "XXX" y guardarlo como si fuera dinero.
 */
final class CurrencyRegistry
{
    /**
     * Codigo ISO-4217 => cantidad de decimales.
     *
     * @var array<string, int>
     */
    private const CURRENCIES = [
        'ARS' => 2,
        'BRL' => 2,
        'CLP' => 0,
        'COP' => 2,
        'EUR' => 2,
        'GBP' => 2,
        'JPY' => 0,
        'MXN' => 2,
        'PEN' => 2,
        'USD' => 2,
        'UYU' => 2,
    ];

    public static function isKnown(string $code): bool
    {
        return isset(self::CURRENCIES[self::normalize($code)]);
    }

    /**
     * Cuantas unidades minimas entran en una unidad de esta moneda.
     * ARS => 100, CLP => 1.
     */
    public static function minorUnitFactor(string $code): int
    {
        return 10 ** self::decimals($code);
    }

    public static function decimals(string $code): int
    {
        return self::CURRENCIES[self::normalize($code)]
            ?? throw new \InvalidArgumentException("Moneda desconocida: {$code}");
    }

    public static function normalize(string $code): string
    {
        return strtoupper(trim($code));
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_keys(self::CURRENCIES);
    }
}
