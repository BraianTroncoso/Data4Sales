<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import EmailStatusDot from '@/Components/EmailStatusDot.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Money from '@/Components/Money.vue';
import StatCard from '@/Components/StatCard.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { formatDate, formatDateTime, formatNumber } from '@/Support/format';

const props = defineProps({
    tenant: { type: Object, required: true },
    customer: { type: Object, required: true },
    orders: { type: Array, default: () => [] },
    currency: { type: String, required: true },
});

const metrics = computed(() => props.customer.metrics ?? {});

const EMAIL_STATUS_LABEL = {
    valid: 'válido',
    invalid: 'inválido',
    missing: 'ausente',
};

const expanded = ref(new Set());

function toggle(orderId) {
    // Set en un ref: reasignamos para que Vue detecte el cambio sin envolver cada orden.
    const next = new Set(expanded.value);

    next.has(orderId) ? next.delete(orderId) : next.add(orderId);

    expanded.value = next;
}

const allExpanded = computed(
    () => props.orders.length > 0 && expanded.value.size === props.orders.length,
);

function toggleAll() {
    expanded.value = allExpanded.value ? new Set() : new Set(props.orders.map((order) => order.id));
}

/**
 * Auditabilidad: la orden se cobró en su moneda y el reporte la muestra convertida.
 * Guardamos la tasa a la vista para que alguien pueda rehacer la cuenta sin abrir la base.
 */
function fxHint(order) {
    if (!order.fx_rate) {
        return null;
    }

    return `1 ${order.currency} = ${order.fx_rate} ${props.currency} (tasa aplicada al momento de la orden)`;
}

function isConverted(order) {
    return order.currency && order.currency !== props.currency;
}

function itemsCount(order) {
    return (order.items ?? []).reduce((acc, item) => acc + (Number(item.quantity) || 0), 0);
}

const cardClass =
    'rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800';
const dtClass = 'text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400';
const ddClass = 'mt-0.5 text-sm text-gray-900 dark:text-gray-100';
</script>

