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
        return 'text-gray-500 dark:text-gray-400';
    }

    const positive = props.invertDelta ? props.delta < 0 : props.delta > 0;

    return positive
        ? 'text-emerald-600 dark:text-emerald-400'
        : 'text-rose-600 dark:text-rose-400';
});
</script>

<template>
    <div
        class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800"
    >
        <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
            {{ label }}
        </p>

        <div class="mt-1 flex items-baseline gap-2">
            <p class="text-2xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">
                <slot>{{ value }}</slot>
            </p>

            <span v-if="deltaText" class="text-xs font-medium" :class="deltaClass">
                {{ deltaText }}
            </span>
        </div>

        <p v-if="note || $slots.note" class="mt-1 text-xs text-gray-500 dark:text-gray-400">
            <slot name="note">{{ note }}</slot>
        </p>
    </div>
</template>
