<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Sales\Concerns\BelongsToTenant;
use App\Domain\Sales\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Una venta.
 *
 * Los tres tiempos que lleva no son redundancia, son tres preguntas
 * distintas:
 *
 *   placed_at          cuando el comprador compro
 *   source_updated_at  cuando el sistema del retailer genero este estado
 *   received_at        cuando el dato nos llego
 *
 * `placed_at` vs `received_at` define si el dato llego tarde.
 * `source_updated_at` es la guarda de version: decide si una actualizacion
 * que llega desordenada debe aplicarse o ignorarse.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $external_id
 * @property string $customer_id
 * @property OrderStatus $status
 * @property string $currency
 * @property int $total_amount_minor
 * @property int $reporting_amount_minor
 * @property numeric-string $fx_rate
 * @property Carbon $placed_at
 * @property Carbon $placed_on
 * @property Carbon $source_updated_at
 * @property Carbon $received_at
 * @property-read Customer|null $customer
 * @property-read Collection<int, OrderItem> $items
 * @property-read Collection<int, OrderStatusHistory> $statusHistory
 */
class Order extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id',
        'external_id',
        'customer_id',
        'status',
        'currency',
        'total_amount_minor',
        'reporting_amount_minor',
        'fx_rate',
        'placed_at',
        'placed_on',
        'source_updated_at',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'total_amount_minor' => 'integer',
            'reporting_amount_minor' => 'integer',
            'fx_rate' => 'decimal:8',
            'placed_at' => 'datetime',
            'placed_on' => 'date',
            'source_updated_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** @return HasMany<OrderStatusHistory, $this> */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('occurred_at');
    }

    /**
     * Cuanto tardo en llegarnos. Es la metrica que hace medible el problema
     * de los datos tardios en vez de dejarlo como anecdota.
     */
    public function arrivalLagInDays(): int
    {
        return (int) $this->placed_at->diffInDays($this->received_at);
    }

    public function scopeNetRevenue(Builder $query): Builder
    {
        return $query->whereNotIn('status', [
            OrderStatus::Cancelled->value,
            OrderStatus::Returned->value,
        ]);
    }

    public function scopePlacedBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('placed_on', [$from, $to]);
    }
}
