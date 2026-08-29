<script setup>
// Breeze no trae un select; lo definimos una vez para que los filtros de las tres
// pantallas compartan alto, foco y modo oscuro con TextInput.
defineProps({
    options: { type: Array, default: () => [] },
    placeholder: { type: String, default: null },
});

const model = defineModel({ type: [String, Number, Boolean, null], default: '' });
</script>

<template>
    <select
        v-model="model"
        class="rounded-md border-gray-300 py-2 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200 dark:focus:border-indigo-500"
    >
        <option v-if="placeholder" value="">{{ placeholder }}</option>
        <slot>
            <option v-for="option in options" :key="option.value" :value="option.value">
                {{ option.label }}
            </option>
        </slot>
    </select>
</template>
