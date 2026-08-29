<?php

declare(strict_types=1);

namespace App\Domain\Outreach\Models;

use App\Domain\Sales\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una ejecucion concreta de una campana.
 *
 * Guarda una COPIA de la condicion, no una referencia. Si alguien edita la
 * definicion despues, esta ejecucion sigue pudiendo explicar contra que se
 * resolvio. Es la diferencia entre auditar y confiar.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $campaign_id
 * @property CarbonImmutable $as_of
 * @property array<string, mixed> $dsl_snapshot
 * @property string $compiler_version
 * @property string $sql_fingerprint
 * @property string $status
 * @property int $audience_size
 * @property int|null $metrics_lag_seconds
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property-read Campaign $campaign
 * @property-read Collection<int, CampaignRecipient> $recipients
 * @property-read Collection<int, SendIntent> $intents
 * @property-read Collection<int, RecipientDecision> $decisions
 */
class CampaignRun extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'campaign_id', 'as_of', 'dsl_snapshot',
        'compiler_version', 'sql_fingerprint', 'status',
        'audience_size', 'metrics_lag_seconds', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            // INMUTABLE a proposito. Con el cast mutable, `as_of->addSeconds()`
            // modifica el instante congelado en vez de derivar uno nuevo, y el
            // segundo calculo que lo usa parte de un valor ya corrido. El
            // sintoma real fue un envio programado tres dias en el futuro con
            // vencimiento en el mismo instante: nada salia, y nada fallaba.
            'as_of' => 'immutable_datetime',
            'dsl_snapshot' => 'array',
            'audience_size' => 'integer',
            'metrics_lag_seconds' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return HasMany<CampaignRecipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class, 'run_id');
    }

    /** @return HasMany<SendIntent, $this> */
    public function intents(): HasMany
    {
        return $this->hasMany(SendIntent::class, 'run_id');
    }

    /** @return HasMany<RecipientDecision, $this> */
    public function decisions(): HasMany
    {
        return $this->hasMany(RecipientDecision::class, 'run_id');
    }
}
