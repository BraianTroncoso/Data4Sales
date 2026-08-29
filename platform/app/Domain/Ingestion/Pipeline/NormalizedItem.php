<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Pipeline;

/**
 * Renglon de una orden, ya limpio.
 */
final readonly class NormalizedItem
{
    public function __construct(
        public string $productExternalId,
        public ?string $sku,
        public string $name,
        public ?string $category,
        public int $quantity,
        public int $unitAmountMinor,
        public int $totalAmountMinor,
        public string $currency,
        public ?string $externalId,
    ) {}
}
