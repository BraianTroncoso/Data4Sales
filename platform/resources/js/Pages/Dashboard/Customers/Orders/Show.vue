<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Money from '@/Components/Money.vue';
import StatCard from '@/Components/StatCard.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import { formatDateTime, formatNumber } from '@/Support/format';

/*
 * Detalle de una orden.
 *
 * Existe por algo concreto: el historial de estados vivía en la base y no se
 * mostraba en ninguna pantalla. Son 2.726 filas sólo para Casa Luna. Junto con
 * los tres tiempos (cuándo ocurrió, cuándo cambió en el origen, cuándo llegó a
 * la plataforma) y la tasa de cambio aplicada, es la evidencia de trazabilidad
 * del sistema: acá se puede reconstruir por qué un número es el que es.
 */
const props = defineProps({
    tenant: { type: Object, required: true },
    customer: { type: Object, required: true },
    order: { type: Object, required: true },
    currency: { type: String, required: true },
});

/*
 * `items` puede llegar de dos formas y las dos son legítimas: como array pelado,
 * o envuelto en `{ data: [...] }`, que es lo que produce
 * `OrderItemResource::collection()` cuando el recurso viaja anidado dentro de
 * otro. Normalizar acá evita que la pantalla dependa de cuál de las dos mandó
 * el servidor, y de que esa decisión cambie más adelante.
 */
function unwrap(value) {
    if (Array.isArray(value)) {
        return value;
    }

    return Array.isArray(value?.data) ? value.data : [];
}

const items = computed(() => unwrap(props.order.items));

const history = computed(() => unwrap(props.order.status_history));

const unitsCount = computed(() =>
    items.value.reduce((acc, item) => acc + (Number(item.quantity) || 0), 0),
);

/*
 * La orden se cobró en su moneda y el tablero la muestra convertida. Cuando las
 * dos monedas coinciden no hay conversión que explicar y mostrar la tasa sólo
 * agrega ruido.
 */
const isConverted = computed(
    () => Boolean(props.order.currency) && props.order.currency !== props.currency,
);

const fxHint = computed(() => {
    if (!props.order.fx_rate || !isConverted.value) {
        return null;
    }

    return `1 ${props.order.currency} = ${props.order.fx_rate} ${props.currency}, tasa aplicada al momento de la orden`;
});

/*
 * La demora entre que algo pasa en el comercio y que llega acá es el dato que
 * explica por qué un tablero puede estar "mal": no está mal, está esperando.
 * Por eso se muestra como métrica y no como nota al pie.
 */
const lagDays = computed(() => props.order.arrival_lag_days ?? null);

const lagNote = computed(() => {
    if (lagDays.value === null) {
        return 'Sin dato de demora';
    }

    if (lagDays.value === 0) {
        return 'Llegó el mismo día';
    }

    return `Entre la orden y su llegada a la plataforma`;
});

const dtClass = 'text-xs uppercase tracking-wide text-ink-muted';
const ddClass = 'mt-0.5 text-sm text-ink';
const thClass =
    'field-label table-cell whitespace-nowrap px-4 py-2.5 text-left uppercase';
const tdClass = 'px-4 py-2.5 text-sm text-ink-soft';

function when(value) {
    return formatDateTime(value, { timeZone: props.tenant.timezone });
}
</script>

