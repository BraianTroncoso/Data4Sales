<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DangerButton from '@/Components/DangerButton.vue';
import EmptyState from '@/Components/EmptyState.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextInput from '@/Components/TextInput.vue';
import { formatDate } from '@/Support/format';

const props = defineProps({
    tenant: { type: Object, required: true },
    users: { type: Array, default: () => [] },
    roleOptions: { type: Array, default: () => [] },
});

const roleChoices = computed(() =>
    props.roleOptions.map((option) => ({ value: option.value, label: option.label })),
);

const showCreate = ref(false);

const form = useForm({
    name: '',
    email: '',
    role: props.roleOptions[0]?.value ?? '',
    password: '',
    password_confirmation: '',
});

function openCreate() {
    form.clearErrors();
    form.reset();
    form.role = props.roleOptions[0]?.value ?? '';
    showCreate.value = true;
}

function closeCreate() {
    showCreate.value = false;
    form.reset();
    form.clearErrors();
}

function submitCreate() {
    form.post(route('dashboard.users.store'), {
        preserveScroll: true,
        onSuccess: () => closeCreate(),
    });
}

// Errores por fila: los cambios de rol y de estado son ediciones puntuales; mostrar
// el error arriba de todo obligaría a adivinar a qué usuario corresponde.
const rowErrors = ref({});
const processingId = ref(null);

function patchUser(user, payload) {
    processingId.value = user.id;

    router.patch(route('dashboard.users.update', user.id), payload, {
        preserveScroll: true,
        preserveState: true,
        onError: (errors) => {
            rowErrors.value = { ...rowErrors.value, [user.id]: Object.values(errors)[0] };
        },
        onSuccess: () => {
            const next = { ...rowErrors.value };
            delete next[user.id];
            rowErrors.value = next;
        },
        onFinish: () => {
            processingId.value = null;
        },
    });
}

function changeRole(user, role) {
    if (role === user.role) {
        return;
    }

    patchUser(user, { role });
}

function toggleActive(user) {
    patchUser(user, { is_active: !user.is_active });
}

// El backend también lo impide, pero deshabilitarlo acá evita el viaje y, sobre todo,
// explica el motivo: el único admin del tenant no puede dejarse afuera de su propio panel.
const SELF_LOCK_TITLE =
    'No podés cambiar tu propio rol ni desactivarte: si fueras el único administrador, el tenant quedaría sin quien gestione usuarios. Pedíselo a otro administrador.';

const thClass =
    'whitespace-nowrap px-4 py-2 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400';
const tdClass = 'px-4 py-2.5 text-sm text-gray-700 dark:text-gray-300';
const cardClass =
    'rounded-lg border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800';
</script>

