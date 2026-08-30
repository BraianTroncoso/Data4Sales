<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import Checkbox from '@/Components/Checkbox.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

defineProps({
    canResetPassword: { type: Boolean, default: true },
    status: { type: String, default: null },
});

/*
 * El formulario pide TRES cosas, no dos.
 *
 * El email no es un identificador global: el índice único de la tabla es
 * (tenant_id, email), porque la misma persona puede trabajar para dos
 * retailers y dos retailers pueden tener un contacto@ cada uno. Sin la
 * organización, la búsqueda sería ambigua por diseño.
 *
 * Es el mismo modelo que usa Slack con el workspace.
 */
const form = useForm({
    tenant: '',
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Ingresar" />

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

            <div class="mt-4">
                <InputLabel for="password" value="Contraseña" />

                <TextInput
                    id="password"
                    v-model="form.password"
                    type="password"
                    class="mt-1 block w-full"
                    required
                    autocomplete="current-password"
                />

                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <div class="mt-4 block">
                <label class="flex items-center">
                    <Checkbox v-model:checked="form.remember" name="remember" />
                    <span
                        class="ms-2 text-sm text-ink-muted"
                        >Recordarme</span
                    >
                </label>
            </div>

            <div class="mt-4 flex items-center justify-end">
                <Link
                    v-if="canResetPassword"
                    :href="route('password.request')"
                    class="rounded-md text-sm text-ink-muted underline hover:text-ink focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2 focus:ring-offset-canvas"
                >
                    ¿Olvidaste tu contraseña?
                </Link>

                <PrimaryButton class="ms-4" :disabled="form.processing">
                    Ingresar
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
