<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Models;

use App\Domain\Ingestion\Enums\BatchStatus;
use App\Domain\Ingestion\Enums\EntityType;
use App\Domain\Ingestion\Enums\IngestSource;
use App\Domain\Sales\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Un envio de datos que hizo un retailer.
 *
 * Es la unidad de trabajo y la unidad de rendicion de cuentas: cuando
 * alguien pregunta "que paso con lo que mandamos ayer", la respuesta es una
 * fila de esta tabla, con las cuentas de aceptados, rechazados y duplicados.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $idempotency_key
 * @property BatchStatus $status
 * @property EntityType $entity_type
 * @property IngestSource $source
 * @property string $payload_hash
 * @property int|null $expected_count
 * @property int $received_count
 * @property int $accepted_count
 * @property int $rejected_count
 * @property int $duplicate_count
 * @property Carbon $received_at
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property string|null $failure_reason
 */
class IngestionBatch extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id',
        'idempotency_key',
        'entity_type',
        'source',
        'status',
        'payload_hash',
        'expected_count',
        'received_count',
        'accepted_count',
        'rejected_count',
        'duplicate_count',
        'received_at',
        'started_at',
        'finished_at',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => BatchStatus::class,
            'entity_type' => EntityType::class,
            'source' => IngestSource::class,
            'expected_count' => 'integer',
            'received_count' => 'integer',
            'accepted_count' => 'integer',
            'rejected_count' => 'integer',
            'duplicate_count' => 'integer',
            'received_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return HasMany<RawEvent, $this> */
    public function rawEvents(): HasMany
    {
        return $this->hasMany(RawEvent::class, 'batch_id');
    }

    /** @return HasMany<IngestionReject, $this> */
    public function rejects(): HasMany
    {
        return $this->hasMany(IngestionReject::class, 'batch_id');
    }

    /**
     * Si llegaron menos registros de los anunciados.
     *
     * Es la definicion de envio interrumpido. Se evalua al finalizar el
     * procesamiento y no al recibir, porque marcar el estado en el momento
     * de la recepcion competiria con el trabajo que lo esta procesando y el
     * ultimo en escribir ganaria por azar.
     */
    public function arrivedIncomplete(): bool
    {
        return $this->expected_count !== null
            && $this->expected_count > $this->received_count;
    }

    /**
     * Cuantos registros quedaron sin procesar.
     *
     * Distinto de cero con el lote terminado significa que el envio se corto
     * a la mitad. Es lo que distingue `partial` de `completed`, y lo que se
     * puede reintentar sin duplicar.
     */
    public function unprocessedCount(): int
    {
        return max(0, $this->received_count
            - $this->accepted_count
            - $this->rejected_count
            - $this->duplicate_count);
    }
}
