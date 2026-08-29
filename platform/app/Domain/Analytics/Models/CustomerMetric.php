<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Models;

use App\Domain\Analytics\Enums\RfmSegment;
use App\Domain\Sales\Concerns\BelongsToTenant;
use App\Domain\Sales\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Metricas derivadas por cliente.
 *
 * Sirve a dos consumidores: la segmentacion del dashboard (Parte 2) y la
 * resolucion de audiencias del motor de acciones (Parte 3). Que exista es lo
 * que permite que una condicion como "compro mas de tres veces en los
 * ultimos 90 dias con ticket promedio arriba de X" no tenga que recorrer el
 * historial de ordenes de cada cliente en tiempo de consulta.
 *
 * @property string $customer_id
 * @property string $tenant_id
 * @property Carbon|null $first_order_at
 * @property Carbon|null $last_order_at
 * @property int $orders_count
 * @property int $net_orders_count
 * @property int $returns_count
 * @property int $total_net_reporting_minor
 * @property int $avg_order_reporting_minor
 * @property int|null $recency_days
 * @property int|null $r_score
 * @property int|null $f_score
 * @property int|null $m_score
 * @property RfmSegment|null $segment
 * @property Carbon $computed_at
 */
class CustomerMetric extends Model
{
    use BelongsToTenant;

    protected $primaryKey = 'customer_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'customer_id',
        'tenant_id',
        'first_order_at',
        'last_order_at',
        'orders_count',
        'net_orders_count',
        'returns_count',
        'total_net_reporting_minor',
        'avg_order_reporting_minor',
        'recency_days',
        'r_score',
        'f_score',
        'm_score',
        'segment',
        'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'segment' => RfmSegment::class,
            'first_order_at' => 'datetime',
            'last_order_at' => 'datetime',
            'orders_count' => 'integer',
            'net_orders_count' => 'integer',
            'returns_count' => 'integer',
            'total_net_reporting_minor' => 'integer',
            'avg_order_reporting_minor' => 'integer',
            'recency_days' => 'integer',
            'r_score' => 'integer',
            'f_score' => 'integer',
            'm_score' => 'integer',
            'computed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
