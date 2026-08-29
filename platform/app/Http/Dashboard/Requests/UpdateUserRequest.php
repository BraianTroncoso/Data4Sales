<?php

declare(strict_types=1);

namespace App\Http\Dashboard\Requests;

use App\Domain\Identity\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cambio de rol o de estado de un usuario.
 *
 * No permite cambiar el email: sería un cambio de identidad, y con el índice
 * (tenant_id, email) hay que tratarlo como tal, no como una edición de perfil.
 */
class UpdateUserRequest extends FormRequest
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
        return [
            'role' => ['sometimes', Rule::enum(UserRole::class)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
