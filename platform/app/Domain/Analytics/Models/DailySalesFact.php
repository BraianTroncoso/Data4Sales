<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Models;

use App\Domain\Sales\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Ventas agregadas por dia y moneda.
 *
 * La grilla conserva la moneda original en vez de colapsar todo a una sola,
 * porque perder esa dimension impide auditar el numero contra lo que ve el
 * retailer en su propio sistema.
 *
 * @property int $id
 * @property string $tenant_id
 * @property Carbon $date
 * @property string $currency
 * @property int $orders_count
 * @property int $items_count
 * @property int $cancelled_count
 * @property int $returned_count
 * @property int $gross_amount_minor
 * @property int $reporting_gross_minor
 * @property int $net_amount_minor
 * @property int $reporting_net_minor
 * @property Carbon $computed_at
 */
class DailySalesFact extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'date',
        'currency',
        'orders_count',
        'items_count',
        'cancelled_count',
        'returned_count',
        'gross_amount_minor',
        'reporting_gross_minor',
        'net_amount_minor',
        'reporting_net_minor',
        'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'orders_count' => 'integer',
            'items_count' => 'integer',
            'cancelled_count' => 'integer',
            'returned_count' => 'integer',
            'gross_amount_minor' => 'integer',
            'reporting_gross_minor' => 'integer',
            'net_amount_minor' => 'integer',
            'reporting_net_minor' => 'integer',
            'computed_at' => 'datetime',
        ];
    }
}
