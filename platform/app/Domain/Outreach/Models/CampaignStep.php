<?php

declare(strict_types=1);

namespace App\Domain\Outreach\Models;

use App\Domain\Sales\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $campaign_id
 * @property int $position
 * @property int $wait_before_seconds
 * @property int|null $valid_for_seconds
 * @property string $template_version_id
 * @property-read TemplateVersion $templateVersion
 * @property-read Campaign|null $campaign
 */
class CampaignStep extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'campaign_id', 'position',
        'wait_before_seconds', 'valid_for_seconds', 'template_version_id',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'wait_before_seconds' => 'integer',
            'valid_for_seconds' => 'integer',
        ];
    }

    /** @return BelongsTo<TemplateVersion, $this> */
    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(TemplateVersion::class);
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
