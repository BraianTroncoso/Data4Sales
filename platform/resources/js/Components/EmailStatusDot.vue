<script setup>
import { computed } from 'vue';

const props = defineProps({
    status: { type: String, default: null },
});

// Un punto y no un badge: en un listado de 50 filas el estado del email es contexto,
// no el dato que el agente vino a leer. El title lo hace explícito cuando importa.
const STATUSES = {
    valid: { label: 'Email válido', classes: 'bg-success-500' },
    invalid: { label: 'Email inválido (rebota o no cumple formato)', classes: 'bg-danger-500' },
    missing: { label: 'Sin email registrado', classes: 'bg-ink-faint' },
    unknown: { label: 'Estado de email sin verificar', classes: 'bg-warning-500' },
};

const entry = computed(
    () => STATUSES[props.status] ?? { label: `Estado: ${props.status ?? 'desconocido'}`, classes: 'bg-ink-faint' },
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
