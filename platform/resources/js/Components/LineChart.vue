<script setup>
import { computed, ref } from 'vue';

/**
 * Gráfico de líneas/áreas en SVG puro.
 *
 * Sin librería: la app no suma dependencias y lo que necesitamos —dos ejes, grilla y
 * tooltip— cabe en un par de escalas lineales. El SVG usa un viewBox fijo y escala al
 * ancho del contenedor, así que las coordenadas internas son estables y el hit-testing
 * del mouse es una sola regla de tres contra el ancho medido.
 */

const props = defineProps({
    series: { type: Array, default: () => [] },
    xKey: { type: String, required: true },
    // [{ key, label, color, axis: 'left'|'right', area: Boolean }]
    lines: { type: Array, required: true },
    height: { type: Number, default: 320 },
    formatLeft: { type: Function, default: (value) => String(value) },
    formatRight: { type: Function, default: (value) => String(value) },
    formatX: { type: Function, default: (value) => String(value) },
    // Formato por línea en el tooltip; cae al del eje si no se pasa.
    formatValue: { type: Function, default: null },
});

const VIEW_W = 960;
const MARGIN = { top: 14, right: 58, bottom: 26, left: 66 };

const innerW = VIEW_W - MARGIN.left - MARGIN.right;
const innerH = computed(() => props.height - MARGIN.top - MARGIN.bottom);

const count = computed(() => props.series.length);

const leftLines = computed(() => props.lines.filter((line) => line.axis !== 'right'));
const rightLines = computed(() => props.lines.filter((line) => line.axis === 'right'));

function xAt(index) {
    if (count.value <= 1) {
        return MARGIN.left + innerW / 2;
    }

    return MARGIN.left + (index * innerW) / (count.value - 1);
}

/** Redondea el dominio a múltiplos "redondos" para que los ticks sean leíbles (0, 25k, 50k…). */
function niceDomain(min, max, tickCount = 4) {
    let lo = Math.min(0, min);
    let hi = max;

    if (hi <= lo) {
        hi = lo + 1;
    }

    const rawStep = (hi - lo) / tickCount;
    const magnitude = 10 ** Math.floor(Math.log10(rawStep));
    const normalized = rawStep / magnitude;
    const step = (normalized <= 1 ? 1 : normalized <= 2 ? 2 : normalized <= 2.5 ? 2.5 : normalized <= 5 ? 5 : 10) * magnitude;

    lo = Math.floor(lo / step) * step;
    hi = Math.ceil(hi / step) * step;

    const ticks = [];

    // El epsilon evita que el último tick se pierda por error de coma flotante acumulado.
    for (let value = lo; value <= hi + step / 1000; value += step) {
        ticks.push(value);
    }

    return { min: lo, max: hi, ticks };
}

function domainFor(lines) {
    if (!lines.length || !count.value) {
        return niceDomain(0, 1);
    }

    let min = 0;
    let max = 0;

    props.series.forEach((row) => {
        lines.forEach((line) => {
            const value = Number(row[line.key]) || 0;
            min = Math.min(min, value);
            max = Math.max(max, value);
        });
    });

    return niceDomain(min, max);
}

const leftDomain = computed(() => domainFor(leftLines.value));
const rightDomain = computed(() => domainFor(rightLines.value));

function yAt(value, domain) {
    const span = domain.max - domain.min || 1;
    const ratio = ((Number(value) || 0) - domain.min) / span;

    return MARGIN.top + innerH.value * (1 - ratio);
}

function domainOf(line) {
    return line.axis === 'right' ? rightDomain.value : leftDomain.value;
}

function linePath(line) {
    const domain = domainOf(line);

    return props.series
        .map((row, index) => `${index === 0 ? 'M' : 'L'}${xAt(index).toFixed(2)},${yAt(row[line.key], domain).toFixed(2)}`)
        .join(' ');
}

function areaPath(line) {
    if (!count.value) {
        return '';
    }

    const domain = domainOf(line);
    const baseline = yAt(Math.max(domain.min, 0), domain).toFixed(2);

    return `${linePath(line)} L${xAt(count.value - 1).toFixed(2)},${baseline} L${xAt(0).toFixed(2)},${baseline} Z`;
}

