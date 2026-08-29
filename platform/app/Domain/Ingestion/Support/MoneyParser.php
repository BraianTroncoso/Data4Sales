<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Support;

/**
 * Convierte un monto que llego de cualquier forma en un entero de unidades
 * minimas.
 *
 * El dinero se guarda siempre como entero (ver D-07). Este parser es el
 * unico lugar donde un valor externo se convierte, y por eso concentra toda
 * la tolerancia: los retailers mandan numeros, cadenas con simbolo de
 * moneda, separadores de miles, y las dos convenciones decimales del mundo.
 *
 * El caso ambiguo real es "1.234". Puede ser mil doscientos treinta y cuatro
 * (convencion latina) o uno coma doscientos treinta y cuatro (convencion
 * anglosajona). La regla que se aplica, y que hay que conocer para leer los
 * numeros con criterio:
 *
 *   - Si aparecen los dos separadores, el ULTIMO es el decimal. Esto no
 *     falla nunca: "1.234,56" y "1,234.56" se resuelven solos.
 *   - Si aparece uno solo y lo siguen exactamente tres digitos, se asume
 *     separador de miles. "1.234" es mil doscientos treinta y cuatro.
 *   - Si aparece uno solo y no lo siguen tres digitos, es decimal.
 *     "1.5" es uno con cincuenta.
 *
 * La segunda regla es una apuesta, y puede equivocarse con un monto
 * genuino de "1.234" unidades con tres decimales. Se acepta porque las
 * monedas que manejamos tienen dos decimales o ninguno, nunca tres.
 */
final class MoneyParser
{
    /**
     * Devuelve el monto en unidades minimas, o null si no se puede
     * interpretar como numero.
     *
     * Un negativo se devuelve como negativo: quien llama decide si eso es
     * valido. Un reembolso puede serlo; el total de una orden no.
     */
    public static function toMinorUnits(mixed $value, string $currency): ?int
    {
        $amount = self::toDecimalString($value);

        if ($amount === null) {
            return null;
        }

        $factor = CurrencyRegistry::minorUnitFactor($currency);

        // Se escala como cadena y se redondea al final, en vez de multiplicar
        // el float: 19.99 * 100 da 1998.9999... en coma flotante, y truncar
        // eso pierde un centavo por operacion.
        return (int) round((float) $amount * $factor);
    }

    /**
     * Normaliza cualquier entrada a una cadena decimal con punto.
     */
    private static function toDecimalString(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return is_finite($value) ? sprintf('%.10F', $value) : null;
        }

        if (! is_string($value)) {
            return null;
        }

        $raw = trim($value);

        if ($raw === '') {
            return null;
        }

        // Simbolos de moneda, espacios internos y espacios finos que mandan
        // algunos exportadores de planillas.
        $raw = preg_replace('/[^\d,.\-+]/u', '', $raw) ?? '';

        if ($raw === '' || ! preg_match('/\d/', $raw)) {
            return null;
        }

        $negative = str_starts_with($raw, '-');
        $digits = ltrim($raw, '+-');

        $normalized = self::resolveSeparators($digits);

        if ($normalized === null) {
            return null;
        }

        return ($negative ? '-' : '').$normalized;
    }

    private static function resolveSeparators(string $digits): ?string
    {
        $lastDot = strrpos($digits, '.');
        $lastComma = strrpos($digits, ',');

        // Ambos separadores presentes: el ultimo manda.
        if ($lastDot !== false && $lastComma !== false) {
            $decimalPos = max($lastDot, $lastComma);

            $integer = preg_replace('/[.,]/', '', substr($digits, 0, $decimalPos)) ?? '';
            $fraction = substr($digits, $decimalPos + 1);

            return self::assemble($integer, $fraction);
        }

        $separator = $lastDot !== false ? '.' : ($lastComma !== false ? ',' : null);

        if ($separator === null) {
            return ctype_digit($digits) ? $digits : null;
        }

        $position = $separator === '.' ? $lastDot : $lastComma;
        $fraction = substr($digits, $position + 1);

        // Exactamente tres digitos detras y nada mas: separador de miles.
        if (strlen($fraction) === 3 && substr_count($digits, $separator) >= 1) {
            $withoutSeparator = str_replace($separator, '', $digits);

            if (ctype_digit($withoutSeparator)) {
                return $withoutSeparator;
            }
        }

        $integer = str_replace($separator, '', substr($digits, 0, $position));

        return self::assemble($integer, $fraction);
    }

    private static function assemble(string $integer, string $fraction): ?string
    {
        $integer = $integer === '' ? '0' : $integer;

        if (! ctype_digit($integer) || ($fraction !== '' && ! ctype_digit($fraction))) {
            return null;
        }

        return $fraction === '' ? $integer : "{$integer}.{$fraction}";
    }
}
