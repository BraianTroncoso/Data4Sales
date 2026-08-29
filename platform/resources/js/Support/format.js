/**
 * Formateo de fechas, números y porcentajes.
 *
 * Todo lo que llega del backend es ISO 8601 y todo lo que se muestra pasa por acá,
 * para que un cambio de criterio (zona horaria del tenant, dígitos significativos)
 * se haga en un solo lugar y no en catorce templates.
 */

const dateTimeCache = new Map();

function dateTimeFormatter(locale, options) {
    const key = `${locale ?? ''}|${JSON.stringify(options)}`;

    if (!dateTimeCache.has(key)) {
        dateTimeCache.set(key, new Intl.DateTimeFormat(locale, options));
    }

    return dateTimeCache.get(key);
}

function parse(value) {
    if (!value) {
        return null;
    }

    // Una fecha "YYYY-MM-DD" sin hora la interpreta el motor como UTC medianoche y,
    // mostrada en un huso negativo, retrocede un día. Le agregamos hora local para
    // que un bucket diario se muestre siempre en su propio día.
    const raw = /^\d{4}-\d{2}-\d{2}$/.test(value) ? `${value}T00:00:00` : value;
    const date = new Date(raw);

    return Number.isNaN(date.getTime()) ? null : date;
}

/** Fecha corta: 14 mar 2025. */
export function formatDate(value, { locale, timeZone } = {}) {
    const date = parse(value);

    if (!date) {
        return '—';
    }

    return dateTimeFormatter(locale, {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        ...(timeZone ? { timeZone } : {}),
    }).format(date);
}

/** Fecha y hora, para timestamps de eventos donde la hora es parte del dato. */
export function formatDateTime(value, { locale, timeZone } = {}) {
    const date = parse(value);

    if (!date) {
        return '—';
    }

    return dateTimeFormatter(locale, {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        ...(timeZone ? { timeZone } : {}),
    }).format(date);
}

/** Etiqueta de eje según granularidad: en meses no queremos repetir el día 01. */
export function formatBucket(value, granularity, { locale, timeZone } = {}) {
    const date = parse(value);

    if (!date) {
        return '—';
    }

    const options =
        granularity === 'month'
            ? { month: 'short', year: '2-digit' }
            : { day: '2-digit', month: 'short' };

    return dateTimeFormatter(locale, { ...options, ...(timeZone ? { timeZone } : {}) }).format(date);
}

export function formatNumber(value, { locale, maximumFractionDigits = 0 } = {}) {
    // Igual que con el dinero: la ausencia de dato se muestra como tal y no como cero.
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    const number = Number(value);

    if (!Number.isFinite(number)) {
        return '—';
    }

    return new Intl.NumberFormat(locale, { maximumFractionDigits }).format(number);
}

export function formatCompactNumber(value, { locale } = {}) {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    const number = Number(value);

    if (!Number.isFinite(number)) {
        return '—';
    }

    return new Intl.NumberFormat(locale, {
        notation: 'compact',
        maximumFractionDigits: 1,
    }).format(number);
}

/**
 * Los ratios pueden llegar como fracción (0.42) o ya en escala porcentual (42).
 * Normalizamos en vez de asumir: mostrar "4200 %" por una diferencia de convención
 * es el tipo de error que nadie reporta y todos descuentan mentalmente.
 */
export function asRate(value) {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    const number = Number(value);

    if (!Number.isFinite(number)) {
        return null;
    }

    return number > 1 ? number / 100 : number;
}

export function formatPercent(value, { locale, maximumFractionDigits = 1 } = {}) {
    const rate = asRate(value);

    if (rate === null) {
        return '—';
    }

    return new Intl.NumberFormat(locale, {
        style: 'percent',
        maximumFractionDigits,
    }).format(rate);
}

/** "hace 2 h 15 min" para la antigüedad del recálculo pendiente. */
export function formatMinutesAgo(minutes) {
    if (minutes === null || minutes === undefined || minutes === '') {
        return null;
    }

    const total = Number(minutes);

    if (!Number.isFinite(total) || total < 0) {
        return null;
    }

    if (total < 60) {
        return `${Math.round(total)} min`;
    }

    const hours = Math.floor(total / 60);

    if (hours < 24) {
        const rest = Math.round(total % 60);

        return rest ? `${hours} h ${rest} min` : `${hours} h`;
    }

    const days = Math.floor(hours / 24);

    return `${days} d ${hours % 24} h`;
}

/** Aritmética sobre 'YYYY-MM-DD' en UTC, para no arrastrar el huso del navegador a los presets. */
export function shiftDays(isoDate, days) {
    const date = new Date(`${isoDate}T00:00:00Z`);

    if (Number.isNaN(date.getTime())) {
        return isoDate;
    }

    date.setUTCDate(date.getUTCDate() + days);

    return date.toISOString().slice(0, 10);
}

export function clampDate(isoDate, min, max) {
    if (min && isoDate < min) {
        return min;
    }

    if (max && isoDate > max) {
        return max;
    }

    return isoDate;
}
