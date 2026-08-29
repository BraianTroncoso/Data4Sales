<script setup>
import { computed } from 'vue';

const props = defineProps({
    status: { type: String, default: null },
});

// Un punto y no un badge: en un listado de 50 filas el estado del email es contexto,
// no el dato que el agente vino a leer. El title lo hace explícito cuando importa.
const STATUSES = {
    valid: { label: 'Email válido', classes: 'bg-emerald-500' },
    invalid: { label: 'Email inválido (rebota o no cumple formato)', classes: 'bg-rose-500' },
    missing: { label: 'Sin email registrado', classes: 'bg-gray-300 dark:bg-gray-600' },
    unknown: { label: 'Estado de email sin verificar', classes: 'bg-amber-400' },
};

const entry = computed(
    () => STATUSES[props.status] ?? { label: `Estado: ${props.status ?? 'desconocido'}`, classes: 'bg-gray-300 dark:bg-gray-600' },
);
</script>

<template>
    <span
        class="inline-block h-2 w-2 shrink-0 rounded-full"
        :class="entry.classes"
        :title="entry.label"
        role="img"
        :aria-label="entry.label"
    />
</template>
