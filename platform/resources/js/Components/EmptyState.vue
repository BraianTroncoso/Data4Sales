<script setup>
defineProps({
    title: { type: String, default: 'Sin datos' },
    description: { type: String, default: null },
});
</script>

<template>
    <div
        class="flex flex-col items-center justify-center rounded-lg border border-dashed border-gray-300 px-6 py-10 text-center dark:border-gray-700"
    >
        <svg
            class="h-8 w-8 text-gray-400 dark:text-gray-500"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="1.5"
            aria-hidden="true"
        >
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 3v16.5h16.5" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 15l3.5-4 3 2.5L19 8" />
        </svg>

        <p class="mt-3 text-sm font-medium text-gray-900 dark:text-gray-100">{{ title }}</p>

        <p v-if="description" class="mt-1 max-w-md text-sm text-gray-500 dark:text-gray-400">
            {{ description }}
        </p>

        <div v-if="$slots.default" class="mt-4">
            <slot />
        </div>
    </div>
</template>
