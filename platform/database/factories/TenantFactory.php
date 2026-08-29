<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Tenancy\Enums\IsolationStrategy;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'slug' => Str::slug($name),
            'name' => $name,
            'isolation_strategy' => IsolationStrategy::Shared,
            'connection_name' => null,
            'database_name' => null,
            'timezone' => 'UTC',
            'reporting_currency' => 'USD',
            'ingest_max_batch_size' => 5000,
            'is_active' => true,
        ];
    }

    public function dedicated(string $connection, string $database): static
    {
        return $this->state(fn () => [
            'isolation_strategy' => IsolationStrategy::Dedicated,
            'connection_name' => $connection,
            'database_name' => $database,
        ]);
    }
}
