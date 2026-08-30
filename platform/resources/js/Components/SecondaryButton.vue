<script setup>
defineProps({
    type: {
        type: String,
        default: 'button',
    },
});
</script>

<template>
    <button
        :type="type"
        class="focus-ring inline-flex items-center rounded-pill border border-line-strong bg-surface px-4 py-2 text-xs font-semibold uppercase tracking-widest text-ink-soft transition duration-150 ease-in-out hover:bg-canvas disabled:cursor-not-allowed disabled:bg-raised disabled:text-ink-soft disabled:shadow-none"
    >
        <slot />
    </button>
</template>
