<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Autenticación del dashboard.
 *
 * Pide TRES cosas: slug del tenant, email y contraseña. El email no es un
 * identificador global — el índice único de la tabla es (tenant_id, email),
 * porque la misma persona puede trabajar para dos retailers y dos retailers
 * pueden tener un contacto@ cada uno. Sin el tenant, la búsqueda es
 * ambigua por diseño.
 *
 * Es el mismo modelo que usa Slack con el workspace, y es lo que corresponde
 * en B2B: el tenant es parte de la identidad, no un detalle a inferir.
 */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'tenant' => ['required', 'string', 'max:64'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['tenant' => 'organización'];
    }

    /**
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $tenant = Tenant::query()
            ->where('slug', $this->string('tenant')->lower()->toString())
            ->where('is_active', true)
            ->first();

        // Un tenant inexistente y una contraseña incorrecta dan exactamente el
        // mismo error. Distinguirlos le diría a quien prueba credenciales qué
        // organizaciones existen en la plataforma, que es justo lo que no
        // queremos regalar.
        if ($tenant === null || ! $this->attemptWithin($tenant)) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Intenta autenticar dentro del tenant, y sólo dentro de él.
     *
     * El `tenant_id` va en las credenciales, no en un filtro posterior: si
     * fuera posterior, existiría un instante en el que el usuario de otro
     * tenant quedó autenticado. Acá simplemente no puede autenticarse.
     *
     * `is_active` también va en las credenciales por lo mismo: un usuario
     * desactivado no inicia sesión, no es que inicie y después lo echemos.
     */
    private function attemptWithin(Tenant $tenant): bool
    {
        return Auth::attempt([
            'tenant_id' => $tenant->id,
            'email' => $this->string('email')->lower()->toString(),
            'password' => $this->string('password')->toString(),
            'is_active' => true,
        ], $this->boolean('remember'));
    }

    /**
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * La clave incluye el tenant: un ataque contra una organización no debe
     * bloquear el acceso legítimo de otra que comparte el email.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(
            Str::lower($this->string('tenant').'|'.$this->string('email')).'|'.$this->ip()
        );
    }
}
