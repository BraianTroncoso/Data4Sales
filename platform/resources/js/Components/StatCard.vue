<script setup>
import { computed } from 'vue';

const props = defineProps({
    label: { type: String, required: true },
    value: { type: [String, Number], default: null },
    note: { type: String, default: null },
    // Variación relativa respecto de un período previo (fracción: 0.12 = +12 %).
    delta: { type: Number, default: null },
    // Hay métricas donde bajar es bueno (recencia, devoluciones): invierte el color, no el signo.
    invertDelta: { type: Boolean, default: false },
});

const deltaText = computed(() => {
    if (props.delta === null || !Number.isFinite(props.delta)) {
        return null;
    }

    const sign = props.delta > 0 ? '+' : '';

    return `${sign}${new Intl.NumberFormat(undefined, { style: 'percent', maximumFractionDigits: 1 }).format(props.delta)}`;
});

const deltaClass = computed(() => {
    if (props.delta === null || props.delta === 0) {
        return 'text-ink-muted';
    }

    const positive = props.invertDelta ? props.delta < 0 : props.delta > 0;

    return positive
        ? 'text-success-600'
        : 'text-danger-600';
});
</script>

<template>
    <div class="card p-4">
        <p class="field-label uppercase">
            {{ label }}
        </p>

        <div class="mt-1 flex items-baseline gap-2">
            <p class="text-2xl font-semibold tabular-nums text-ink">
                <slot>{{ value }}</slot>
            </p>

            <span v-if="deltaText" class="text-xs font-medium" :class="deltaClass">
                {{ deltaText }}
            </span>
        </div>

        <p v-if="note || $slots.note" class="mt-1 text-xs text-ink-muted">
            <slot name="note">{{ note }}</slot>
        </p>
    </div>
</template>
