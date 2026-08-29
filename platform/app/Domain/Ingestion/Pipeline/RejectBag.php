<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Pipeline;

use App\Domain\Ingestion\Enums\RejectReason;

/**
 * Acumula los problemas encontrados al normalizar un registro.
 *
 * Existe para que el normalizador pueda seguir revisando despues de
 * encontrar el primer error. Cortar en el primero significaria que un
 * retailer con dos campos mal arregla uno, reenvia, y recien ahi se entera
 * del otro. Con la bolsa, el reporte del lote dice todo lo que hay que
 * corregir de una vez.
 *
 * La distincion clave es entre un rechazo que DESCARTA el registro y uno
 * que solo lo anota. Un email invalido no puede costar una venta.
 */
final class RejectBag
{
    /** @var list<array{reason: RejectReason, field: string|null, message: string, payload: mixed}> */
    private array $rejects = [];

    public function add(RejectReason $reason, string $message, ?string $field = null, mixed $payload = null): void
    {
        $this->rejects[] = [
            'reason' => $reason,
            'field' => $field,
            'message' => $message,
            'payload' => $payload,
        ];
    }

    /**
     * Si algo de lo encontrado obliga a descartar el registro entero.
     */
    public function discardsRecord(): bool
    {
        foreach ($this->rejects as $reject) {
            if ($reject['reason']->discardsRecord()) {
                return true;
            }
        }

        return false;
    }

    public function isEmpty(): bool
    {
        return $this->rejects === [];
    }

    /** @return list<array{reason: RejectReason, field: string|null, message: string, payload: mixed}> */
    public function all(): array
    {
        return $this->rejects;
    }
}