<template>
    <Head :title="customer.name || customer.external_id" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <div class="flex items-baseline gap-3">
                    <Link
                        :href="route('dashboard.customers.index')"
                        class="text-sm text-indigo-600 hover:underline dark:text-indigo-400"
                    >
                        ← Clientes
                    </Link>

                    <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                        {{ customer.name || 'Sin nombre' }}
                    </h2>
                </div>

                <p class="font-mono text-xs text-gray-500 dark:text-gray-400">
                    {{ customer.external_id }}
                </p>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                <!-- Perfil -->
                <div :class="cardClass" class="p-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Perfil</h3>

                        <span
                            v-if="metrics.segment"
                            class="inline-flex items-center rounded bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700 ring-1 ring-inset ring-indigo-600/20 dark:bg-indigo-500/10 dark:text-indigo-300 dark:ring-indigo-400/20"
                            :title="`Segmento RFM: ${metrics.segment}`"
                        >
                            {{ metrics.segment_label ?? metrics.segment }}
                        </span>
                    </div>

                    <dl class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                        <div class="col-span-2">
                            <dt :class="dtClass">Email</dt>
                            <dd :class="ddClass">
                                <span class="inline-flex items-center gap-2">
                                    <EmailStatusDot :status="customer.email_status" />
                                    <!--
                                        Acá sí va el email completo: el listado lo enmascara porque
                                        se exporta en bloque, pero el detalle es la vista de un caso
                                        puntual y el agente necesita la dirección real.
                                    -->
                                    <a
                                        v-if="customer.email"
                                        :href="`mailto:${customer.email}`"
                                        class="break-all font-mono text-xs text-indigo-600 hover:underline dark:text-indigo-400"
                                    >
                                        {{ customer.email }}
                                    </a>
                                    <span v-else class="text-gray-500 dark:text-gray-400">—</span>
                                </span>
                                <span
                                    v-if="customer.email_normalized && customer.email_normalized !== customer.email"
                                    class="mt-0.5 block font-mono text-[11px] text-gray-500 dark:text-gray-400"
                                    title="Forma normalizada usada para deduplicar clientes."
                                >
                                    norm.: {{ customer.email_normalized }}
                                </span>
                                <span class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">
                                    Estado: {{ EMAIL_STATUS_LABEL[customer.email_status] ?? customer.email_status ?? 'desconocido' }}
                                </span>
                            </dd>
                        </div>

                        <div>
                            <dt :class="dtClass">Teléfono</dt>
                            <dd :class="ddClass">{{ customer.phone || '—' }}</dd>
                        </div>

                        <div>
                            <dt :class="dtClass">País</dt>
                            <dd :class="ddClass">{{ customer.country || '—' }}</dd>
                        </div>

                        <div>
                            <dt :class="dtClass">Primera vez</dt>
                            <dd :class="ddClass">
                                {{ formatDate(customer.first_seen_at, { timeZone: tenant.timezone }) }}
                            </dd>
                        </div>

                        <div>
                            <dt :class="dtClass">Última actividad</dt>
                            <dd :class="ddClass">
                                {{ formatDateTime(customer.last_seen_at, { timeZone: tenant.timezone }) }}
                            </dd>
                        </div>
                    </dl>
                </div>

                <!-- Métricas -->
                <div class="grid grid-cols-2 gap-4 lg:grid-cols-5">
                    <StatCard
                        label="Total neto"
                        :note="`En ${currency}, moneda de reporte del tenant`"
                    >
                        <Money :amount-minor="metrics.total_net_reporting_minor" :currency="currency" />
                    </StatCard>

                    <StatCard label="Ticket promedio" note="Sobre órdenes netas">
                        <Money :amount-minor="metrics.avg_order_reporting_minor" :currency="currency" />
                    </StatCard>

                    <StatCard
                        label="Órdenes"
                        :value="formatNumber(metrics.net_orders_count)"
                        :note="`${formatNumber(metrics.orders_count)} en total, incluyendo canceladas`"
                    />

                    <StatCard
                        label="Devoluciones"
                        :value="formatNumber(metrics.returns_count)"
                        note="Órdenes devueltas por el cliente"
                    />

                    <StatCard
                        label="Recencia"
                        :value="metrics.recency_days === null || metrics.recency_days === undefined ? '—' : `${formatNumber(metrics.recency_days)} d`"
                        :note="`Última orden ${formatDate(metrics.last_order_at, { timeZone: tenant.timezone })}`"
                    />
                </div>

                <!-- Historial de órdenes -->
                <div :class="cardClass">
                    <div
                        class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 px-4 py-3 dark:border-gray-700"
                    >
                        <div>
                            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                Historial de órdenes
                            </h3>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                {{ formatNumber(orders.length) }} órdenes ·
                                primera {{ formatDate(metrics.first_order_at, { timeZone: tenant.timezone }) }} ·
                                última {{ formatDate(metrics.last_order_at, { timeZone: tenant.timezone }) }}
                            </p>
                        </div>

                        <button
                            v-if="orders.length"
                            type="button"
                            class="rounded-md border border-gray-300 px-2.5 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700"
                            @click="toggleAll"
                        >
                            {{ allExpanded ? 'Colapsar todo' : 'Expandir todo' }}
                        </button>
                    </div>

                    <div v-if="orders.length" class="divide-y divide-gray-100 dark:divide-gray-700">
                        <div v-for="order in orders" :key="order.id">
                            <button
                                type="button"
                                class="flex w-full items-center gap-3 px-4 py-3 text-left transition hover:bg-gray-50 dark:hover:bg-gray-700/40"
                                :aria-expanded="expanded.has(order.id)"
                                @click="toggle(order.id)"
                            >
                                <svg
                                    class="h-3.5 w-3.5 shrink-0 text-gray-400 transition-transform"
                                    :class="expanded.has(order.id) ? 'rotate-90' : ''"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="2"
                                    aria-hidden="true"
                                >
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 6l6 6-6 6" />
                                </svg>

                                <span class="w-32 shrink-0 truncate font-mono text-xs text-gray-600 dark:text-gray-400">
                                    {{ order.external_id }}
                                </span>

                                <StatusBadge :status="order.status" class="shrink-0" />

                                <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400">
                                    {{ formatDateTime(order.placed_at, { timeZone: tenant.timezone }) }}
                                </span>

                                <!--
                                    Dato que llegó tarde: si la orden se registró varios días después
                                    de ocurrida, cualquier reporte cerrado antes de esa llegada no la
                                    incluía. Vale la pena saberlo al mirar una discrepancia.
                                -->
                                <span
                                    v-if="order.arrival_lag_days > 1"
                                    class="shrink-0 rounded bg-gray-100 px-1.5 py-0.5 text-[11px] text-gray-600 dark:bg-gray-700 dark:text-gray-300"
                                    :title="`Se recibió ${order.arrival_lag_days} días después de la fecha de la orden (${formatDateTime(order.received_at, { timeZone: tenant.timezone })}). Los reportes cerrados antes de esa fecha no la contemplaban.`"
                                >
                                    +{{ order.arrival_lag_days }} d de demora
                                </span>

                                <span class="ml-auto shrink-0 text-right">
                                    <span class="block text-sm font-medium text-gray-900 dark:text-gray-100">
                                        <Money
                                            :amount-minor="order.total_amount_minor"
                                            :currency="order.currency"
                                        />
                                    </span>

                                    <span
                                        v-if="isConverted(order)"
                                        class="block text-xs text-gray-500 dark:text-gray-400"
                                    >
                                        ≈
                                        <Money
                                            :amount-minor="order.reporting_amount_minor"
                                            :currency="currency"
                                            :hint="fxHint(order)"
                                        />
                                    </span>
                                </span>
                            </button>

                            <div
                                v-if="expanded.has(order.id)"
                                class="bg-gray-50 px-4 pb-4 pt-1 dark:bg-gray-900/40"
                            >
                                <table class="min-w-full text-sm">
                                    <thead>
                                        <tr class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                            <th class="py-1 pr-3 font-medium">SKU</th>
                                            <th class="py-1 pr-3 font-medium">Producto</th>
                                            <th class="py-1 pr-3 font-medium">Categoría</th>
                                            <th class="py-1 pr-3 text-right font-medium">Cant.</th>
                                            <th class="py-1 pr-3 text-right font-medium">Unitario</th>
                                            <th class="py-1 text-right font-medium">Total</th>
                                        </tr>
                                    </thead>

                                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                        <tr v-for="item in order.items ?? []" :key="item.id">
                                            <td class="py-1.5 pr-3 font-mono text-xs text-gray-500 dark:text-gray-400">
                                                {{ item.product?.sku ?? '—' }}
                                            </td>
                                            <td class="py-1.5 pr-3 text-gray-800 dark:text-gray-200">
                                                {{ item.product?.name ?? '—' }}
                                            </td>
                                            <td class="py-1.5 pr-3 text-gray-500 dark:text-gray-400">
                                                {{ item.product?.category ?? '—' }}
                                            </td>
                                            <td class="py-1.5 pr-3 text-right tabular-nums text-gray-800 dark:text-gray-200">
                                                {{ formatNumber(item.quantity) }}
                                            </td>
                                            <td class="py-1.5 pr-3 text-right text-gray-800 dark:text-gray-200">
                                                <Money
                                                    :amount-minor="item.unit_amount_minor"
                                                    :currency="item.currency ?? order.currency"
                                                />
                                            </td>
                                            <td class="py-1.5 text-right font-medium text-gray-900 dark:text-gray-100">
                                                <Money
                                                    :amount-minor="item.total_amount_minor"
                                                    :currency="item.currency ?? order.currency"
                                                />
                                            </td>
                                        </tr>

                                        <tr v-if="!(order.items ?? []).length">
                                            <td colspan="6" class="py-2 text-xs text-gray-500 dark:text-gray-400">
                                                La orden no tiene renglones cargados.
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>

                                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                    {{ formatNumber(itemsCount(order)) }} unidades ·
                                    moneda de la orden {{ order.currency }}
                                    <template v-if="isConverted(order)">
                                        · convertida a {{ currency }} con tasa
                                        <span class="font-mono">{{ order.fx_rate }}</span>
                                    </template>
                                    · registrada el
                                    {{ formatDateTime(order.received_at, { timeZone: tenant.timezone }) }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div v-else class="p-6">
                        <EmptyState
                            title="Sin órdenes"
                            description="Este cliente todavía no registró ninguna orden en el período importado."
                        />
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