<template>
    <Head :title="`Orden ${order.external_id}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <div class="flex flex-wrap items-baseline gap-3">
                    <Link
                        :href="route('dashboard.customers.show', customer.id)"
                        class="focus-ring rounded-chip text-sm text-brand-600 hover:text-brand-700 hover:underline"
                    >
                        ← {{ customer.name || customer.external_id }}
                    </Link>

                    <h2 class="font-mono text-xl font-semibold leading-tight text-ink">
                        {{ order.external_id }}
                    </h2>

                    <StatusBadge :status="order.status" />
                </div>

                <p class="font-mono text-xs text-ink-muted">
                    {{ customer.external_id }}
                </p>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                <!-- Métricas de la orden -->
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard label="Total" :note="`En ${currency}, moneda de reporte del tenant`">
                        <Money
                            :amount-minor="order.reporting_amount_minor"
                            :currency="currency"
                        />
                    </StatCard>

                    <StatCard
                        label="Cobrado"
                        :note="isConverted ? 'En la moneda original de la orden' : 'Sin conversión: misma moneda'"
                    >
                        <Money
                            :amount-minor="order.total_amount_minor"
                            :currency="order.currency"
                        />
                    </StatCard>

                    <StatCard
                        label="Unidades"
                        :value="formatNumber(unitsCount)"
                        :note="`${formatNumber(items.length)} ${items.length === 1 ? 'renglón' : 'renglones'}`"
                    />

                    <StatCard
                        label="Demora de llegada"
                        :value="lagDays === null ? '—' : `${formatNumber(lagDays)} d`"
                        :note="lagNote"
                    />
                </div>

                <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <!-- Renglones -->
                    <div class="card lg:col-span-2">
                        <div class="flex flex-wrap items-baseline justify-between gap-2 p-4">
                            <h3 class="text-sm font-semibold text-ink">Renglones</h3>
                            <p class="text-xs text-ink-muted">
                                Los importes son los de la orden, en {{ order.currency }}
                            </p>
                        </div>

                        <div v-if="items.length" class="overflow-x-auto">
                            <table class="min-w-full">
                                <caption class="sr-only">
                                    Productos incluidos en la orden {{ order.external_id }}
                                </caption>

                                <thead>
                                    <tr class="border-y border-line">
                                        <th scope="col" :class="thClass">Producto</th>
                                        <th scope="col" :class="[thClass, 'text-right']">Cant.</th>
                                        <th scope="col" :class="[thClass, 'text-right']">Unitario</th>
                                        <th scope="col" :class="[thClass, 'text-right']">Subtotal</th>
                                    </tr>
                                </thead>

                                <tbody class="divide-y divide-line">
                                    <tr v-for="item in items" :key="item.id">
                                        <th scope="row" :class="[tdClass, 'text-left font-normal']">
                                            <span class="block text-ink">
                                                {{ item.product?.name ?? 'Producto sin nombre' }}
                                            </span>
                                            <span class="block font-mono text-xs text-ink-muted">
                                                {{ item.product?.sku ?? item.product_id }}
                                                <template v-if="item.product?.category">
                                                    · {{ item.product.category }}
                                                </template>
                                            </span>
                                        </th>

                                        <td :class="[tdClass, 'text-right tabular-nums']">
                                            {{ formatNumber(item.quantity) }}
                                        </td>

                                        <td :class="[tdClass, 'text-right']">
                                            <Money
                                                :amount-minor="item.unit_amount_minor"
                                                :currency="item.currency"
                                            />
                                        </td>

                                        <td :class="[tdClass, 'text-right']">
                                            <Money
                                                :amount-minor="item.total_amount_minor"
                                                :currency="item.currency"
                                            />
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div v-else class="p-6">
                            <EmptyState
                                title="Esta orden no tiene renglones"
                                description="La orden llegó sin detalle de productos. El total sigue siendo el que informó el origen."
                            />
                        </div>
                    </div>

                    <!-- Trazabilidad -->
                    <div class="card p-4">
                        <h3 class="text-sm font-semibold text-ink">Trazabilidad</h3>
                        <p class="mt-1 text-xs text-ink-muted">
                            Los tres tiempos responden preguntas distintas y por eso viajan separados.
                        </p>

                        <dl class="mt-4 space-y-4">
                            <div>
                                <dt :class="dtClass">Ocurrió en el comercio</dt>
                                <dd :class="ddClass">{{ when(order.placed_at) }}</dd>
                            </div>

                            <div v-if="order.source_updated_at">
                                <dt :class="dtClass">Última actualización en el origen</dt>
                                <dd :class="ddClass">{{ when(order.source_updated_at) }}</dd>
                                <p class="mt-0.5 text-xs text-ink-muted">
                                    Es la versión del dato: una actualización más vieja que la
                                    guardada se descarta en vez de pisarla.
                                </p>
                            </div>

                            <div>
                                <dt :class="dtClass">Llegó a la plataforma</dt>
                                <dd :class="ddClass">{{ when(order.received_at) }}</dd>
                            </div>

                            <div v-if="isConverted">
                                <dt :class="dtClass">Tasa aplicada</dt>
                                <dd :class="ddClass">
                                    <span class="tabular-nums">{{ order.fx_rate }}</span>
                                </dd>
                                <p class="mt-0.5 text-xs text-ink-muted">{{ fxHint }}</p>
                            </div>
                        </dl>
                    </div>
                </div>

                <!-- Historial de estados -->
                <div class="card p-4">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h3 class="text-sm font-semibold text-ink">Historial de estados</h3>
                        <p v-if="history.length" class="text-xs text-ink-muted">
                            {{ formatNumber(history.length) }}
                            {{ history.length === 1 ? 'cambio registrado' : 'cambios registrados' }}
                        </p>
                    </div>

                    <ol v-if="history.length" class="mt-4 space-y-0">
                        <li
                            v-for="(entry, index) in history"
                            :key="`${entry.to}-${entry.occurred_at}-${index}`"
                            class="relative flex gap-4 pb-5 last:pb-0"
                        >
                            <!--
                                La línea vertical es decorativa: une los puntos del timeline y no
                                aporta nada a quien escucha la página, por eso se oculta a AT.
                            -->
                            <span
                                v-if="index < history.length - 1"
                                class="absolute left-[7px] top-4 h-full w-px bg-line"
                                aria-hidden="true"
                            />

                            <span
                                class="relative mt-1.5 h-3.5 w-3.5 shrink-0 rounded-pill border-2 border-brand bg-canvas"
                                aria-hidden="true"
                            />

                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                <StatusBadge :status="entry.to" />

                                <span v-if="entry.from" class="text-xs text-ink-muted">
                                    desde <StatusBadge :status="entry.from" />
                                </span>

                                <span class="text-sm text-ink-soft">
                                    {{ when(entry.occurred_at) }}
                                </span>

                                <!--
                                    Sólo se muestra si difiere del momento del cambio: si el dato
                                    llegó en el mismo instante, repetirlo es ruido.
                                -->
                                <span
                                    v-if="entry.received_at !== entry.occurred_at"
                                    class="pill border border-line bg-canvas text-ink-muted"
                                >
                                    llegó {{ when(entry.received_at) }}
                                </span>
                            </div>
                        </li>
                    </ol>

                    <div v-else class="py-4">
                        <EmptyState
                            title="Sin cambios de estado registrados"
                            description="La orden entró con su estado actual y no volvió a cambiar."
                        />
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
