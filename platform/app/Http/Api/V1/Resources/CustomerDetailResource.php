<?php

declare(strict_types=1);

namespace App\Http\Api\V1\Resources;

use App\Domain\Sales\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Cliente en su vista de DETALLE.
 *
 * Aca si viaja el contacto completo: es la pantalla que usa un agente para
 * atender un caso puntual, y ocultarle el email al que tiene que responder
 * seria seguridad de utileria. La diferencia con el listado es intencional
 * y esta explicada en CustomerResource.
 *
 * @mixin Customer
 */
class CustomerDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'external_id' => $this->external_id,
            'name' => $this->name,
            // El email tal como lo mandó el retailer, no el normalizado: si
            // está mal escrito, el agente necesita ver el error para poder
            // corregirlo con el cliente.
            'email' => $this->email,
            'email_normalized' => $this->email_normalized,
            'email_status' => $this->email_status,
            'phone' => $this->phone,
            'country' => $this->country,
            'first_seen_at' => $this->first_seen_at->toIso8601String(),
            'last_seen_at' => $this->last_seen_at->toIso8601String(),

            'metrics' => $this->whenLoaded('metrics', fn () => [
                'first_order_at' => $this->metrics?->first_order_at?->toIso8601String(),
                'last_order_at' => $this->metrics?->last_order_at?->toIso8601String(),
                'orders_count' => $this->metrics->orders_count ?? 0,
                'net_orders_count' => $this->metrics->net_orders_count ?? 0,
                'returns_count' => $this->metrics->returns_count ?? 0,
                'total_net_reporting_minor' => $this->metrics->total_net_reporting_minor ?? 0,
                'avg_order_reporting_minor' => $this->metrics->avg_order_reporting_minor ?? 0,
                'recency_days' => $this->metrics?->recency_days,
                'segment' => $this->metrics?->segment?->value,
                'segment_label' => $this->metrics?->segment?->label(),
            ]),

            'orders' => OrderResource::collection($this->whenLoaded('orders')),
        ];
    }
}
