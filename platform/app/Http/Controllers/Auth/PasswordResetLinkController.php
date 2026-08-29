<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pedido del enlace de recuperacion.
 *
 * Pide TRES cosas igual que el login: organizacion, email y nada mas. El email
 * no identifica a nadie por si solo — el unico de `users` es (tenant_id, email)
 * (D-08) — asi que buscar solo por email era ambiguo por diseno y podia
 * mandarle el enlace al usuario equivocado.
 */
class PasswordResetLinkController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword', [
            'status' => session('status'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(
            [
                'tenant' => ['required', 'string', 'max:64'],
                'email' => ['required', 'string', 'email'],
            ],
            attributes: ['tenant' => 'organización'],
        );

        $tenant = Tenant::query()
            ->where('slug', $request->string('tenant')->lower()->toString())
            ->where('is_active', true)
            ->first();

        if ($tenant instanceof Tenant) {
            Password::sendResetLink([
                // Del tenant resuelto, nunca del request: el formulario manda
                // un slug, no un identificador.
                'tenant_id' => $tenant->id,
                'email' => $request->string('email')->lower()->toString(),
                // Un usuario desactivado no recupera acceso por esta puerta.
                'is_active' => true,
            ]);
        }

        /*
         * Siempre la misma respuesta, exista o no la cuenta.
         *
         * Es la misma postura que toma el login (LoginRequest): distinguir
         * "esa organización no existe" de "ese email no está" le regala a
         * quien prueba credenciales el padrón de organizaciones y de usuarios
         * de la plataforma. El andamiaje de Breeze devolvia un error de
         * validación distinto en cada caso.
         */
        return back()->with('status', __(Password::RESET_LINK_SENT));
    }
}
