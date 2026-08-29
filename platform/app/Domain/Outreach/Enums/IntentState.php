<?php

declare(strict_types=1);

namespace App\Domain\Outreach\Enums;

/**
 * Estado de la intencion de mandarle un mensaje a alguien.
 *
 * La distincion entre `Claimed` e `InFlight` es toda la garantia de entrega
 * del sistema, y es la que se pierde cuando alguien "simplifica" la maquina
 * de estados:
 *
 *   Claimed     un worker lo tomo, todavia no llamo al proveedor. Si el
 *               proceso muere aca, devolverlo a la cola es seguro: nadie
 *               recibio nada.
 *   InFlight    ya se llamo al proveedor y no sabemos como termino. Reintentar
 *               puede duplicar. El recolector lo marca `Unresolved` para que
 *               una persona lo mire, en vez de decidir por su cuenta.
 *
 * Con un solo estado hay que elegir entre perder mensajes o duplicarlos, sin
 * forma de saber cual de las dos cosas paso.
 */
enum IntentState: string
{
    case Queued = 'queued';
    case Claimed = 'claimed';
    case InFlight = 'in_flight';
    case Sent = 'sent';
    case Failed = 'failed';
    case Suppressed = 'suppressed';
    case Expired = 'expired';
    case Unresolved = 'unresolved';
    case Cancelled = 'cancelled';

    /** Estados desde los que todavia se puede intentar enviar. */
    public function isPending(): bool
    {
        return in_array($this, [self::Queued, self::Claimed], strict: true);
    }

    public function isTerminal(): bool
    {
        return ! $this->isPending() && $this !== self::InFlight;
    }
}
