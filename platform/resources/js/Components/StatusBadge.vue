<script setup>
import { computed } from 'vue';

const props = defineProps({
    status: { type: String, default: null },
});

// El color codifica el destino de la orden, no el orden del flujo: verde sólo para
// lo que se completó, rojo/ámbar para lo que revirtió, neutros para lo que sigue en curso.
const STATUSES = {
    created: { label: 'Creada', classes: 'bg-canvas text-ink-muted ring-ink-faint/75' },
    paid: { label: 'Pagada', classes: 'bg-info-50 text-info-600 ring-info-500/75' },
    shipped: { label: 'Enviada', classes: 'bg-brand-50 text-brand-700 ring-brand-600/75' },
    delivered: { label: 'Entregada', classes: 'bg-success-50 text-success-700 ring-success-600/75' },
    cancelled: { label: 'Cancelada', classes: 'bg-danger-50 text-danger-700 ring-danger-600/75' },
    returned: { label: 'Devuelta', classes: 'bg-warning-50 text-warning-700 ring-warning-600/75' },
};

const entry = computed(
    () =>
        STATUSES[props.status] ?? {
            label: props.status ?? '—',
            classes: 'bg-canvas text-ink-muted ring-ink-faint/75',
        },
);
</script>

<template>
    <span
        class="pill px-2 py-0.5 ring-1 ring-inset"
        :class="entry.classes"
        :title="status"
    >
        {{ entry.label }}
    </span>
</template>
