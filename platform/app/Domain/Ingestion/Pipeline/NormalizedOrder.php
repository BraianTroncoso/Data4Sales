<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Pipeline;

use App\Domain\Sales\Enums\OrderStatus;
use Carbon\CarbonImmutable;

/**
 * Una venta ya normalizada: todo entero, todo en UTC, todo validado.
 *
 * A partir de aca el resto del sistema no vuelve a ver una cadena con coma
 * decimal ni una fecha sin zona. Ese es el punto de tener una frontera
 * explicita entre la zona cruda y la curada.
 */
final readonly class NormalizedOrder
{
    /**
     * @param  list<NormalizedItem>  $items
     */
    public function __construct(
        public string $externalId,
        public NormalizedCustomer $customer,
        public OrderStatus $status,
        public string $currency,
        public int $totalAmountMinor,
        public int $reportingAmountMinor,
        public float $fxRate,
        public CarbonImmutable $placedAt,
        public string $placedOn,
        public CarbonImmutable $sourceUpdatedAt,
        public array $items,
    ) {}
}
