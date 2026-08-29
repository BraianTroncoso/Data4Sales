<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Models;

use App\Domain\Sales\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Un dia cuyos agregados quedaron desactualizados y hay que recomputar.
 *
 * Esta tabla ES la respuesta a "llega una orden de hace 60 dias, que queda
 * mal y por cuanto tiempo". Queda mal ese dia, y el tiempo se mide mirando
 * la fila pendiente mas antigua.
 *
 * @property int $id
 * @property string $tenant_id
 * @property Carbon $date
 * @property string $reason
 * @property Carbon $created_at
 * @property Carbon|null $processed_at
 */
class AggregateDirtyDay extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    /** Por que se ensucio un dia. Sirve para medir cuanto reproceso genera cada causa. */
    public const REASON_NEW_ORDER = 'new_order';

    public const REASON_STATUS_CHANGE = 'status_change';

    public const REASON_LATE_ARRIVAL = 'late_arrival';

    protected $fillable = [
        'tenant_id',
        'date',
        'reason',
        'created_at',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'created_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