// Con 120 puntos no entran 120 etiquetas: mostramos ~7 y el resto vive en el tooltip.
const xTicks = computed(() => {
    if (!count.value) {
        return [];
    }

    const step = Math.max(1, Math.ceil(count.value / 7));

    return props.series
        .map((row, index) => ({ index, label: props.formatX(row[props.xKey]) }))
        .filter((tick) => tick.index % step === 0);
});

// Los puntos sólo se dibujan cuando hay pocos: con series densas se convierten en una mancha.
const showDots = computed(() => count.value > 0 && count.value <= 30);

const svgRef = ref(null);
const hoverIndex = ref(null);

function onPointerMove(event) {
    if (!count.value || !svgRef.value) {
        return;
    }

    const rect = svgRef.value.getBoundingClientRect();

    if (!rect.width) {
        return;
    }

    if (count.value === 1) {
        hoverIndex.value = 0;

        return;
    }

    // El SVG escala uniformemente, así que basta con el factor de ancho para volver al viewBox.
    const scale = rect.width / VIEW_W;
    const x = (event.clientX - rect.left) / scale;
    const index = Math.round(((x - MARGIN.left) / innerW) * (count.value - 1));

    hoverIndex.value = Math.min(count.value - 1, Math.max(0, index));
}

function onPointerLeave() {
    hoverIndex.value = null;
}

const hoverRow = computed(() =>
    hoverIndex.value === null ? null : props.series[hoverIndex.value] ?? null,
);

const tooltipStyle = computed(() => {
    if (hoverIndex.value === null) {
        return {};
    }

    const ratio = xAt(hoverIndex.value) / VIEW_W;

    return {
        left: `${ratio * 100}%`,
        // Después de la mitad el tooltip se voltea para no salirse del panel.
        transform: ratio > 0.5 ? 'translateX(calc(-100% - 10px))' : 'translateX(10px)',
    };
});

function formatFor(line, value) {
    if (props.formatValue) {
        return props.formatValue(value, line);
    }

    return line.axis === 'right' ? props.formatRight(value) : props.formatLeft(value);
}

// Ids únicos por instancia: dos gráficos en la misma página no pueden compartir el gradiente.
let sequence = 0;
const uid = `chart-${(sequence += 1)}-${Math.random().toString(36).slice(2, 8)}`;
</script>

