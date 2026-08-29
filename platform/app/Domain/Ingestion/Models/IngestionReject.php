<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Models;

use App\Domain\Ingestion\Enums\EntityType;
use App\Domain\Ingestion\Enums\RejectReason;
use App\Domain\Sales\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un registro (o un campo) que no paso la limpieza.
 *
 * El motivo es tipado, no texto libre. Es lo que permite que la tabla de
 * suciedad del README sea una consulta agrupada por `reason` y no una
 * promesa escrita a mano.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $batch_id
 * @property int|null $raw_event_id
 * @property EntityType $entity_type
 * @property string|null $source_id
 * @property RejectReason $reason
 * @property string|null $field
 * @property string $message
 * @property array<string, mixed>|null $payload
 * @property Carbon $created_at
 */
class IngestionReject extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'batch_id',
        'raw_event_id',
        'entity_type',
        'source_id',
        'reason',
        'field',
        'message',
        'payload',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'reason' => RejectReason::class,
            'entity_type' => EntityType::class,
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<IngestionBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(IngestionBatch::class, 'batch_id');
    }
}
