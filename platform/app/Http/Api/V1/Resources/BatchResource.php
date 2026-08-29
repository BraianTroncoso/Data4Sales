<?php

declare(strict_types=1);

namespace App\Http\Api\V1\Resources;

use App\Domain\Ingestion\Models\IngestionBatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Estado de un lote, tal como lo ve el retailer.
 *
 * Es el contrato que permite que "ingerido" y "aceptado" no se confundan.
 * Con ingesta asincronica hay una ventana entre las dos cosas, y exponer las
 * cuentas es lo que la hace observable en vez de misteriosa.
 *
 * @mixin IngestionBatch
 */
class BatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'batch_id' => $this->id,
            'status' => $this->status->value,
            'entity_type' => $this->entity_type->value,
            'source' => $this->source->value,
            'counts' => [
                'expected' => $this->expected_count,
                'received' => $this->received_count,
                'accepted' => $this->accepted_count,
                'rejected' => $this->rejected_count,
                'duplicate' => $this->duplicate_count,
                'pending' => $this->unprocessedCount(),
            ],
            'received_at' => $this->received_at->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'failure_reason' => $this->failure_reason,
            'links' => [
                'self' => route('api.v1.ingest.batches.show', ['batch' => $this->id]),
                'rejects' => route('api.v1.ingest.batches.rejects', ['batch' => $this->id]),
            ],
        ];
    }
}
