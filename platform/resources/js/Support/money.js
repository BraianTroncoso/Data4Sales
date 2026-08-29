/**
 * Formateo de dinero.
 *
 * El backend manda enteros en unidad mínima (centavos) y el código de moneda al lado.
 * Lo hace porque un float no sobrevive a una suma de miles de órdenes sin perder
 * centavos, y porque "1050" es inequívoco mientras que "10.50" depende del locale
 * de quien lo escribió. La contrapartida es que el único que sabe dónde va la coma
 * es este archivo: dividir siempre por 100 mostraría CLP 1.000 (mil pesos chilenos)
 * como "1.000,00" y, peor, "$10,00". Por eso la tabla de exponentes.
 */

// ISO 4217. Sólo listamos las monedas que no usan el exponente 2 más las del dataset,
// para no arrastrar una tabla de 180 entradas que nadie mantiene.
const MINOR_UNIT_EXPONENT = {
    ARS: 2,
    BRL: 2,
    CLP: 0,
    COP: 2,
    EUR: 2,
    GBP: 2,
    JPY: 0,
    KRW: 0,
    MXN: 2,
    PEN: 2,
    PYG: 0,
    USD: 2,
    UYU: 2,
    VND: 0,
};

const DEFAULT_EXPONENT = 2;

/** Exponente de la unidad mínima. Ante una moneda desconocida asumimos 2, que es el caso mayoritario. */
export function minorExponent(currency) {
    if (!currency) {
        return DEFAULT_EXPONENT;
    }

    return MINOR_UNIT_EXPONENT[String(currency).toUpperCase()] ?? DEFAULT_EXPONENT;
}

/** Pasa de unidad mínima a unidad mayor (1050 USD -> 10.5). */
export function toMajor(amountMinor, currency) {
    // null/undefined no son cero: un cliente sin órdenes no facturó "0,00", no tiene dato.
    // Number(null) daría 0 y mostraríamos un monto que nadie calculó.
    if (amountMinor === null || amountMinor === undefined || amountMinor === '') {
        return null;
    }

    const value = Number(amountMinor);

    if (!Number.isFinite(value)) {
        return null;
    }

    // Una sola división: encadenar operaciones sobre el valor ya dividido es
    // justamente lo que queremos evitar al trabajar en unidad mínima.
    return value / 10 ** minorExponent(currency);
}

// Construir un Intl.NumberFormat es caro y en una tabla de 100 filas se nota,
// así que memorizamos por combinación locale+moneda+opciones.
const formatterCache = new Map();

function currencyFormatter(locale, currency, extra = {}, cacheKey = '') {
    const key = `${locale ?? ''}|${currency}|${cacheKey}`;

    if (!formatterCache.has(key)) {
        const exponent = minorExponent(currency);

        formatterCache.set(
            key,
            new Intl.NumberFormat(locale, {
                style: 'currency',
                currency,
                minimumFractionDigits: exponent,
                maximumFractionDigits: exponent,
                ...extra,
            }),
        );
    }

    return formatterCache.get(key);
}

/**
 * Formatea un monto en unidad mínima.
 * `locale` indefinido delega en el del navegador: el tenant define la moneda,
 * no cómo el usuario que mira la pantalla escribe los números.
 */
export function formatMinor(amountMinor, currency, locale = undefined) {
    const major = toMajor(amountMinor, currency);

    if (major === null) {
        return '—';
    }

    try {
        return currencyFormatter(locale, currency).format(major);
    } catch {
        // Moneda no reconocida por Intl: mostramos el número y el código igual,
        // porque un dashboard sin el monto es peor que uno con el monto feo.
        const exponent = minorExponent(currency);

        return `${major.toFixed(exponent)} ${currency ?? ''}`.trim();
    }
}

/** Versión compacta (1,2 M) para ejes y etiquetas donde no entra el monto completo. */
export function formatMinorCompact(amountMinor, currency, locale = undefined) {
    const major = toMajor(amountMinor, currency);

    if (major === null) {
        return '—';
    }

    try {
        return currencyFormatter(
            locale,
            currency,
            { notation: 'compact', minimumFractionDigits: 0, maximumFractionDigits: 1 },
            'compact',
        ).format(major);
    } catch {
        return `${Math.round(major)} ${currency ?? ''}`.trim();
    }
}

/** Suma segura de montos en unidad mínima (enteros, sin pasar por float intermedio). */
export function sumMinor(rows, key) {
    return rows.reduce((total, row) => total + (Number(row?.[key]) || 0), 0);
}
