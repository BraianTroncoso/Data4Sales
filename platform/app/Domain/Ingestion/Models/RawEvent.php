<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Models;

use App\Domain\Ingestion\Enums\EntityType;
use App\Domain\Sales\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un registro tal cual llego, sin interpretar.
 *
 * Append-only. Es la fuente de verdad para responder "de donde salio este
 * dato" y para poder reprocesar si manana cambian las reglas de limpieza.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $batch_id
 * @property EntityType $entity_type
 * @property string|null $source_id
 * @property array<string, mixed> $payload
 * @property string $content_hash
 * @property string|null $outcome
 * @property Carbon $received_at
 * @property Carbon|null $processed_at
 */
class RawEvent extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'batch_id',
        'entity_type',
        'source_id',
        'payload',
        'content_hash',
        'outcome',
        'received_at',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'entity_type' => EntityType::class,
            'payload' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<IngestionBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(IngestionBatch::class, 'batch_id');
    }
}
