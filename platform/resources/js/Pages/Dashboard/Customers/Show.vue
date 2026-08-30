<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
// `route` sólo está inyectada en los templates por el plugin de Ziggy; acá se
// importa para poder consultarla desde el <script setup>.
import { route } from '../../../../../vendor/tightenco/ziggy';
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

/*
 * El detalle de orden se está construyendo del lado del servidor en paralelo.
 * Preguntarle a Ziggy si la ruta existe evita que esta pantalla se caiga entera
 * mientras tanto: `route()` con un nombre no registrado lanza, y se llevaría
 * puesta la ficha del cliente. Cuando la ruta aparece, el enlace se enciende
 * solo, sin tocar este archivo.
 */
const hasOrderDetail = computed(() => {
    try {
        return route().has('dashboard.customers.orders.show');
    } catch {
        return false;
    }
});

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

/*
 * El historial se pagina en el cliente. El servidor ya manda hasta 200 órdenes
 * en la misma respuesta, así que pedirle otra vuelta al backend para pasar de
 * página sería un viaje de red por datos que ya están acá.
 *
 * Y hay una razón más fuerte que el scroll: "Expandir todo" sobre 200 órdenes
 * montaba 200 tablas de renglones de una sola vez. Acotado a una página, el
 * botón vuelve a ser barato.
 */
const ORDERS_PER_PAGE = 10;

const orderPage = ref(1);

const pageCount = computed(() =>
    Math.max(1, Math.ceil(props.orders.length / ORDERS_PER_PAGE)),
);

const pagedOrders = computed(() => {
    const start = (orderPage.value - 1) * ORDERS_PER_PAGE;

    return props.orders.slice(start, start + ORDERS_PER_PAGE);
});

// Rango humano: "1–10 de 47", no "página 1 de 5".
const pageRange = computed(() => {
    if (props.orders.length === 0) {
        return null;
    }

    const start = (orderPage.value - 1) * ORDERS_PER_PAGE + 1;
    const end = Math.min(start + ORDERS_PER_PAGE - 1, props.orders.length);

    return `${formatNumber(start)}–${formatNumber(end)} de ${formatNumber(props.orders.length)}`;
});

function goToPage(page) {
    orderPage.value = Math.min(Math.max(1, page), pageCount.value);
}

/*
 * El servidor corta en 200 órdenes. Cuando el cliente tiene más, decirlo es
 * obligatorio: si no, esta lista contradice al contador de arriba, que sí trae
 * el total real, y el usuario no sabe a cuál creerle.
 */
const isTruncated = computed(
    () => (metrics.value.orders_count ?? 0) > props.orders.length,
);

// Expandir/colapsar aplica sólo a lo que está a la vista.
const allExpanded = computed(
    () =>
        pagedOrders.value.length > 0 &&
        pagedOrders.value.every((order) => expanded.value.has(order.id)),
);

