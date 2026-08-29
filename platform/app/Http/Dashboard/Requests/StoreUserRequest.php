<?php

declare(strict_types=1);

namespace App\Http\Dashboard\Requests;

use App\Domain\Identity\Enums\UserRole;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Alta de un usuario del dashboard.
 *
 * El `tenant_id` NO está entre las reglas a propósito: no se acepta del
 * cliente en ninguna forma. Sale del contexto de sesión y sólo de ahí. Si
 * fuera un campo del formulario, un administrador podría crear usuarios en
 * otro tenant simplemente cambiándolo.
 */
class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canManageUsers() === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->currentOrFail()->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                // Único DENTRO del tenant, no globalmente: el mismo email
                // puede existir en otra organización sin conflicto.
                Rule::unique('system.users', 'email')->where('tenant_id', $tenantId),
            ],
            'role' => ['required', Rule::enum(UserRole::class)],
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'Ya existe un usuario con ese email en esta organización.',
        ];
    }
}
