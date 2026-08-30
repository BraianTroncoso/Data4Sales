<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    href: {
        type: String,
        required: true,
    },
});
</script>

<template>
    <Link
        :href="href"
        class="focus-ring block w-full px-4 py-2 text-start text-sm leading-5 text-ink-soft transition duration-150 ease-in-out hover:bg-raised focus:bg-raised"
    >
        <slot />
    </Link>
</template>
