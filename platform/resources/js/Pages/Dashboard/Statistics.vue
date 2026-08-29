<script setup>
import { computed, ref, watch } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import BarList from '@/Components/BarList.vue';
import EmptyState from '@/Components/EmptyState.vue';
import LineChart from '@/Components/LineChart.vue';
import SelectInput from '@/Components/SelectInput.vue';
import StatCard from '@/Components/StatCard.vue';
import { formatMinor, formatMinorCompact, sumMinor } from '@/Support/money';
import {
    clampDate,
    formatBucket,
    formatCompactNumber,
    formatDate,
    formatMinutesAgo,
    formatNumber,
    formatPercent,
    shiftDays,
} from '@/Support/format';

const props = defineProps({
    tenant: { type: Object, required: true },
    range: { type: Object, required: true },
    bounds: { type: Object, required: true },
    series: { type: Array, default: () => [] },
    segments: { type: Array, default: () => [] },
    topProducts: { type: Array, default: () => [] },
    retention: { type: Object, default: () => ({}) },
    freshness: { type: Object, default: () => ({}) },
});

const currency = computed(() => props.tenant.currency);

const page = usePage();

// `by` no viaja en las props, así que lo leemos de la URL que Inertia ya mantiene
// sincronizada. Usar page.url en vez de window.location hace que el toggle siga
// reflejando el estado real después de un back/forward del navegador.
const currentQuery = computed(() => {
    const [, queryString = ''] = page.url.split('?');

    return Object.fromEntries(new URLSearchParams(queryString));
});

const by = computed(() => (currentQuery.value.by === 'category' ? 'category' : 'product'));

const from = ref(props.range.from);
const to = ref(props.range.to);
const granularity = ref(props.range.granularity);

// El backend puede devolver un rango distinto al pedido (lo recorta a bounds);
// re-sincronizamos para que los inputs muestren lo que realmente se está viendo.
watch(
    () => props.range,
    (range) => {
        from.value = range.from;
        to.value = range.to;
        granularity.value = range.granularity;
    },
);

