<?php

declare(strict_types=1);

namespace App\Domain\Outreach\Models;

use App\Domain\Outreach\Enums\IntentState;
use App\Domain\Sales\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $run_id
 * @property string $step_id
 * @property string $recipient_id
 * @property string $customer_id
 * @property IntentState $state
 * @property string $idempotency_key
 * @property Carbon $scheduled_for
 * @property Carbon|null $expires_at
 * @property string|null $claimed_by
 * @property Carbon|null $claim_expires_at
 * @property int $attempts
 * @property string|null $provider_message_id
 * @property-read Collection<int, SendAttempt> $attemptLog
 */
class SendIntent extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'run_id', 'step_id', 'recipient_id', 'customer_id',
        'idempotency_key', 'state', 'scheduled_for', 'expires_at',
        'claimed_by', 'claim_expires_at', 'attempts', 'provider_message_id',
    ];

    protected function casts(): array
    {
        return [
            'state' => IntentState::class,
            'scheduled_for' => 'datetime',
            'expires_at' => 'datetime',
            'claim_expires_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    /** @return BelongsTo<CampaignRecipient, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(CampaignRecipient::class, 'recipient_id');
    }

    /** @return HasMany<SendAttempt, $this> */
    public function attemptLog(): HasMany
    {
        return $this->hasMany(SendAttempt::class, 'intent_id');
    }
}
