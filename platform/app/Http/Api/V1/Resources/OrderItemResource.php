<?php

declare(strict_types=1);

namespace App\Http\Api\V1\Resources;

use App\Domain\Sales\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OrderItem
 */
class OrderItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product' => $this->whenLoaded('product', fn () => [
                'external_id' => $this->product?->external_id,
                'sku' => $this->product?->sku,
                'name' => $this->product?->name,
                'category' => $this->product?->category,
            ]),
            'quantity' => $this->quantity,
            'unit_amount_minor' => $this->unit_amount_minor,
            'total_amount_minor' => $this->total_amount_minor,
            'currency' => $this->currency,
        ];
    }
}
