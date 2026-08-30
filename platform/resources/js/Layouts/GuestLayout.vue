<script setup>
import ApplicationWordmark from '@/Components/ApplicationWordmark.vue';
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <!--
        El fondo es el mismo canvas que usa la aplicación autenticada. Antes
        el invitado y el dashboard usaban dos grises distintos para el mismo
        producto, y el salto se notaba al loguearse.
    -->
    <div
        class="flex min-h-screen flex-col items-center bg-canvas pt-6 sm:justify-center sm:pt-0"
    >
        <div>
            <Link href="/">
                <!--
                    Sólo tamaño: el símbolo trae sus propios colores y
                    cualquier color de texto heredado lo teñiría.
                -->
                <ApplicationWordmark class="h-11 w-auto" />
            </Link>
        </div>

        <div
            class="card mt-6 w-full overflow-hidden rounded-panel px-6 py-8 sm:max-w-md"
        >
            <slot />
        </div>
    </div>
</template>
