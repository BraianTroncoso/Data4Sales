<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Sales\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Renglon de una orden. Clave entera y no UUID: es la tabla que mas crece
 * (varios por orden, millones de ordenes), y ahi los 8 bytes contra 16 de
 * un UUID, multiplicados por cada indice, se notan.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $order_id
 * @property string $product_id
 * @property string|null $external_id
 * @property int $quantity
 * @property int $unit_amount_minor
 * @property int $total_amount_minor
 * @property string $currency
 * @property-read Order|null $order
 * @property-read Product|null $product
 */
class OrderItem extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'order_id',
        'product_id',
        'external_id',
        'quantity',
        'unit_amount_minor',
        'total_amount_minor',
        'currency',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_amount_minor' => 'integer',
            'total_amount_minor' => 'integer',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
