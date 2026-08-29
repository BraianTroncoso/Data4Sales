<?php

declare(strict_types=1);

namespace App\Domain\Emitter;

use Carbon\CarbonImmutable;

/**
 * Perfil de un retailer a simular.
 *
 * Los tres tenants del seed tienen perfiles deliberadamente distintos, y no
 * solo en volumen: distinta zona horaria, distinta moneda principal y
 * distinta moneda secundaria. Si los tres fueran iguales salvo el tamano, el
 * manejo de zonas y de conversion quedaria como codigo que nunca se ejecuta.
 */
final readonly class DatasetProfile
{
    public function __construct(
        public string $slug,
        public string $timezone,
        public string $currency,
        public string $secondaryCurrency,
        public string $country,
        public int $customers,
        public int $products,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
    ) {}

    /**
     * Perfiles predefinidos de los tres tenants del seed.
     *
     * El rango es de cuatro meses: el enunciado pide un mínimo de tres, y el
     * margen extra hace que los bordes del rango no coincidan con los bordes
     * del dataset, que es donde suelen esconderse los errores de fecha.
     */
    public static function forTenant(string $slug, ?CarbonImmutable $today = null): self
    {
        $to = ($today ?? CarbonImmutable::now())->startOfDay();
        $from = $to->subMonths(4);

        return match ($slug) {
            'luna' => new self('luna', 'America/Argentina/Buenos_Aires', 'ARS', 'USD', 'AR', 400, 120, $from, $to),
            'andes' => new self('andes', 'America/Santiago', 'USD', 'CLP', 'CL', 12000, 600, $from, $to),
            'mega' => new self('mega', 'Europe/Madrid', 'EUR', 'GBP', 'ES', 150000, 3000, $from, $to),
            default => new self($slug, 'UTC', 'USD', 'EUR', 'US', 500, 100, $from, $to),
        };
    }
}