<template>
    <Head title="Usuarios" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                    Usuarios
                </h2>

                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ tenant.name }} · {{ tenant.slug }}
                </p>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-5xl space-y-4 px-4 sm:px-6 lg:px-8">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Empleados con acceso al panel de este tenant. A diferencia de la pantalla
                        de clientes, acá el email va completo: son cuentas internas, no compradores.
                    </p>

                    <PrimaryButton type="button" @click="openCreate">Nuevo usuario</PrimaryButton>
                </div>

                <div :class="cardClass" class="overflow-hidden">
                    <div v-if="users.length" class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-900/40">
                                <tr>
                                    <th scope="col" :class="thClass">Nombre</th>
                                    <th scope="col" :class="thClass">Email</th>
                                    <th scope="col" :class="thClass">Rol</th>
                                    <th scope="col" :class="thClass">Estado</th>
                                    <th scope="col" :class="thClass">Alta</th>
                                    <th scope="col" :class="[thClass, 'text-right']">Acciones</th>
                                </tr>
                            </thead>

                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                <template v-for="user in users" :key="user.id">
                                    <tr :class="user.is_active ? '' : 'opacity-60'">
                                        <td :class="tdClass">
                                            <span class="font-medium text-gray-900 dark:text-gray-100">
                                                {{ user.name }}
                                            </span>
                                            <span
                                                v-if="user.is_self"
                                                class="ml-2 rounded bg-gray-100 px-1.5 py-0.5 text-[11px] text-gray-600 dark:bg-gray-700 dark:text-gray-300"
                                            >
                                                vos
                                            </span>
                                        </td>

                                        <td :class="[tdClass, 'font-mono text-xs']">
                                            {{ user.email }}
                                        </td>

                                        <td :class="tdClass">
                                            <SelectInput
                                                :model-value="user.role"
                                                :options="roleChoices"
                                                :disabled="user.is_self || processingId === user.id"
                                                :title="user.is_self ? SELF_LOCK_TITLE : `Rol actual: ${user.role_label ?? user.role}`"
                                                class="w-40 disabled:cursor-not-allowed disabled:opacity-60"
                                                @update:model-value="(value) => changeRole(user, value)"
                                            />
                                        </td>

                                        <td :class="tdClass">
                                            <span class="inline-flex items-center gap-2">
                                                <span
                                                    class="h-2 w-2 rounded-full"
                                                    :class="user.is_active ? 'bg-emerald-500' : 'bg-gray-400'"
                                                />
                                                {{ user.is_active ? 'Activo' : 'Inactivo' }}
                                            </span>
                                        </td>

                                        <td :class="[tdClass, 'text-gray-500 dark:text-gray-400']">
                                            {{ formatDate(user.created_at, { timeZone: tenant.timezone }) }}
                                        </td>

                                        <td :class="[tdClass, 'text-right']">
                                            <span :title="user.is_self ? SELF_LOCK_TITLE : undefined">
                                                <DangerButton
                                                    v-if="user.is_active"
                                                    type="button"
                                                    class="disabled:cursor-not-allowed disabled:opacity-40"
                                                    :disabled="user.is_self || processingId === user.id"
                                                    @click="toggleActive(user)"
                                                >
                                                    Desactivar
                                                </DangerButton>

                                                <SecondaryButton
                                                    v-else
                                                    type="button"
                                                    class="disabled:cursor-not-allowed disabled:opacity-40"
                                                    :disabled="processingId === user.id"
                                                    @click="toggleActive(user)"
                                                >
                                                    Reactivar
                                                </SecondaryButton>
                                            </span>
                                        </td>
                                    </tr>

                                    <tr v-if="rowErrors[user.id]">
                                        <td colspan="6" class="px-4 pb-2">
                                            <InputError :message="rowErrors[user.id]" />
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

                    <div v-else class="p-6">
                        <EmptyState
                            title="Sin usuarios"
                            description="Todavía no hay cuentas de acceso creadas para este tenant."
                        >
                            <PrimaryButton type="button" @click="openCreate">
                                Crear el primero
                            </PrimaryButton>
                        </EmptyState>
                    </div>
                </div>

                <!-- Qué puede hacer cada rol, a la vista: evita asignar permisos de memoria. -->
                <div :class="cardClass" class="p-4">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Roles</h3>

                    <dl class="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div v-for="option in roleOptions" :key="option.value">
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                {{ option.label }}
                            </dt>
                            <dd class="mt-0.5 text-sm text-gray-600 dark:text-gray-300">
                                {{ option.description }}
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>
        </div>

        <Modal :show="showCreate" max-width="lg" @close="closeCreate">
            <form class="bg-white p-6 dark:bg-gray-800" @submit.prevent="submitCreate">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Nuevo usuario</h2>

                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    La cuenta se crea dentro de {{ tenant.name }} y sólo ve los datos de este tenant.
                </p>

                <div class="mt-4 space-y-4">
                    <div>
                        <InputLabel for="user-name" value="Nombre" class="dark:text-gray-300" />
                        <TextInput
                            id="user-name"
                            v-model="form.name"
                            type="text"
                            autocomplete="off"
                            class="mt-1 block w-full dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200"
                        />
                        <InputError :message="form.errors.name" class="mt-1" />
                    </div>

                    <div>
                        <InputLabel for="user-email" value="Email" class="dark:text-gray-300" />
                        <TextInput
                            id="user-email"
                            v-model="form.email"
                            type="email"
                            autocomplete="off"
                            class="mt-1 block w-full dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200"
                        />
                        <InputError :message="form.errors.email" class="mt-1" />
                    </div>

                    <div>
                        <InputLabel for="user-role" value="Rol" class="dark:text-gray-300" />
                        <SelectInput
                            id="user-role"
                            v-model="form.role"
                            :options="roleChoices"
                            class="mt-1 block w-full"
                        />
                        <p
                            v-if="roleOptions.find((option) => option.value === form.role)?.description"
                            class="mt-1 text-xs text-gray-500 dark:text-gray-400"
                        >
                            {{ roleOptions.find((option) => option.value === form.role).description }}
                        </p>
                        <InputError :message="form.errors.role" class="mt-1" />
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel for="user-password" value="Contraseña" class="dark:text-gray-300" />
                            <TextInput
                                id="user-password"
                                v-model="form.password"
                                type="password"
                                autocomplete="new-password"
                                class="mt-1 block w-full dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200"
                            />
                            <InputError :message="form.errors.password" class="mt-1" />
                        </div>

                        <div>
                            <InputLabel
                                for="user-password-confirmation"
                                value="Repetir contraseña"
                                class="dark:text-gray-300"
                            />
                            <TextInput
                                id="user-password-confirmation"
                                v-model="form.password_confirmation"
                                type="password"
                                autocomplete="new-password"
                                class="mt-1 block w-full dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200"
                            />
                        </div>
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <SecondaryButton type="button" @click="closeCreate">Cancelar</SecondaryButton>

                    <PrimaryButton
                        class="disabled:opacity-50"
                        :disabled="form.processing"
                    >
                        {{ form.processing ? 'Creando…' : 'Crear usuario' }}
                    </PrimaryButton>
                </div>
            </form>
        </Modal>
    </AuthenticatedLayout>
</template>
