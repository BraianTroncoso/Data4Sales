<script setup>
import { computed } from 'vue';
import { formatMinor, formatMinorCompact } from '@/Support/money';

const props = defineProps({
    // Entero en unidad mínima, tal como llega del backend.
    amountMinor: { type: [Number, String], default: null },
    currency: { type: String, required: true },
    locale: { type: String, default: undefined },
    compact: { type: Boolean, default: false },
    // Texto extra para el title: sirve para dejar la tasa de cambio a la vista sin ensuciar la fila.
    hint: { type: String, default: null },
});

const text = computed(() =>
    props.compact
        ? formatMinorCompact(props.amountMinor, props.currency, props.locale)
        : formatMinor(props.amountMinor, props.currency, props.locale),
);

// En modo compacto el title guarda el monto exacto: la vista es densa, pero el dato no se pierde.
const title = computed(() => {
    const parts = [];

    if (props.compact) {
        parts.push(formatMinor(props.amountMinor, props.currency, props.locale));
    }

    if (props.hint) {
        parts.push(props.hint);
    }

    return parts.length ? parts.join(' · ') : null;
});
</script>

<template>
    <span class="tabular-nums" :title="title">{{ text }}</span>
</template>
