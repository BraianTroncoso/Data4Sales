<?php

declare(strict_types=1);

namespace App\Http\Api\V1\Resources;

use App\Domain\Sales\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Una orden.
 *
 * Los montos viajan como ENTEROS en unidad mínima, con la moneda al lado.
 * No se formatean acá: el formato es una decisión de presentación que
 * depende del idioma del usuario, y mandarlo ya formateado obligaría a
 * cualquier consumidor que quiera sumar a re-parsear un número que nosotros
 * mismos rompimos.
 *
 * Los tres tiempos viajan por separado porque responden preguntas distintas,
 * y `arrival_lag_days` está expuesto a propósito: es lo que hace visible el
 * problema del dato tardío en la interfaz en vez de dejarlo enterrado.
 *
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'external_id' => $this->external_id,
            'status' => $this->status->value,
            'currency' => $this->currency,
            'total_amount_minor' => $this->total_amount_minor,
            'reporting_amount_minor' => $this->reporting_amount_minor,
            'fx_rate' => (float) $this->fx_rate,
            'placed_at' => $this->placed_at->toIso8601String(),
            'placed_on' => $this->placed_on->format('Y-m-d'),
            // La marca de versión del retailer: es la que decide si una
            // actualización que llega desordenada se aplica o se ignora.
            // Expuesta para que "por qué esta orden quedó así" tenga respuesta
            // desde la pantalla y no sólo desde los logs.
            'source_updated_at' => $this->source_updated_at->toIso8601String(),
            'received_at' => $this->received_at->toIso8601String(),
            'arrival_lag_days' => $this->arrivalLagInDays(),
            'customer_id' => $this->customer_id,
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'status_history' => $this->whenLoaded('statusHistory', fn () => $this->statusHistory->map(fn ($h) => [
                'from' => $h->from_status?->value,
                'to' => $h->to_status->value,
                'occurred_at' => $h->occurred_at->toIso8601String(),
                'received_at' => $h->received_at->toIso8601String(),
            ])),
        ];
    }
}
