<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Pipeline;

/**
 * Datos de comprador ya limpios, listos para persistir.
 */
final readonly class NormalizedCustomer
{
    public function __construct(
        public string $externalId,
        public ?string $name,
        public ?string $email,
        public ?string $emailNormalized,
        public string $emailStatus,
        public ?string $phone,
        public ?string $country,
    ) {}
}
