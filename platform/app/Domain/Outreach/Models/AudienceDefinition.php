<?php

declare(strict_types=1);

namespace App\Domain\Outreach\Models;

use App\Domain\Sales\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Una condicion guardada, con su identidad estable.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property array<string, mixed> $dsl
 * @property string $dsl_hash
 * @property int $schema_version
 */
class AudienceDefinition extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'name', 'dsl', 'dsl_hash', 'schema_version', 'created_by'];

    protected function casts(): array
    {
        return ['dsl' => 'array', 'schema_version' => 'integer'];
    }
}
