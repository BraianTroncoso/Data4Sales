<?php

declare(strict_types=1);

namespace App\Domain\Outreach\Rendering;

/**
 * Los datos de UN destinatario, y nada mas.
 *
 * Es un objeto cerrado a proposito, construido por destinatario y descartado
 * despues de renderizar. El riesgo real de un motor de plantillas no es que
 * alguien escriba una variable inexistente: es que el contexto de un
 * destinatario sobreviva al siguiente y una persona reciba un correo
 * perfectamente formado con los datos de otra. Un objeto inmutable por
 * mensaje hace que ese estado compartido no exista.
 */
final readonly class RecipientContext
{
    /**
     * @param  array<string, scalar|null>  $variables
     */
    private function __construct(
        public string $customerId,
        public string $email,
        public array $variables,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            customerId: (string) $row['customer_id'],
            email: (string) $row['email'],
            variables: [
                'nombre' => self::firstName($row['name'] ?? null),
                'nombre_completo' => $row['name'] ?? null,
                'ordenes' => (int) ($row['orders_count'] ?? 0),
                'ticket_promedio' => (int) ($row['avg_order_minor'] ?? 0),
                'dias_sin_comprar' => $row['recency_days'] ?? null,
            ],
        );
    }

    private static function firstName(?string $full): ?string
    {
        if ($full === null || trim($full) === '') {
            return null;
        }

        return explode(' ', trim($full))[0];
    }
}
