<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Enums;

/**
 * Que tipo de entidad transporta un lote de ingesta.
 *
 * Las ordenes llegan con su cliente y sus items anidados, porque en el
 * mundo real el retailer manda la venta completa. Los otros dos tipos
 * existen para permitir correcciones de catalogo o de padron sin tener que
 * reenviar ventas.
 */
enum EntityType: string
{
    case Orders = 'orders';
    case Customers = 'customers';
    case Products = 'products';
}
