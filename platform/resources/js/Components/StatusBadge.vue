<script setup>
import { computed } from 'vue';

const props = defineProps({
    status: { type: String, default: null },
});

// El color codifica el destino de la orden, no el orden del flujo: verde sólo para
// lo que se completó, rojo/ámbar para lo que revirtió, neutros para lo que sigue en curso.
const STATUSES = {
    created: { label: 'Creada', classes: 'bg-gray-100 text-gray-700 ring-gray-500/20 dark:bg-gray-700/40 dark:text-gray-300 dark:ring-gray-400/20' },
    paid: { label: 'Pagada', classes: 'bg-blue-50 text-blue-700 ring-blue-600/20 dark:bg-blue-500/10 dark:text-blue-300 dark:ring-blue-400/20' },
    shipped: { label: 'Enviada', classes: 'bg-indigo-50 text-indigo-700 ring-indigo-600/20 dark:bg-indigo-500/10 dark:text-indigo-300 dark:ring-indigo-400/20' },
    delivered: { label: 'Entregada', classes: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-400/20' },
    cancelled: { label: 'Cancelada', classes: 'bg-rose-50 text-rose-700 ring-rose-600/20 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-400/20' },
    returned: { label: 'Devuelta', classes: 'bg-amber-50 text-amber-800 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-400/20' },
};

const entry = computed(
    () =>
        STATUSES[props.status] ?? {
            label: props.status ?? '—',
            classes: 'bg-gray-100 text-gray-600 ring-gray-500/20 dark:bg-gray-700/40 dark:text-gray-300 dark:ring-gray-400/20',
        },
);
</script>

<template>
    <span
        class="inline-flex items-center rounded px-1.5 py-0.5 text-xs font-medium ring-1 ring-inset"
        :class="entry.classes"
        :title="status"
    >
        {{ entry.label }}
    </span>
</template>