function toggleAll() {
    const next = new Set(expanded.value);

    pagedOrders.value.forEach((order) => {
        allExpanded.value ? next.delete(order.id) : next.add(order.id);
    });

    expanded.value = next;
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

/*
 * Los renglones llegan envueltos en `{ data: [...] }`: es lo que produce
 * `OrderItemResource::collection()` al viajar anidado dentro de `OrderResource`.
 * Tratarlos como array pelado hacía que `.reduce` lanzara al expandir una orden
 * y que el `v-for` iterara sobre el envoltorio en vez de sobre los renglones.
 */
function orderItems(order) {
    const raw = order.items;

    if (Array.isArray(raw)) {
        return raw;
    }

    return Array.isArray(raw?.data) ? raw.data : [];
}

function itemsCount(order) {
    return orderItems(order).reduce((acc, item) => acc + (Number(item.quantity) || 0), 0);
}

// La cadena de la tarjeta vive una sola vez, en `.card` (app.css).
const cardClass = 'card';
const dtClass = 'text-xs uppercase tracking-wide text-ink-muted';
const ddClass = 'mt-0.5 text-sm text-ink';
</script>

<template>
    <Head :title="customer.name || customer.external_id" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <div class="flex items-baseline gap-3">
                    <Link
                        :href="route('dashboard.customers.index')"
                        class="focus-ring rounded-chip text-sm text-brand-600 hover:text-brand-700 hover:underline"
                    >
                        ← Clientes
                    </Link>

                    <h2 class="text-xl font-semibold leading-tight text-ink">
                        {{ customer.name || 'Sin nombre' }}
                    </h2>
                </div>

                <p class="font-mono text-xs text-ink-muted">
                    {{ customer.external_id }}
                </p>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                <!-- Perfil -->
                <div :class="cardClass" class="p-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h3 class="text-sm font-semibold text-ink">Perfil</h3>

                        <span
                            v-if="metrics.segment"
                            class="pill bg-brand-50 px-2 py-0.5 text-brand-700 ring-1 ring-inset ring-brand-600/75"
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
                                        class="focus-ring break-all rounded-chip font-mono text-xs text-brand-600 hover:text-brand-700 hover:underline"
                                    >
                                        {{ customer.email }}
                                    </a>
                                    <span v-else class="text-ink-muted">—</span>
                                </span>
                                <span
                                    v-if="customer.email_normalized && customer.email_normalized !== customer.email"
                                    class="mt-0.5 block font-mono text-[11px] text-ink-muted"
                                    title="Forma normalizada usada para deduplicar clientes."
                                >
                                    norm.: {{ customer.email_normalized }}
                                </span>
                                <span class="mt-0.5 block text-xs text-ink-muted">
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
                        class="flex flex-wrap items-center justify-between gap-2 border-b border-line px-4 py-3"
                    >
                        <div>
                            <h3 class="text-sm font-semibold text-ink">
                                Historial de órdenes
                            </h3>
                            <p class="text-xs text-ink-muted">
                                <template v-if="isTruncated">
                                    últimas {{ formatNumber(orders.length) }} de
                                    {{ formatNumber(metrics.orders_count) }} órdenes ·
                                </template>
                                <template v-else>
                                    {{ formatNumber(orders.length) }} órdenes ·
                                </template>
                                primera {{ formatDate(metrics.first_order_at, { timeZone: tenant.timezone }) }} ·
                                última {{ formatDate(metrics.last_order_at, { timeZone: tenant.timezone }) }}
                            </p>
                        </div>

                        <button
                            v-if="orders.length"
                            type="button"
                            class="focus-ring rounded-pill border border-line-strong px-2.5 py-1.5 text-xs font-medium text-ink-soft hover:bg-canvas"
                            @click="toggleAll"
                        >
                            {{ allExpanded ? 'Colapsar todo' : 'Expandir todo' }}
                        </button>
                    </div>

                    <div v-if="orders.length" class="divide-y divide-line">
                        <div v-for="order in pagedOrders" :key="order.id">
                            <!--
                                Dos acciones distintas en la misma fila, y por eso dos controles:
                                el chevron abre los renglones sin salir de la ficha, y la fila
                                lleva al detalle completo. Antes la fila entera era un acordeón y
                                no había forma de llegar a la orden.
                            -->
                            <div class="flex w-full items-center transition hover:bg-raised">
                                <button
                                    type="button"
                                    class="focus-ring shrink-0 rounded-chip py-3 pl-4 pr-1"
                                    :aria-expanded="expanded.has(order.id)"
                                    :aria-controls="`order-items-${order.id}`"
                                    :title="expanded.has(order.id) ? 'Ocultar los renglones' : 'Ver los renglones acá mismo'"
                                    @click="toggle(order.id)"
                                >
                                    <span class="sr-only">Renglones de {{ order.external_id }}</span>
                                    <svg
                                        class="h-3.5 w-3.5 text-ink-muted transition-transform"
                                        :class="expanded.has(order.id) ? 'rotate-90' : ''"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2"
                                        aria-hidden="true"
                                    >
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 6l6 6-6 6" />
                                    </svg>
                                </button>

                            <component
                                :is="hasOrderDetail ? Link : 'button'"
                                v-bind="
                                    hasOrderDetail
                                        ? { href: route('dashboard.customers.orders.show', [customer.id, order.id]) }
                                        : { type: 'button', onClick: () => toggle(order.id) }
                                "
                                class="focus-ring flex flex-1 items-center gap-3 rounded-chip py-3 pl-2 pr-4 text-left"
                            >
                                <span class="w-32 shrink-0 truncate font-mono text-xs text-ink-muted">
                                    {{ order.external_id }}
                                </span>

                                <StatusBadge :status="order.status" class="shrink-0" />

                                <span class="shrink-0 text-xs text-ink-muted">
                                    {{ formatDateTime(order.placed_at, { timeZone: tenant.timezone }) }}
                                </span>

                                <!--
                                    Dato que llegó tarde: si la orden se registró varios días después
                                    de ocurrida, cualquier reporte cerrado antes de esa llegada no la
                                    incluía. Vale la pena saberlo al mirar una discrepancia.
                                -->
                                <span
                                    v-if="order.arrival_lag_days > 1"
                                    class="shrink-0 rounded-chip border border-line bg-canvas px-1.5 py-0.5 text-[11px] text-ink-muted"
                                    :title="`Se recibió ${order.arrival_lag_days} días después de la fecha de la orden (${formatDateTime(order.received_at, { timeZone: tenant.timezone })}). Los reportes cerrados antes de esa fecha no la contemplaban.`"
                                >
                                    +{{ order.arrival_lag_days }} d de demora
                                </span>

                                <span class="ml-auto shrink-0 text-right">
                                    <span class="block text-sm font-medium text-ink">
                                        <Money
                                            :amount-minor="order.total_amount_minor"
                                            :currency="order.currency"
                                        />
                                    </span>

                                    <span
                                        v-if="isConverted(order)"
                                        class="block text-xs text-ink-muted"
                                    >
                                        ≈
                                        <Money
                                            :amount-minor="order.reporting_amount_minor"
                                            :currency="currency"
                                            :hint="fxHint(order)"
                                        />
                                    </span>
                                </span>
                            </component>
                            </div>

                            <div
                                v-if="expanded.has(order.id)"
                                :id="`order-items-${order.id}`"
                                class="bg-canvas px-4 pb-4 pt-1"
                            >
                                <table class="min-w-full text-sm">
                                    <thead>
                                        <tr class="text-left text-xs uppercase tracking-wide text-ink-muted">
                                            <th class="py-1 pr-3 font-medium">SKU</th>
                                            <th class="py-1 pr-3 font-medium">Producto</th>
                                            <th class="py-1 pr-3 font-medium">Categoría</th>
                                            <th class="py-1 pr-3 text-right font-medium">Cant.</th>
                                            <th class="py-1 pr-3 text-right font-medium">Unitario</th>
                                            <th class="py-1 text-right font-medium">Total</th>
                                        </tr>
                                    </thead>

                                    <tbody class="divide-y divide-line">
                                        <tr v-for="item in orderItems(order)" :key="item.id">
                                            <td class="py-1.5 pr-3 font-mono text-xs text-ink-muted">
                                                {{ item.product?.sku ?? '—' }}
                                            </td>
                                            <td class="py-1.5 pr-3 text-ink">
                                                {{ item.product?.name ?? '—' }}
                                            </td>
                                            <td class="py-1.5 pr-3 text-ink-muted">
                                                {{ item.product?.category ?? '—' }}
                                            </td>
                                            <td class="py-1.5 pr-3 text-right tabular-nums text-ink">
                                                {{ formatNumber(item.quantity) }}
                                            </td>
                                            <td class="py-1.5 pr-3 text-right text-ink">
                                                <Money
                                                    :amount-minor="item.unit_amount_minor"
                                                    :currency="item.currency ?? order.currency"
                                                />
                                            </td>
                                            <td class="py-1.5 text-right font-medium text-ink">
                                                <Money
                                                    :amount-minor="item.total_amount_minor"
                                                    :currency="item.currency ?? order.currency"
                                                />
                                            </td>
                                        </tr>

                                        <tr v-if="!orderItems(order).length">
                                            <td colspan="6" class="py-2 text-xs text-ink-muted">
                                                La orden no tiene renglones cargados.
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>

                                <p class="mt-2 text-xs text-ink-muted">
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

                    <!--
                        Paginación del historial. `aria-live` anuncia el nuevo rango a
                        quien usa lector de pantalla: sin eso, cambiar de página no
                        produce ninguna señal audible.
                    -->
                    <div
                        v-if="orders.length && pageCount > 1"
                        class="flex flex-wrap items-center justify-between gap-3 border-t border-line px-4 py-3"
                    >
                        <p class="text-xs text-ink-muted" aria-live="polite">
                            {{ pageRange }} órdenes
                            <template v-if="isTruncated">
                                · el detalle llega hasta las
                                {{ formatNumber(orders.length) }} más recientes
                            </template>
                        </p>

                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                class="focus-ring rounded-pill border border-line-strong px-3 py-1.5 text-xs font-medium text-ink-soft enabled:hover:bg-raised disabled:bg-raised disabled:text-ink-muted"
                                :disabled="orderPage === 1"
                                @click="goToPage(orderPage - 1)"
                            >
                                ← Anterior
                            </button>

                            <span class="text-xs tabular-nums text-ink-muted">
                                {{ formatNumber(orderPage) }} / {{ formatNumber(pageCount) }}
                            </span>

                            <button
                                type="button"
                                class="focus-ring rounded-pill border border-line-strong px-3 py-1.5 text-xs font-medium text-ink-soft enabled:hover:bg-raised disabled:bg-raised disabled:text-ink-muted"
                                :disabled="orderPage === pageCount"
                                @click="goToPage(orderPage + 1)"
                            >
                                Siguiente →
                            </button>
                        </div>
                    </div>

                    <div v-else-if="!orders.length" class="p-6">
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