<template>
    <div class="relative">
        <div class="mb-2 flex flex-wrap items-center gap-x-4 gap-y-1">
            <span
                v-for="line in lines"
                :key="line.key"
                class="inline-flex items-center gap-1.5 text-xs text-ink-muted"
            >
                <span class="h-0.5 w-4 rounded" :style="{ backgroundColor: line.color }" />
                {{ line.label }}
                <span class="text-ink-muted">
                    ({{ line.axis === 'right' ? 'eje der.' : 'eje izq.' }})
                </span>
            </span>
        </div>

        <svg
            ref="svgRef"
            :viewBox="`0 0 ${VIEW_W} ${height}`"
            class="w-full select-none"
            :style="{ height: 'auto' }"
            role="img"
            aria-label="Serie temporal"
            @mousemove="onPointerMove"
            @mouseleave="onPointerLeave"
        >
            <defs>
                <linearGradient
                    v-for="line in lines.filter((l) => l.area)"
                    :id="`${uid}-${line.key}`"
                    :key="line.key"
                    x1="0"
                    y1="0"
                    x2="0"
                    y2="1"
                >
                    <stop offset="0%" :stop-color="line.color" stop-opacity="0.28" />
                    <stop offset="100%" :stop-color="line.color" stop-opacity="0.02" />
                </linearGradient>
            </defs>

            <!-- Grilla horizontal tomada del eje izquierdo: dos grillas superpuestas serían ruido. -->
            <g class="text-line">
                <line
                    v-for="tick in leftDomain.ticks"
                    :key="`grid-${tick}`"
                    :x1="MARGIN.left"
                    :x2="VIEW_W - MARGIN.right"
                    :y1="yAt(tick, leftDomain)"
                    :y2="yAt(tick, leftDomain)"
                    stroke="currentColor"
                    stroke-width="1"
                />
            </g>

            <g class="fill-current text-ink-muted" font-size="11">
                <text
                    v-for="tick in leftDomain.ticks"
                    :key="`left-${tick}`"
                    :x="MARGIN.left - 8"
                    :y="yAt(tick, leftDomain) + 3.5"
                    text-anchor="end"
                >
                    {{ formatLeft(tick) }}
                </text>

                <text
                    v-for="tick in rightDomain.ticks"
                    :key="`right-${tick}`"
                    :x="VIEW_W - MARGIN.right + 8"
                    :y="yAt(tick, rightDomain) + 3.5"
                    text-anchor="start"
                >
                    {{ formatRight(tick) }}
                </text>

                <text
                    v-for="tick in xTicks"
                    :key="`x-${tick.index}`"
                    :x="xAt(tick.index)"
                    :y="height - 8"
                    text-anchor="middle"
                >
                    {{ tick.label }}
                </text>
            </g>

            <g v-if="count">
                <path
                    v-for="line in lines.filter((l) => l.area)"
                    :key="`area-${line.key}`"
                    :d="areaPath(line)"
                    :fill="`url(#${uid}-${line.key})`"
                    stroke="none"
                />

                <path
                    v-for="line in lines"
                    :key="`line-${line.key}`"
                    :d="linePath(line)"
                    fill="none"
                    :stroke="line.color"
                    stroke-width="1.75"
                    stroke-linejoin="round"
                    stroke-linecap="round"
                />

                <template v-if="showDots">
                    <template v-for="line in lines" :key="`dotset-${line.key}`">
                        <circle
                            v-for="(row, index) in series"
                            :key="`dot-${line.key}-${index}`"
                            :cx="xAt(index)"
                            :cy="yAt(row[line.key], domainOf(line))"
                            r="2"
                            :fill="line.color"
                        />
                    </template>
                </template>
            </g>

            <g v-if="hoverIndex !== null && count">
                <line
                    :x1="xAt(hoverIndex)"
                    :x2="xAt(hoverIndex)"
                    :y1="MARGIN.top"
                    :y2="MARGIN.top + innerH"
                    class="text-ink-faint"
                    stroke="currentColor"
                    stroke-width="1"
                    stroke-dasharray="3 3"
                />

                <circle
                    v-for="line in lines"
                    :key="`hover-${line.key}`"
                    :cx="xAt(hoverIndex)"
                    :cy="yAt(series[hoverIndex][line.key], domainOf(line))"
                    r="3.5"
                    :fill="line.color"
                    class="stroke-surface"
                    stroke-width="1.5"
                />
            </g>

            <line
                :x1="MARGIN.left"
                :x2="VIEW_W - MARGIN.right"
                :y1="MARGIN.top + innerH"
                :y2="MARGIN.top + innerH"
                class="text-line-strong"
                stroke="currentColor"
                stroke-width="1"
            />
        </svg>

        <div
            v-if="hoverRow"
            class="pointer-events-none absolute top-8 z-10 min-w-[10rem] rounded-chip border border-line bg-surface p-2 text-xs shadow-pop"
            :style="tooltipStyle"
        >
            <p class="font-medium text-ink">
                {{ formatX(hoverRow[xKey]) }}
            </p>

            <p
                v-for="line in lines"
                :key="`tip-${line.key}`"
                class="mt-1 flex items-center justify-between gap-3 text-ink-muted"
            >
                <span class="inline-flex items-center gap-1.5">
                    <span class="h-1.5 w-1.5 rounded-full" :style="{ backgroundColor: line.color }" />
                    {{ line.label }}
                </span>
                <span class="font-medium tabular-nums text-ink">
                    {{ formatFor(line, hoverRow[line.key]) }}
                </span>
            </p>
        </div>
    </div>
</template>
