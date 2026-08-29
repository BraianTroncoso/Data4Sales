<?php

declare(strict_types=1);

namespace App\Domain\Outreach\Dsl;

use App\Domain\Outreach\Dsl\Exceptions\InvalidCondition;
use Carbon\CarbonImmutable;

/**
 * Ventana temporal de una condicion agregada.
 *
 * Se resuelve contra el `as_of` de la ejecucion, NUNCA contra "ahora". Es la
 * diferencia entre una ejecucion reproducible y una que da distinto en cada
 * reintento: si un worker retoma una tanda diez minutos despues, "los ultimos
 * 90 dias" tiene que seguir significando lo mismo que cuando se congelo la
 * audiencia.
 */
final readonly class Window
{
    private function __construct(
        public string $kind,
        public ?int $lastDays,
        public ?CarbonImmutable $from,
        public ?CarbonImmutable $to,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function parse(array $raw, string $path): self
    {
        $kind = $raw['kind'] ?? null;

        if ($kind === 'relative') {
            $days = filter_var($raw['last_days'] ?? null, FILTER_VALIDATE_INT);

            if ($days === false || $days < 1) {
                throw InvalidCondition::malformed('`last_days` tiene que ser un entero positivo.', $path);
            }

            return new self('relative', $days, null, null);
        }

        // La ventana absoluta esta disenada y no implementada. Se rechaza de
        // forma explicita en vez de tratarla como relativa por defecto.
        if ($kind === 'absolute') {
            throw InvalidCondition::unsupportedNode('window.absolute', $path);
        }

        throw InvalidCondition::malformed(
            'La ventana necesita `kind: "relative"` con `last_days`.',
            $path,
        );
    }

    /**
     * Los dos extremos de la ventana, ya materializados contra el instante
     * congelado de la ejecucion.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    public function resolve(CarbonImmutable $asOf): array
    {
        return match ($this->kind) {
            'relative' => [$asOf->subDays($this->lastDays), $asOf],
            default => [$this->from, $this->to],
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'kind' => $this->kind,
            'last_days' => $this->lastDays,
        ], static fn ($v) => $v !== null);
    }
}
