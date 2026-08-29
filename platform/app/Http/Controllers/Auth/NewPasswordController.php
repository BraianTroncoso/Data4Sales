<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Fijado de la nueva contrasena.
 *
 * La organizacion viaja en el enlace del correo y se reenvia en el formulario.
 * No es redundancia: sin ella, el token se validaria contra el primer usuario
 * que tenga ese email, que puede ser el de otra organizacion.
 */
class NewPasswordController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('Auth/ResetPassword', [
            'email' => $request->string('email')->toString(),
            'tenant' => $request->string('tenant')->toString(),
            'token' => $request->route('token'),
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate(
            [
                'token' => ['required', 'string'],
                'tenant' => ['required', 'string', 'max:64'],
                'email' => ['required', 'string', 'email'],
                'password' => ['required', 'confirmed', Rules\Password::defaults()],
            ],
            attributes: ['tenant' => 'organización'],
        );

        $tenant = Tenant::query()
            ->where('slug', $request->string('tenant')->lower()->toString())
            ->where('is_active', true)
            ->first();

        // Un tenant inexistente y un token vencido dan el mismo error, por la
        // misma razón que en el login.
        $status = $tenant instanceof Tenant
            ? Password::reset(
                [
                    'tenant_id' => $tenant->id,
                    'email' => $request->string('email')->lower()->toString(),
                    'is_active' => true,
                    'password' => $request->string('password')->toString(),
                    'password_confirmation' => $request->string('password_confirmation')->toString(),
                    'token' => $request->string('token')->toString(),
                ],
                function ($user) use ($request) {
                    $user->forceFill([
                        'password' => Hash::make($request->string('password')->toString()),
                        'remember_token' => Str::random(60),
                    ])->save();

                    event(new PasswordReset($user));
                }
            )
            : Password::INVALID_USER;

        if ($status === Password::PASSWORD_RESET) {
            return redirect()->route('login')->with('status', __($status));
        }

        throw ValidationException::withMessages([
            'email' => [trans($status)],
        ]);
    }
}
