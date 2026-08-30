<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import EmailStatusDot from '@/Components/EmailStatusDot.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Money from '@/Components/Money.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextInput from '@/Components/TextInput.vue';
import { formatDateTime, formatNumber } from '@/Support/format';

const props = defineProps({
    tenant: { type: Object, required: true },
    customers: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    segmentOptions: { type: Array, default: () => [] },
    currency: { type: String, required: true },
});

const rows = computed(() => props.customers?.data ?? []);

const q = ref(props.filters.q ?? '');
const segment = ref(props.filters.segment ?? '');
const country = ref(props.filters.country ?? '');
const emailStatus = ref(props.filters.email_status ?? '');
const sort = ref(props.filters.sort ?? 'last_seen_at');
const direction = ref(props.filters.direction ?? 'desc');
const perPage = ref(props.filters.per_page ?? 25);

watch(
    () => props.filters,
    (filters) => {
        q.value = filters.q ?? '';
        segment.value = filters.segment ?? '';
        country.value = filters.country ?? '';
        emailStatus.value = filters.email_status ?? '';
        sort.value = filters.sort ?? 'last_seen_at';
        direction.value = filters.direction ?? 'desc';
        perPage.value = filters.per_page ?? 25;
    },
);

function buildParams(overrides = {}) {
    const params = {
        q: q.value,
        segment: segment.value,
        country: country.value,
        email_status: emailStatus.value,
        sort: sort.value,
        direction: direction.value,
        per_page: perPage.value,
        ...overrides,
    };

    // Los vacíos se omiten para que la URL sea legible y compartible: un soporte
    // que pega el link en un ticket no quiere arrastrar seis parámetros vacíos.
    return Object.fromEntries(
        Object.entries(params).filter(([, value]) => value !== null && value !== undefined && value !== ''),
    );
}

