<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\UserRole;
use App\Domain\Tenancy\Models\Tenant;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * Usuario del dashboard: un empleado del retailer, no un comprador.
 *
 * Vive en el plano de control porque la autenticacion tiene que resolverse
 * antes de saber a que base de datos ir.
 *
 * Dos propiedades hacen todo el trabajo de seguridad de la Parte 2:
 * `tenant_id` dice de quien son los datos que puede ver, y `role` dice
 * cuales. Ninguna de las dos se pide nunca al cliente: salen de la sesion.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $email
 * @property UserRole $role
 * @property bool $is_active
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property-read Tenant|null $tenant
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable;

    protected $connection = 'system';

    protected $fillable = [
        'tenant_id',
        'name',
        'email',
        'password',
        'role',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function canViewStatistics(): bool
    {
        return $this->is_active && $this->role->canViewStatistics();
    }

    public function canViewCustomerData(): bool
    {
        return $this->is_active && $this->role->canViewCustomerData();
    }

    public function canManageUsers(): bool
    {
        return $this->is_active && $this->role->canManageUsers();
    }
}
