<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password = null;

    public function definition(): array
    {
        return [
            // Un usuario sin tenant no tiene sentido en este dominio: no
            // habria datos que pudiera ver. La fabrica crea uno antes que
            // dejar el campo nulo.
            'tenant_id' => Tenant::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => UserRole::Analyst,
            'is_active' => true,
            'email_verified_at' => now(),
            'remember_token' => Str::random(10),
        ];
    }

    public function analyst(): static
    {
        return $this->state(fn () => ['role' => UserRole::Analyst]);
    }

    public function support(): static
    {
        return $this->state(fn () => ['role' => UserRole::Support]);
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => UserRole::Admin]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn () => ['tenant_id' => $tenant->id]);
    }
}
