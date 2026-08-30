<script setup>
import { computed } from 'vue';

const props = defineProps({
    // [{ key?, label, value, formatted, meta, hint, color }]
    items: { type: Array, default: () => [] },
    color: { type: String, default: '#7340ff' },
    // Ancho de la columna de la derecha; se fija para que los valores queden alineados.
    metaWidth: { type: String, default: '6rem' },
});

// La barra se escala contra el máximo de la lista y no contra el total: con distribuciones
// de cola larga (un segmento se lleva el 70 %) el resto quedaría en barras invisibles.
const max = computed(() =>
    props.items.reduce((acc, item) => Math.max(acc, Math.abs(Number(item.value) || 0)), 0),
);

function width(item) {
    if (!max.value) {
        return '0%';
    }

    const ratio = Math.abs(Number(item.value) || 0) / max.value;

    // Mínimo visible: una fila con valor > 0 no debería verse igual que una vacía.
    return `${Math.max(ratio * 100, ratio > 0 ? 1.5 : 0)}%`;
}
</script>

<template>
    <ul class="divide-y divide-line">
        <li v-for="(item, index) in items" :key="item.key ?? item.label ?? index" class="py-2.5">
            <div class="flex items-baseline justify-between gap-3">
                <span
                    class="truncate text-sm text-ink-soft"
                    :title="item.hint ?? item.label"
                >
                    {{ item.label }}
                </span>

                <span
                    class="shrink-0 text-sm font-medium tabular-nums text-ink"
                >
                    {{ item.formatted }}
                </span>
            </div>

            <div class="mt-1.5 flex items-center gap-3">
                <div class="h-1.5 flex-1 rounded-pill bg-raised">
                    <div
                        class="h-1.5 rounded-pill transition-[width] duration-300"
                        :style="{ width: width(item), backgroundColor: item.color ?? color }"
                    />
                </div>

                <span
                    v-if="item.meta"
                    class="shrink-0 text-right text-xs tabular-nums text-ink-muted"
                    :style="{ width: metaWidth }"
                >
                    {{ item.meta }}
                </span>
            </div>
        </li>
    </ul>
</template>
