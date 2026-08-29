<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Enums;

/**
 * Estado de un lote de ingesta.
 *
 * `Partial` es el estado que da sentido a todo esto: un envio que se corta a
 * la mitad no es un exito ni un fracaso, es un lote con una parte procesada
 * y una parte no. Modelarlo explicitamente es lo que permite reintentar sin
 * duplicar y sin perder.
 */
enum BatchStatus: string
{
    case Received = 'received';
    case Processing = 'processing';
    case Completed = 'completed';
    case Partial = 'partial';
    case Failed = 'failed';

    public function isFinished(): bool
    {
        return in_array($this, [self::Completed, self::Partial, self::Failed], strict: true);
    }
}
