<?php

declare(strict_types=1);

namespace App\Http\Api\V1\Resources;

use App\Domain\Sales\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Cliente en un LISTADO.
 *
 * Deliberadamente pobre en datos de contacto: se devuelve el email
 * enmascarado, no el completo, y no se devuelve el telefono. Un listado se
 * pagina, se exporta y se copia; un detalle se abre de a uno para atender un
 * caso concreto. Que el listado no lleve datos de contacto completos hace
 * que una fuga accidental de mil filas no sea una fuga de mil contactos.
 *
 * @mixin Customer
 */
class CustomerResource extends JsonResource
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
            'email_masked' => self::mask($this->email_normalized),
            'email_status' => $this->email_status,
            'country' => $this->country,
            'last_seen_at' => $this->last_seen_at->toIso8601String(),
            'metrics' => $this->whenLoaded('metrics', fn () => [
                'orders_count' => $this->metrics->orders_count ?? 0,
                'net_orders_count' => $this->metrics->net_orders_count ?? 0,
                'total_net_reporting_minor' => $this->metrics->total_net_reporting_minor ?? 0,
                'avg_order_reporting_minor' => $this->metrics->avg_order_reporting_minor ?? 0,
                'recency_days' => $this->metrics?->recency_days,
                'segment' => $this->metrics?->segment?->value,
            ]),
        ];
    }

    /**
     * ana.gonzalez@example.com => an***ez@example.com
     *
     * Suficiente para que un agente confirme que tiene el cliente correcto
     * cuando la persona le dicta su casilla, sin exponer el padron.
     */
    public static function mask(?string $email): ?string
    {
        if ($email === null || ! str_contains($email, '@')) {
            return $email;
        }

        [$local, $domain] = explode('@', $email, 2);

        if (mb_strlen($local) <= 4) {
            return str_repeat('*', mb_strlen($local)).'@'.$domain;
        }

        return mb_substr($local, 0, 2).'***'.mb_substr($local, -2).'@'.$domain;
    }
}
