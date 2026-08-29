<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Support;

use Carbon\CarbonInterface;

/**
 * Tasa de cambio aplicable a una orden.
 *
 * SIMPLIFICACION CONSCIENTE: en produccion esto consultaria a un proveedor
 * de tasas historicas, con su propio manejo de dias sin cotizacion, feriados
 * y monedas sin mercado. Aca es una tabla fija.
 *
 * Lo que NO se simplifica, porque es lo que importa del diseno: la tasa se
 * resuelve con la fecha de la ORDEN y se guarda junto a ella. Convertir al
 * vuelo con la tasa de hoy haria que el revenue de marzo cambiara cada vez
 * que alguien abre el dashboard, y ningun reporte seria reproducible ni
 * auditable. Guardar la tasa usada es lo que permite que alguien reconstruya
 * el numero a mano y le de igual.
 */
final class ExchangeRateProvider
{
    /**
     * Unidades de cada moneda por un dolar.
     *
     * @var array<string, float>
     */
    private const PER_USD = [
        'USD' => 1.0,
        'ARS' => 1350.0,
        'BRL' => 5.40,
        'CLP' => 950.0,
        'COP' => 4100.0,
        'EUR' => 0.92,
        'GBP' => 0.79,
        'JPY' => 155.0,
        'MXN' => 18.50,
        'PEN' => 3.75,
        'UYU' => 40.0,
    ];

    /**
     * Cuantas unidades de $to equivalen a una de $from.
     *
     * La fecha se recibe aunque hoy no se use: mantenerla en la firma es lo
     * que hace que reemplazar esta clase por un proveedor real no toque a
     * ninguno de sus llamadores.
     */
    public function rate(string $from, string $to, CarbonInterface $on): float
    {
        $from = CurrencyRegistry::normalize($from);
        $to = CurrencyRegistry::normalize($to);

        if ($from === $to) {
            return 1.0;
        }

        return (self::PER_USD[$to] ?? 1.0) / (self::PER_USD[$from] ?? 1.0);
    }

    /**
     * Convierte un monto en unidades minimas de una moneda a unidades
     * minimas de otra.
     *
     * El paso por unidades enteras es necesario porque los exponentes
     * difieren: pasar de CLP (sin decimales) a USD (con dos) no es
     * multiplicar el entero por la tasa.
     */
    public function convertMinor(int $amountMinor, string $from, string $to, CarbonInterface $on): int
    {
        $rate = $this->rate($from, $to, $on);

        $wholeUnits = $amountMinor / CurrencyRegistry::minorUnitFactor($from);

        return (int) round($wholeUnits * $rate * CurrencyRegistry::minorUnitFactor($to));
    }
}