// Cambiar un filtro invalida el cursor: seguir paginando desde el cursor viejo
// devolvería una ventana de un conjunto que ya no existe.
function apply(overrides = {}) {
    router.get(route('dashboard.customers.index'), buildParams(overrides), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

let searchTimer = null;

watch(q, (value) => {
    // Si el valor coincide con el que ya está aplicado, viene de la resincronización
    // con las props y no de una tecla: no hay que volver a pedir nada.
    if ((value ?? '') === (props.filters.q ?? '')) {
        return;
    }

    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => apply(), 300);
});

let countryTimer = null;

watch(country, (value) => {
    if ((value ?? '') === (props.filters.country ?? '')) {
        return;
    }

    clearTimeout(countryTimer);
    countryTimer = setTimeout(() => apply(), 300);
});

function setFilter(key, value) {
    if (key === 'segment') segment.value = value;
    if (key === 'email_status') emailStatus.value = value;
    if (key === 'per_page') perPage.value = value;

    apply();
}

const SORTABLE = {
    external_id: 'identificador',
    name: 'nombre',
    last_seen_at: 'última actividad',
};

function sortBy(field) {
    if (sort.value === field) {
        direction.value = direction.value === 'asc' ? 'desc' : 'asc';
    } else {
        sort.value = field;
        // Fechas arrancan de lo más reciente; texto, alfabético.
        direction.value = field === 'last_seen_at' ? 'desc' : 'asc';
    }

    apply();
}

function resetFilters() {
    q.value = '';
    segment.value = '';
    country.value = '';
    emailStatus.value = '';

    apply({ q: '', segment: '', country: '', email_status: '' });
}

const hasActiveFilters = computed(
    () => Boolean(q.value || segment.value || country.value || emailStatus.value),
);

const EMAIL_STATUS_OPTIONS = [
    { value: 'valid', label: 'Email válido' },
    { value: 'invalid', label: 'Email inválido' },
    { value: 'missing', label: 'Sin email' },
];

const PER_PAGE_OPTIONS = [
    { value: 25, label: '25 por página' },
    { value: 50, label: '50 por página' },
    { value: 100, label: '100 por página' },
];

// Los países visibles alimentan un datalist: no hay endpoint de catálogo y sugerir
// lo que está a la vista es mejor que un input a ciegas.
const countrySuggestions = computed(() =>
    [...new Set(rows.value.map((row) => row.country).filter(Boolean))].sort(),
);

function recencyLabel(days) {
    if (days === null || days === undefined) {
        return '—';
    }

    return `${formatNumber(days)} d`;
}

// `field-label` trae `display:block`: en un <th> eso rompería el layout de la tabla,
// así que la utilidad `table-cell` (capa utilities, gana sobre components) lo restituye.
// La densidad es la misma que la tabla de Usuarios: px-4 py-2.5 en th y td.
const thClass =
    'field-label table-cell whitespace-nowrap px-4 py-2.5 text-left uppercase';
const tdClass = 'whitespace-nowrap px-4 py-2.5 text-sm text-ink-soft';
</script>

<template>
    <Head title="Clientes" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-xl font-semibold leading-tight text-ink">
                    Datos de clientes
                </h2>

                <p class="text-sm text-ink-muted">{{ tenant.name }}</p>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-4 px-4 sm:px-6 lg:px-8">
                <!--
                    La barra de filtros va sin sombra: es una superficie de trabajo, no una
                    tarjeta de contenido. La sombra de marca se reserva para la tabla.
                -->
                <div
                    class="rounded-card border border-line bg-surface p-4"
                >
                    <!--
                        La búsqueda es la acción principal: un agente de soporte llega con un
                        email o un número de cliente en la mano. Va primera, ancha y con foco.
                    -->
                    <div class="flex flex-col gap-3 lg:flex-row lg:items-end">
                        <div class="flex-1">
                            <label for="customers-search" class="field-label">
                                Buscar cliente
                            </label>

                            <div class="relative mt-1">
                                <svg
                                    class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-muted"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.8"
                                    aria-hidden="true"
                                >
                                    <circle cx="11" cy="11" r="7" />
                                    <path stroke-linecap="round" d="M20 20l-3.5-3.5" />
                                </svg>

                                <TextInput
                                    id="customers-search"
                                    v-model="q"
                                    type="search"
                                    autofocus
                                    autocomplete="off"
                                    placeholder="Email, nombre o identificador externo"
                                    class="field block py-2 pl-9"
                                />
                            </div>
                        </div>

                        <div class="flex flex-wrap items-end gap-3">
                            <div>
                                <label for="customers-segment" class="field-label">
                                    Segmento
                                </label>
                                <SelectInput
                                    id="customers-segment"
                                    class="field mt-1 block"
                                    :model-value="segment"
                                    :options="segmentOptions"
                                    placeholder="Todos"
                                    @update:model-value="(value) => setFilter('segment', value)"
                                />
                            </div>

                            <div>
                                <label for="customers-country" class="field-label">
                                    País
                                </label>
                                <input
                                    id="customers-country"
                                    v-model="country"
                                    list="customers-country-options"
                                    placeholder="AR, CL…"
                                    class="field mt-1 block w-24 py-2 uppercase"
                                />
                                <datalist id="customers-country-options">
                                    <option v-for="code in countrySuggestions" :key="code" :value="code" />
                                </datalist>
                            </div>

                            <div>
                                <label for="customers-email-status" class="field-label">
                                    Estado del email
                                </label>
                                <SelectInput
                                    id="customers-email-status"
                                    class="field mt-1 block"
                                    :model-value="emailStatus"
                                    :options="EMAIL_STATUS_OPTIONS"
                                    placeholder="Cualquiera"
                                    @update:model-value="(value) => setFilter('email_status', value)"
                                />
                            </div>

                            <div>
                                <label for="customers-per-page" class="field-label">
                                    Filas
                                </label>
                                <SelectInput
                                    id="customers-per-page"
                                    class="field mt-1 block"
                                    :model-value="perPage"
                                    :options="PER_PAGE_OPTIONS"
                                    @update:model-value="(value) => setFilter('per_page', value)"
                                />
                            </div>

                            <button
                                v-if="hasActiveFilters"
                                type="button"
                                class="focus-ring rounded-pill pb-2.5 text-xs font-medium text-brand-600 hover:text-brand-700 hover:underline"
                                @click="resetFilters"
                            >
                                Limpiar filtros
                            </button>
                        </div>
                    </div>
                </div>

                <div class="card overflow-hidden">
                    <div v-if="rows.length" class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-line">
                            <thead class="bg-canvas">
                                <tr>
                                    <th
                                        v-for="field in ['external_id', 'name']"
                                        :key="field"
                                        scope="col"
                                        :class="thClass"
                                    >
                                        <button
                                            type="button"
                                            class="focus-ring inline-flex items-center gap-1 rounded-chip hover:text-ink"
                                            :title="`Ordenar por ${SORTABLE[field]}`"
                                            @click="sortBy(field)"
                                        >
                                            {{ field === 'name' ? 'Cliente' : 'ID externo' }}
                                            <span v-if="sort === field" aria-hidden="true">
                                                {{ direction === 'asc' ? '↑' : '↓' }}
                                            </span>
                                        </button>
                                    </th>

                                    <th
                                        scope="col"
                                        :class="thClass"
                                        title="En el listado el email va enmascarado a propósito: esta pantalla se exporta y se copia. El detalle del cliente muestra el email completo."
                                    >
                                        Email
                                    </th>
                                    <th scope="col" :class="thClass">País</th>
                                    <th scope="col" :class="thClass">Segmento</th>
                                    <th scope="col" :class="[thClass, 'text-right']">Órdenes</th>
                                    <th scope="col" :class="[thClass, 'text-right']">Total neto</th>
                                    <th scope="col" :class="[thClass, 'text-right']">Ticket prom.</th>
                                    <th scope="col" :class="[thClass, 'text-right']">Recencia</th>
                                    <th scope="col" :class="thClass">
                                        <button
                                            type="button"
                                            class="focus-ring inline-flex items-center gap-1 rounded-chip hover:text-ink"
                                            title="Ordenar por última actividad"
                                            @click="sortBy('last_seen_at')"
                                        >
                                            Última actividad
                                            <span v-if="sort === 'last_seen_at'" aria-hidden="true">
                                                {{ direction === 'asc' ? '↑' : '↓' }}
                                            </span>
                                        </button>
                                    </th>
                                    <th scope="col" class="px-4 py-2.5"><span class="sr-only">Ver</span></th>
                                </tr>
                            </thead>

                            <tbody class="divide-y divide-line">
                                <tr
                                    v-for="customer in rows"
                                    :key="customer.id"
                                    class="hover:bg-raised"
                                >
                                    <td :class="[tdClass, 'font-mono text-xs text-ink-muted']">
                                        {{ customer.external_id }}
                                    </td>

                                    <td :class="tdClass">
                                        <Link
                                            :href="route('dashboard.customers.show', customer.id)"
                                            class="focus-ring rounded-chip font-medium text-brand-600 hover:text-brand-700 hover:underline"
                                        >
                                            {{ customer.name || 'Sin nombre' }}
                                        </Link>
                                    </td>

                                    <td :class="tdClass">
                                        <span class="inline-flex items-center gap-2">
                                            <EmailStatusDot :status="customer.email_status" />
                                            <span
                                                class="font-mono text-xs"
                                                title="Email enmascarado. El detalle del cliente muestra la dirección completa."
                                            >
                                                {{ customer.email_masked || '—' }}
                                            </span>
                                        </span>
                                    </td>

                                    <td :class="tdClass">{{ customer.country || '—' }}</td>

                                    <td :class="tdClass">
                                        {{ customer.metrics?.segment ?? '—' }}
                                    </td>

                                    <td :class="[tdClass, 'text-right tabular-nums']">
                                        <span
                                            :title="`${customer.metrics?.net_orders_count ?? 0} netas sobre ${customer.metrics?.orders_count ?? 0} totales`"
                                        >
                                            {{ formatNumber(customer.metrics?.net_orders_count) }}
                                            <span class="text-ink-muted">
                                                / {{ formatNumber(customer.metrics?.orders_count) }}
                                            </span>
                                        </span>
                                    </td>

                                    <td :class="[tdClass, 'text-right']">
                                        <Money
                                            :amount-minor="customer.metrics?.total_net_reporting_minor"
                                            :currency="currency"
                                        />
                                    </td>

                                    <td :class="[tdClass, 'text-right']">
                                        <Money
                                            :amount-minor="customer.metrics?.avg_order_reporting_minor"
                                            :currency="currency"
                                        />
                                    </td>

                                    <td :class="[tdClass, 'text-right tabular-nums']">
                                        {{ recencyLabel(customer.metrics?.recency_days) }}
                                    </td>

                                    <td :class="[tdClass, 'text-ink-muted']">
                                        {{ formatDateTime(customer.last_seen_at, { timeZone: tenant.timezone }) }}
                                    </td>

                                    <td class="px-4 py-2.5 text-right">
                                        <Link
                                            :href="route('dashboard.customers.show', customer.id)"
                                            class="focus-ring rounded-chip text-xs font-medium text-brand-600 hover:text-brand-700 hover:underline"
                                        >
                                            Ver
                                        </Link>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-else class="p-6">
                        <EmptyState
                            title="Ningún cliente coincide"
                            description="Revisá la búsqueda o quitá algún filtro. La búsqueda cubre email, nombre e identificador externo."
                        >
                            <button
                                v-if="hasActiveFilters"
                                type="button"
                                class="focus-ring rounded-pill border border-line-strong px-3 py-1.5 text-xs font-medium text-ink-soft hover:bg-canvas"
                                @click="resetFilters"
                            >
                                Limpiar filtros
                            </button>
                        </EmptyState>
                    </div>

                    <!--
                        Paginación por cursor: sin números de página porque el total no se
                        conoce (y contarlo en cada request sobre una tabla grande se paga caro).
                    -->
                    <div
                        class="flex items-center justify-between gap-3 border-t border-line px-4 py-3 text-xs text-ink-muted"
                    >
                        <p>
                            {{ formatNumber(rows.length) }} clientes en esta página · email
                            enmascarado por diseño
                        </p>

                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                class="focus-ring rounded-pill border border-line-strong px-3 py-1.5 font-medium text-ink-soft transition enabled:hover:bg-canvas disabled:bg-raised disabled:text-ink-muted disabled:border-line"
                                :disabled="!customers.prev_cursor"
                                @click="apply({ cursor: customers.prev_cursor })"
                            >
                                ← Anterior
                            </button>

                            <button
                                type="button"
                                class="focus-ring rounded-pill border border-line-strong px-3 py-1.5 font-medium text-ink-soft transition enabled:hover:bg-canvas disabled:bg-raised disabled:text-ink-muted disabled:border-line"
                                :disabled="!customers.next_cursor"
                                @click="apply({ cursor: customers.next_cursor })"
                            >
                                Siguiente →
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
