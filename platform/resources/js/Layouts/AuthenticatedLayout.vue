<script setup>
import { ref, computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import NavLink from '@/Components/NavLink.vue';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink.vue';

const page = usePage();

const showingNavigationDropdown = ref(false);

const user = computed(() => page.props.auth?.user ?? null);
const tenant = computed(() => page.props.tenant ?? null);

/*
 * El menú se dibuja a partir de las capacidades que ya resolvió el servidor,
 * no del rol. Si acá se decidiera "analyst ve estadísticas", esa regla
 * viviría en dos lugares y con el tiempo se separarían.
 *
 * Y esto es sólo la interfaz: ocultar un enlace no impide nada. El acceso lo
 * corta el middleware, del lado del servidor. Alguien que escriba la URL a
 * mano recibe un 403, no la pantalla.
 */
const links = computed(() => {
    const can = page.props.auth?.can ?? {};

    return [
        { key: 'statistics', label: 'Estadísticas', route: 'dashboard.statistics', pattern: 'dashboard.statistics', show: can.statistics },
        { key: 'customers', label: 'Clientes', route: 'dashboard.customers.index', pattern: 'dashboard.customers.*', show: can.customers },
        { key: 'users', label: 'Usuarios', route: 'dashboard.users.index', pattern: 'dashboard.users.*', show: can.users },
    ].filter((link) => link.show);
});

const flash = computed(() => page.props.flash?.status ?? null);
</script>

<template>
    <div class="min-h-screen bg-canvas">
        <nav
            class="border-b border-line bg-surface"
        >
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="flex h-16 justify-between">
                    <div class="flex">
                        <div class="flex shrink-0 items-center gap-3">
                            <Link :href="route('dashboard')">
                                <!--
                                    Sólo tamaño. El símbolo de marca trae sus
                                    propios colores; heredar el color de texto
                                    del contenedor lo teñía de gris.
                                -->
                                <ApplicationLogo class="block h-9 w-auto" />
                            </Link>

                            <!--
                                La organización activa, siempre a la vista. En
                                multi-tenant, no saber en cuál estás parado es
                                cómo alguien decide con los datos del retailer
                                equivocado.
                            -->
                            <span
                                v-if="tenant"
                                class="hidden items-center gap-1.5 rounded-chip border border-line bg-canvas px-2.5 py-1 text-xs font-medium text-ink-soft sm:inline-flex"
                                :title="`Zona horaria ${tenant.timezone} · moneda de reporte ${tenant.currency}`"
                            >
                                {{ tenant.name }}
                                <span class="text-ink-muted"
                                    >· {{ tenant.currency }}</span
                                >
                            </span>
                        </div>

                        <div class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex">
                            <NavLink
                                v-for="link in links"
                                :key="link.key"
                                :href="route(link.route)"
                                :active="route().current(link.pattern)"
                            >
                                {{ link.label }}
                            </NavLink>
                        </div>
                    </div>

                    <div class="hidden sm:ms-6 sm:flex sm:items-center">
                        <Dropdown v-if="user" align="right" width="48">
                            <template #trigger>
                                <span class="inline-flex rounded-field">
                                    <!--
                                        El anillo de foco compartido reemplaza
                                        al outline anulado sin reemplazo: el
                                        disparador del menú no tenía ningún
                                        indicador de foco visible.
                                    -->
                                    <button
                                        type="button"
                                        class="focus-ring inline-flex items-center rounded-field border border-transparent bg-surface px-3 py-2 text-sm font-medium leading-4 text-ink-muted transition duration-150 ease-in-out hover:text-ink"
                                    >
                                        <span class="text-start">
                                            {{ user.name }}
                                            <span
                                                class="block text-xs text-ink-muted"
                                                >{{ user.role_label }}</span
                                            >
                                        </span>

                                        <svg
                                            class="-me-0.5 ms-2 h-4 w-4"
                                            xmlns="http://www.w3.org/2000/svg"
                                            viewBox="0 0 20 20"
                                            fill="currentColor"
                                        >
                                            <path
                                                fill-rule="evenodd"
                                                d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                                clip-rule="evenodd"
                                            />
                                        </svg>
                                    </button>
                                </span>
                            </template>

                            <template #content>
                                <DropdownLink :href="route('profile.edit')">
                                    Mi perfil
                                </DropdownLink>
                                <DropdownLink
                                    :href="route('logout')"
                                    method="post"
                                    as="button"
                                >
                                    Cerrar sesión
                                </DropdownLink>
                            </template>
                        </Dropdown>
                    </div>

                    <div class="-me-2 flex items-center sm:hidden">
                        <button
                            type="button"
                            class="focus-ring inline-flex items-center justify-center rounded-field p-2 text-ink-muted transition duration-150 ease-in-out hover:bg-raised hover:text-ink focus:bg-raised focus:text-ink"
                            @click="
                                showingNavigationDropdown =
                                    !showingNavigationDropdown
                            "
                        >
                            <svg
                                class="h-6 w-6"
                                stroke="currentColor"
                                fill="none"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    :class="{
                                        hidden: showingNavigationDropdown,
                                        'inline-flex': !showingNavigationDropdown,
                                    }"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M4 6h16M4 12h16M4 18h16"
                                />
                                <path
                                    :class="{
                                        hidden: !showingNavigationDropdown,
                                        'inline-flex': showingNavigationDropdown,
                                    }"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M6 18L18 6M6 6l12 12"
                                />
                            </svg>
                        </button>
                    </div>
                </div>
            </div>

            <div
                :class="{
                    block: showingNavigationDropdown,
                    hidden: !showingNavigationDropdown,
                }"
                class="sm:hidden"
            >
                <div class="space-y-1 pb-3 pt-2">
                    <ResponsiveNavLink
                        v-for="link in links"
                        :key="link.key"
                        :href="route(link.route)"
                        :active="route().current(link.pattern)"
                    >
                        {{ link.label }}
                    </ResponsiveNavLink>
                </div>

                <div
                    v-if="user"
                    class="border-t border-line pb-1 pt-4"
                >
                    <div class="px-4">
                        <div
                            class="text-base font-medium text-ink"
                        >
                            {{ user.name }}
                        </div>
                        <div class="text-sm font-medium text-ink-muted">
                            {{ user.email }} · {{ user.role_label }}
                        </div>
                        <div
                            v-if="tenant"
                            class="mt-1 text-xs font-medium text-ink-muted"
                        >
                            {{ tenant.name }}
                        </div>
                    </div>

                    <div class="mt-3 space-y-1">
                        <ResponsiveNavLink :href="route('profile.edit')">
                            Mi perfil
                        </ResponsiveNavLink>
                        <ResponsiveNavLink
                            :href="route('logout')"
                            method="post"
                            as="button"
                        >
                            Cerrar sesión
                        </ResponsiveNavLink>
                    </div>
                </div>
            </div>
        </nav>

        <header
            v-if="$slots.header"
            class="bg-surface shadow-card"
        >
            <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                <slot name="header" />
            </div>
        </header>

        <div
            v-if="flash"
            class="mx-auto max-w-7xl px-4 pt-4 sm:px-6 lg:px-8"
        >
            <div
                class="rounded-card border border-success-100 bg-success-50 px-4 py-2 text-sm text-success-700"
            >
                {{ flash }}
            </div>
        </div>

        <main>
            <slot />
        </main>
    </div>
</template>
