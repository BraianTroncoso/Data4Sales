<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Analytics\Models\CustomerMetric;
use App\Domain\Sales\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Un comprador del retailer.
 *
 * Su identidad es (tenant_id, external_id), la clave natural del retailer.
 * El email NO identifica: se indexa para busqueda pero se repite entre
 * clientes distintos, porque en la realidad se repite.
 *
 * Esta clase concentra datos personales. Todo lo que la exponga tiene que
 * pasar por el rol de Customer Support o Administrador.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $external_id
 * @property string|null $name
 * @property string|null $email
 * @property string|null $email_normalized
 * @property string $email_status
 * @property string|null $phone
 * @property string|null $country
 * @property Carbon|null $source_created_at
 * @property Carbon|null $source_updated_at
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 * @property-read CustomerMetric|null $metrics
 * @property-read Collection<int, Order> $orders
 */
class Customer extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id',
        'external_id',
        'name',
        'email',
        'email_normalized',
        'email_status',
        'phone',
        'country',
        'source_created_at',
        'source_updated_at',
        'first_seen_at',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'source_created_at' => 'datetime',
            'source_updated_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** @return HasOne<CustomerMetric, $this> */
    public function metrics(): HasOne
    {
        return $this->hasOne(CustomerMetric::class);
    }

    public function hasContactableEmail(): bool
    {
        return $this->email_status === 'valid';
    }
}
