<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Sales\Concerns\BelongsToTenant;
use App\Domain\Sales\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Cada transicion de estado de una orden.
 *
 * No es auditoria decorativa: es lo que permite responder "esta orden estuvo
 * entregada y despues volvio como devolucion", que es exactamente lo que
 * necesitan las condiciones del motor de la Parte 3. Reconstruirlo desde los
 * eventos crudos en cada consulta seria carisimo.
 *
 * Tambien registra las actualizaciones IGNORADAS por llegar desordenadas.
 * Sin eso, "por que esta orden quedo en este estado" no tendria respuesta.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $order_id
 * @property OrderStatus|null $from_status
 * @property OrderStatus $to_status
 * @property Carbon $occurred_at
 * @property Carbon $received_at
 * @property int|null $raw_event_id
 */
class OrderStatusHistory extends Model
{
    use BelongsToTenant;

    protected $table = 'order_status_history';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'order_id',
        'from_status',
        'to_status',
        'occurred_at',
        'received_at',
        'raw_event_id',
    ];

    protected function casts(): array
    {
        return [
            'from_status' => OrderStatus::class,
            'to_status' => OrderStatus::class,
            'occurred_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
