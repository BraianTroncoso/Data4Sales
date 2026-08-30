<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

defineProps({
    status: {
        type: String,
        default: null,
    },
});

/*
 * Pide la organización, igual que el login.
 *
 * El email no identifica a nadie por sí solo: el único de la tabla de usuarios
 * es (tenant_id, email), porque la misma persona puede trabajar para dos
 * retailers. Buscar sólo por email podía mandarle el enlace al usuario de otra
 * organización.
 */
const form = useForm({
    tenant: '',
    email: '',
});

const submit = () => {
    form.post(route('password.email'));
};
</script>

<template>
    <GuestLayout>
        <Head title="Recuperar contraseña" />

        <div class="mb-4 text-sm text-ink-muted">
            Indicá tu organización y tu email y te mandamos un enlace para
            elegir una contraseña nueva.
        </div>

        <div v-if="status" class="mb-4 text-sm font-medium text-success-600">
            {{ status }}
        </div>

        <form @submit.prevent="submit">
            <div>
                <InputLabel for="tenant" value="Organización" />

                <TextInput
                    id="tenant"
                    v-model="form.tenant"
                    type="text"
                    class="mt-1 block w-full"
                    required
                    autofocus
                    autocomplete="organization"
                    placeholder="luna"
                />

                <p class="mt-1 text-xs text-ink-muted">
                    El identificador corto de tu retailer.
                </p>

                <InputError class="mt-2" :message="form.errors.tenant" />
            </div>

            <div class="mt-4">
                <InputLabel for="email" value="Email" />

                <TextInput
                    id="email"
                    v-model="form.email"
                    type="email"
                    class="mt-1 block w-full"
                    required
                    autocomplete="username"
                />

                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div class="mt-4 flex items-center justify-end">
                <PrimaryButton :disabled="form.processing">
                    Enviar enlace
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
