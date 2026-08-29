<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Sales\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $external_id
 * @property string|null $sku
 * @property string $name
 * @property string|null $category
 * @property Carbon|null $source_updated_at
 */
class Product extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id',
        'external_id',
        'sku',
        'name',
        'category',
        'source_updated_at',
    ];

    protected function casts(): array
    {
        return ['source_updated_at' => 'datetime'];
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
