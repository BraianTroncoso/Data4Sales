<?php

declare(strict_types=1);

namespace App\Http\Api\V1\Controllers;

use App\Domain\Ingestion\Actions\ReceiveBatch;
use App\Domain\Ingestion\Enums\EntityType;
use App\Domain\Ingestion\Enums\RejectReason;
use App\Domain\Ingestion\Models\IngestionBatch;
use App\Http\Api\V1\Requests\IngestBatchRequest;
use App\Http\Api\V1\Resources\BatchResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Punto de entrada de datos al sistema. El unico.
 *
 * Responde 202 y no 201 a proposito: el lote quedo guardado y aceptado para
 * procesar, pero todavia no esta reflejado en las tablas de negocio.
 * Devolver 201 seria mentir sobre el estado del sistema, y el retailer que
 * consulte inmediatamente despues encontraria numeros que no cierran.
 */
class IngestController extends Controller
{
    public function __construct(
        private readonly ReceiveBatch $receiveBatch,
    ) {}

    /**
     * Recibe un lote de registros de un tipo de entidad.
     */
    public function store(IngestBatchRequest $request, string $entity): JsonResponse
    {
        $entityType = EntityType::tryFrom($entity);

        if ($entityType === null) {
            return new JsonResponse([
                'error' => [
                    'code' => 'unknown_entity',
                    'message' => "Tipo de entidad [{$entity}] desconocido.",
                    'supported' => array_column(EntityType::cases(), 'value'),
                ],
            ], 404);
        }

        $records = $request->records();

        $batch = $this->receiveBatch->execute(
            entityType: $entityType,
            source: $request->source(),
            records: $records,
            idempotencyKey: $request->idempotencyKey(),
            expectedCount: $request->expectedCount(),
        );

        return (new BatchResource($batch))
            ->response()
            ->setStatusCode(202);
    }

    public function show(string $batch): JsonResponse
    {
        $model = IngestionBatch::query()->find($batch);

        if ($model === null) {
            // Un lote de otro tenant es indistinguible de uno inexistente,
            // porque Row-Level Security lo hace invisible antes de que este
            // codigo lo vea. La respuesta no filtra que existe.
            return $this->notFound();
        }

        return (new BatchResource($model))->response();
    }

    /**
     * Detalle de lo que se rechazo y por que.
     *
     * Existe porque un contador de rechazos sin el detalle obliga al
     * retailer a adivinar. Agrupar por motivo convierte "300 registros
     * fallaron" en "300 registros con la fecha en un formato que no
     * interpretamos", que es accionable.
     */
    public function rejects(Request $request, string $batch): JsonResponse
    {
        $model = IngestionBatch::query()->find($batch);

        if ($model === null) {
            return $this->notFound();
        }

        // `toBase()` a proposito: estas filas son tuplas de agregacion, no
        // entidades. Traerlas como modelos obligaria a fingir que
        // IngestionReject tiene una columna `total`, que no tiene, y a que
        // Eloquent hidrate objetos completos para descartarlos enseguida.
        $summary = $model->rejects()
            ->toBase()
            ->selectRaw('reason, count(*) as total')
            ->groupBy('reason')
            ->orderByDesc('total')
            ->get()
            ->map(function (object $row): array {
                $reason = RejectReason::from((string) $row->reason);

                return [
                    'reason' => $reason->value,
                    'total' => (int) $row->total,
                    'what_arrived' => $reason->describesDirt(),
                    'what_we_did' => $reason->describesPolicy(),
                ];
            });

        $samples = $model->rejects()
            ->latest('id')
            ->limit(50)
            ->get(['reason', 'field', 'message', 'source_id'])
            ->map(fn ($row) => [
                'reason' => $row->reason->value,
                'field' => $row->field,
                'source_id' => $row->source_id,
                'message' => $row->message,
            ]);

        return new JsonResponse([
            'batch_id' => $model->id,
            'summary' => $summary,
            'samples' => $samples,
        ]);
    }

    private function notFound(): JsonResponse
    {
        return new JsonResponse([
            'error' => ['code' => 'not_found', 'message' => 'El lote no existe.'],
        ], 404);
    }
}