function reload(overrides = {}) {
    router.get(
        route('dashboard.statistics'),
        {
            from: from.value,
            to: to.value,
            granularity: granularity.value,
            by: by.value,
            ...overrides,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function onFromChange() {
    from.value = clampDate(from.value, props.bounds.from, props.bounds.to);

    // Un rango invertido devolvería vacío y parecería "no hay datos": corregimos el otro extremo.
    if (from.value > to.value) {
        to.value = from.value;
    }

    reload();
}

function onToChange() {
    to.value = clampDate(to.value, props.bounds.from, props.bounds.to);

    if (to.value < from.value) {
        from.value = to.value;
    }

    reload();
}

const GRANULARITIES = [
    { value: 'day', label: 'Por día' },
    { value: 'week', label: 'Por semana' },
    { value: 'month', label: 'Por mes' },
];

// Atajos calculados sobre el fin del dataset y no sobre "hoy": en datos importados
// el último día con información casi nunca es el de hoy.
const presets = computed(() => [
    { key: '30d', label: '30 días', from: clampDate(shiftDays(props.bounds.to, -29), props.bounds.from, props.bounds.to), to: props.bounds.to },
    { key: '90d', label: '90 días', from: clampDate(shiftDays(props.bounds.to, -89), props.bounds.from, props.bounds.to), to: props.bounds.to },
    { key: 'all', label: 'Todo', from: props.bounds.from, to: props.bounds.to },
]);

function applyPreset(preset) {
    from.value = preset.from;
    to.value = preset.to;

    reload();
}

function isActivePreset(preset) {
    return preset.from === props.range.from && preset.to === props.range.to;
}

const totals = computed(() => {
    const orders = props.series.reduce((acc, row) => acc + (Number(row.orders_count) || 0), 0);

    return {
        orders,
        items: props.series.reduce((acc, row) => acc + (Number(row.items_count) || 0), 0),
        cancelled: props.series.reduce((acc, row) => acc + (Number(row.cancelled_count) || 0), 0),
        returned: props.series.reduce((acc, row) => acc + (Number(row.returned_count) || 0), 0),
        gross: sumMinor(props.series, 'gross_minor'),
        net: sumMinor(props.series, 'net_minor'),
        // Ticket promedio sobre el neto: si lo calculáramos sobre el bruto, cada cancelación
        // inflaría el ticket de un período que en realidad facturó menos.
        avgTicket: orders ? Math.round(sumMinor(props.series, 'net_minor') / orders) : 0,
    };
});

const hasData = computed(() => props.series.length > 0);

const chartLines = [
    { key: 'net_minor', label: 'Revenue neto', color: '#4f46e5', axis: 'left', area: true },
    { key: 'orders_count', label: 'Órdenes', color: '#0d9488', axis: 'right' },
];

// Los buckets ya vienen calculados en la zona horaria del tenant; volver a aplicarla
// acá los correría un día. Por eso el eje X no recibe timeZone.
const formatBucketLabel = (value) => formatBucket(value, granularity.value);

const chartValueFormat = (value, line) =>
    line.key.endsWith('_minor') ? formatMinor(value, currency.value) : formatNumber(value);

const segmentItems = computed(() =>
    [...props.segments]
        .sort((a, b) => (Number(b.revenue_minor) || 0) - (Number(a.revenue_minor) || 0))
        .map((segment) => ({
            key: segment.segment,
            label: segment.label ?? segment.segment,
            value: Number(segment.revenue_minor) || 0,
            formatted: formatMinor(segment.revenue_minor, currency.value),
            meta: `${formatNumber(segment.customers)} clientes`,
            hint: `${segment.label ?? segment.segment} · ${formatNumber(segment.customers)} clientes · ticket prom. ${formatMinor(segment.avg_ticket_minor, currency.value)}`,
        })),
);

const topItems = computed(() =>
    props.topProducts.map((row, index) => ({
        key: row.product_id ?? `${row.label}-${index}`,
        label: row.label,
        value: Number(row.revenue_minor) || 0,
        formatted: formatMinor(row.revenue_minor, currency.value),
        meta: `${formatNumber(row.units)} u.`,
        hint: `${row.label} · ${formatNumber(row.units)} unidades`,
    })),
);

function setBy(value) {
    if (value !== by.value) {
        reload({ by: value });
    }
}

const staleMessage = computed(() => {
    const waiting = formatMinutesAgo(props.freshness?.oldest_pending_minutes);

    return waiting ? `el más antiguo espera hace ${waiting}` : 'sin marca de tiempo del pendiente más antiguo';
});

const fieldClass =
    'rounded-md border-gray-300 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200';
const cardClass =
    'rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800';
</script>

<template>
    <Head title="Estadísticas" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                    Estadísticas
                </h2>

                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ tenant.name }} · reporta en {{ tenant.currency }} ·
                    {{ tenant.timezone }}
                </p>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                <!--
                    Frescura: un dashboard que no dice cuán viejo es su dato invita a decidir
                    sobre números que ya cambiaron. Va arriba, pero en tono informativo:
                    el pipeline atrasado es normal, no una falla.
                -->
                <div
                    v-if="freshness?.is_stale"
                    class="flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200"
                >
                    <svg
                        class="mt-0.5 h-4 w-4 shrink-0"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.8"
                        aria-hidden="true"
                    >
                        <circle cx="12" cy="12" r="9" />
                        <path stroke-linecap="round" d="M12 7.5v5l3 1.8" />
                    </svg>

                    <p>
                        Hay
                        <strong class="font-semibold">{{ formatNumber(freshness.pending_days) }}</strong>
                        {{ freshness.pending_days === 1 ? 'día' : 'días' }} pendientes de recálculo
                        ({{ staleMessage }}). Los números de abajo pueden no incluir la actividad
                        más reciente.
                    </p>
                </div>

                <!-- Filtros -->
                <div :class="cardClass" class="p-4">
                    <div class="flex flex-wrap items-end gap-4">
                        <div>
                            <label
                                for="stats-from"
                                class="block text-xs font-medium text-gray-500 dark:text-gray-400"
                            >
                                Desde
                            </label>
                            <input
                                id="stats-from"
                                v-model="from"
                                type="date"
                                :min="bounds.from"
                                :max="bounds.to"
                                :class="fieldClass"
                                class="mt-1 block"
                                @change="onFromChange"
                            />
                        </div>

                        <div>
                            <label
                                for="stats-to"
                                class="block text-xs font-medium text-gray-500 dark:text-gray-400"
                            >
                                Hasta
                            </label>
                            <input
                                id="stats-to"
                                v-model="to"
                                type="date"
                                :min="bounds.from"
                                :max="bounds.to"
                                :class="fieldClass"
                                class="mt-1 block"
                                @change="onToChange"
                            />
                        </div>

                        <div>
                            <label
                                for="stats-granularity"
                                class="block text-xs font-medium text-gray-500 dark:text-gray-400"
                            >
                                Granularidad
                            </label>
                            <!--
                                Sin v-model: así el reload ocurre con el valor nuevo garantizado,
                                sin depender del orden en que Vue mezcla los listeners.
                            -->
                            <SelectInput
                                id="stats-granularity"
                                class="mt-1 block"
                                :model-value="granularity"
                                :options="GRANULARITIES"
                                @update:model-value="
                                    (value) => {
                                        granularity = value;
                                        reload();
                                    }
                                "
                            />
                        </div>

                        <div class="flex items-center gap-1 pb-0.5">
                            <button
                                v-for="preset in presets"
                                :key="preset.key"
                                type="button"
                                class="rounded-md border px-2.5 py-1.5 text-xs font-medium transition"
                                :class="
                                    isActivePreset(preset)
                                        ? 'border-indigo-500 bg-indigo-50 text-indigo-700 dark:border-indigo-400 dark:bg-indigo-500/10 dark:text-indigo-300'
                                        : 'border-gray-300 text-gray-600 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700'
                                "
                                @click="applyPreset(preset)"
                            >
                                {{ preset.label }}
                            </button>
                        </div>

                        <p class="ml-auto pb-1 text-xs text-gray-500 dark:text-gray-400">
                            Dataset disponible: {{ formatDate(bounds.from) }} –
                            {{ formatDate(bounds.to) }}
                        </p>
                    </div>
                </div>

                <template v-if="hasData">
                    <!-- KPIs -->
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <StatCard
                            label="Revenue neto"
                            :value="formatMinor(totals.net, currency)"
                            :note="`Bruto ${formatMinor(totals.gross, currency)} · descuenta cancelaciones y devoluciones`"
                        />

                        <StatCard
                            label="Órdenes"
                            :value="formatNumber(totals.orders)"
                            :note="`${formatNumber(totals.cancelled)} canceladas · ${formatNumber(totals.returned)} devueltas · ${formatNumber(totals.items)} ítems`"
                        />

                        <StatCard
                            label="Ticket promedio"
                            :value="formatMinor(totals.avgTicket, currency)"
                            note="Revenue neto ÷ órdenes del período"
                        />

                        <StatCard
                            label="Tasa de recompra"
                            :value="formatPercent(retention.repeat_rate)"
                            :note="`${formatNumber(retention.repeat_buyers)} de ${formatNumber(retention.buyers)} compradores volvieron`"
                        />
                    </div>

                    <!-- Serie temporal -->
                    <div :class="cardClass" class="p-4">
                        <div class="mb-1 flex flex-wrap items-baseline justify-between gap-2">
                            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                Revenue y órdenes en el tiempo
                            </h3>

                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                {{ formatNumber(series.length) }} puntos ·
                                {{ GRANULARITIES.find((g) => g.value === range.granularity)?.label }}
                            </p>
                        </div>

                        <LineChart
                            :series="series"
                            x-key="bucket"
                            :lines="chartLines"
                            :height="320"
                            :format-left="(value) => formatMinorCompact(value, currency)"
                            :format-right="(value) => formatCompactNumber(value)"
                            :format-x="formatBucketLabel"
                            :format-value="chartValueFormat"
                        />
                    </div>

                    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                        <!-- Segmentación RFM -->
                        <div :class="cardClass" class="p-4">
                            <div class="flex items-baseline justify-between gap-2">
                                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                    Segmentación RFM
                                </h3>
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    Ordenado por revenue
                                </p>
                            </div>

                            <BarList
                                v-if="segmentItems.length"
                                class="mt-2"
                                :items="segmentItems"
                                color="#4f46e5"
                                meta-width="7rem"
                            />

                            <EmptyState
                                v-else
                                class="mt-4"
                                title="Sin segmentos"
                                description="Todavía no hay clientes clasificados en este rango."
                            />
                        </div>

                        <!-- Top productos / categorías -->
                        <div :class="cardClass" class="p-4">
                            <div class="flex items-baseline justify-between gap-2">
                                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                    Top {{ by === 'category' ? 'categorías' : 'productos' }}
                                </h3>

                                <div
                                    class="inline-flex overflow-hidden rounded-md border border-gray-300 text-xs dark:border-gray-600"
                                >
                                    <button
                                        v-for="option in [
                                            { value: 'product', label: 'Producto' },
                                            { value: 'category', label: 'Categoría' },
                                        ]"
                                        :key="option.value"
                                        type="button"
                                        class="px-2.5 py-1 font-medium transition"
                                        :class="
                                            by === option.value
                                                ? 'bg-indigo-600 text-white'
                                                : 'text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-700'
                                        "
                                        @click="setBy(option.value)"
                                    >
                                        {{ option.label }}
                                    </button>
                                </div>
                            </div>

                            <BarList
                                v-if="topItems.length"
                                class="mt-2"
                                :items="topItems"
                                color="#0d9488"
                                meta-width="5.5rem"
                            />

                            <EmptyState
                                v-else
                                class="mt-4"
                                title="Sin ventas en el rango"
                                description="Ningún producto registró unidades en las fechas seleccionadas."
                            />
                        </div>
                    </div>

                    <!-- Retención -->
                    <div :class="cardClass" class="p-4">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                            Retención
                        </h3>

                        <dl
                            class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-3 sm:divide-x sm:divide-gray-100 dark:sm:divide-gray-700"
                        >
                            <div class="sm:pr-4">
                                <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    Tasa de recompra
                                </dt>
                                <dd class="mt-1 text-xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">
                                    {{ formatPercent(retention.repeat_rate) }}
                                </dd>
                                <dd class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ formatNumber(retention.repeat_buyers) }} de
                                    {{ formatNumber(retention.buyers) }} compradores
                                </dd>
                            </div>

                            <div class="sm:px-4">
                                <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    Revenue de clientes que vuelven
                                </dt>
                                <dd class="mt-1 text-xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">
                                    {{ formatPercent(retention.repeat_revenue_share) }}
                                </dd>
                                <dd class="text-xs text-gray-500 dark:text-gray-400">
                                    Porción del revenue del período
                                </dd>
                            </div>

                            <div class="sm:pl-4">
                                <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    Órdenes por comprador
                                </dt>
                                <dd class="mt-1 text-xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">
                                    {{ formatNumber(retention.avg_orders_per_buyer, { maximumFractionDigits: 2 }) }}
                                </dd>
                                <dd class="text-xs text-gray-500 dark:text-gray-400">
                                    Promedio en el rango seleccionado
                                </dd>
                            </div>
                        </dl>
                    </div>
                </template>

                <div v-else :class="cardClass" class="p-6">
                    <EmptyState
                        title="No hay actividad en este rango"
                        :description="`Entre ${formatDate(range.from)} y ${formatDate(range.to)} no se registraron órdenes. Probá ampliar el rango o cambiar la granularidad.`"
                    >
                        <button
                            type="button"
                            class="rounded-md border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700"
                            @click="applyPreset(presets[2])"
                        >
                            Ver todo el dataset
                        </button>
                    </EmptyState>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
