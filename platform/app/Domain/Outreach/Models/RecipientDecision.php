<?php

declare(strict_types=1);

namespace App\Domain\Outreach\Models;

use App\Domain\Outreach\Enums\DecisionReason;
use App\Domain\Sales\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Por que un cliente entro, quedo afuera, o quedo esperando.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $run_id
 * @property string $customer_id
 * @property string $decision
 * @property DecisionReason $reason_code
 * @property string|null $node_id
 * @property array<string, mixed>|null $detail
 * @property Carbon $decided_at
 */
class RecipientDecision extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'run_id', 'customer_id',
        'decision', 'reason_code', 'node_id', 'detail', 'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'reason_code' => DecisionReason::class,
            'detail' => 'array',
            'decided_at' => 'datetime',
        ];
    }
}
