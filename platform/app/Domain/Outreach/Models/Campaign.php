<?php

declare(strict_types=1);

namespace App\Domain\Outreach\Models;

use App\Domain\Sales\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $slug
 * @property string $name
 * @property string $mode
 * @property string $audience_definition_id
 * @property string $control_state
 * @property string $lifecycle_state
 * @property-read AudienceDefinition|null $audienceDefinition
 * @property-read Collection<int, CampaignStep> $steps
 * @property-read Collection<int, CampaignRun> $runs
 */
class Campaign extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'slug', 'name', 'mode',
        'audience_definition_id', 'lifecycle_state', 'control_state',
    ];

    /** @return BelongsTo<AudienceDefinition, $this> */
    public function audienceDefinition(): BelongsTo
    {
        return $this->belongsTo(AudienceDefinition::class);
    }

    /** @return HasMany<CampaignStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(CampaignStep::class)->orderBy('position');
    }

    /** @return HasMany<CampaignRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(CampaignRun::class, 'campaign_id');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * El despachador lee esto en CADA tanda.
     *
     * Pausar es una orden, no un estado de la campana: separarlos es lo que
     * hace que pausar surta efecto en segundos en vez de tener que esperar a
     * que termine lo que ya esta corriendo.
     */
    public function isDispatchAllowed(): bool
    {
        return $this->control_state === 'running';
    }
}
