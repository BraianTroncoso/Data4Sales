<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * Props compartidas con todas las páginas.
 *
 * Acá viajan las CAPACIDADES ya resueltas, no el rol crudo. Es deliberado:
 * si el frontend recibiera `role: 'analyst'` tendría que reimplementar la
 * regla de qué puede hacer cada rol, y esa regla existiría en dos lugares
 * que van a divergir. Mandando `can.statistics` el menú refleja exactamente
 * lo que el servidor va a permitir.
 *
 * Y para que quede claro: esto es para dibujar la interfaz. El control de
 * acceso real está en el middleware, del lado del servidor.
 */
class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $context = app(TenantContext::class);

        return [
            ...parent::share($request),

            'auth' => [
                'user' => $user === null ? null : [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role->value,
                    'role_label' => $user->role->label(),
                ],
                'can' => [
                    'statistics' => $user?->canViewStatistics() ?? false,
                    'customers' => $user?->canViewCustomerData() ?? false,
                    'users' => $user?->canManageUsers() ?? false,
                ],
            ],

            // La organización activa. Que esté siempre visible en la interfaz
            // no es adorno: en una plataforma multi-tenant, no saber en cuál
            // estás parado es cómo alguien termina tomando una decisión con
            // los datos del retailer equivocado.
            'tenant' => $context->hasTenant() ? [
                'name' => $context->current()->name,
                'slug' => $context->current()->slug,
                'currency' => $context->current()->reporting_currency,
                'timezone' => $context->current()->timezone,
            ] : null,

            'flash' => [
                'status' => fn () => $request->session()->get('status'),
            ],
        ];
    }
}
