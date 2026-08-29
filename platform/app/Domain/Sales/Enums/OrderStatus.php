<?php

declare(strict_types=1);

namespace App\Domain\Sales\Enums;

/**
 * Ciclo de vida de una orden.
 *
 * La ingesta recibe actualizaciones, no solo altas, asi que estos valores
 * llegan desordenados y repetidos. Que la transicion sea "valida" no se
 * usa para rechazar: un retailer puede tener flujos que no imaginamos, y
 * rechazar por eso perderia datos reales. Se usa para detectar anomalias y
 * dejarlas registradas en el historial.
 */
enum OrderStatus: string
{
    case Created = 'created';
    case Paid = 'paid';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
    case Returned = 'returned';

    /**
     * Estados que NO cuentan como venta neta.
     *
     * Es la definicion de "ventas de verdad" que espera ver un gerente, y la
     * que usa el calculo de agregados.
     */
    public function countsAsNetRevenue(): bool
    {
        return ! in_array($this, [self::Cancelled, self::Returned], strict: true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Delivered, self::Cancelled, self::Returned], strict: true);
    }

    /**
     * Sinonimos que mandan los retailers en la vida real. Normalizar aca
     * evita rechazar una orden perfectamente valida por decir "complete"
     * en vez de "delivered".
     */
    public static function fromLoose(string $value): ?self
    {
        $normalized = strtolower(trim($value));

        return match ($normalized) {
            'created', 'new', 'pending', 'open' => self::Created,
            'paid', 'payment_received', 'confirmed' => self::Paid,
            'shipped', 'dispatched', 'in_transit' => self::Shipped,
            'delivered', 'complete', 'completed', 'fulfilled' => self::Delivered,
            'cancelled', 'canceled', 'void' => self::Cancelled,
            'returned', 'refunded', 'return' => self::Returned,
            default => null,
        };
    }
}
