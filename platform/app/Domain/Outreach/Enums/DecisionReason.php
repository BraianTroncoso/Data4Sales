<?php

declare(strict_types=1);

namespace App\Domain\Outreach\Enums;

/**
 * Por que un cliente entro, quedo afuera, o quedo esperando.
 *
 * Es lo que hace respondible "por que este cliente recibio, o no recibio,
 * este correo". El enunciado pide tratar eso como requisito de producto, no
 * de infraestructura: por eso los motivos son un enum tipado y no texto
 * libre, y por eso viajan a la respuesta del endpoint tal cual.
 */
enum DecisionReason: string
{
    case MatchedCondition = 'matched_condition';
    case FailedCondition = 'failed_condition';
    case Suppressed = 'suppressed';
    case InvalidEmail = 'invalid_email';
    case DuplicateEmail = 'duplicate_email';
    case Expired = 'expired';

    // El destinatario dejo de existir entre el congelamiento y el envio: un
    // borrado por derecho al olvido, tipicamente. Merece un motivo propio y
    // no un `failed` generico, porque la respuesta a "por que no le llego" es
    // distinta y no admite reintento.
    case RecipientGone = 'recipient_gone';

    public function explain(): string
    {
        return match ($this) {
            self::MatchedCondition => 'Cumple la condición de la audiencia.',
            self::FailedCondition => 'No cumple la condición de la audiencia.',
            self::Suppressed => 'Su dirección está suprimida: se dio de baja o rebotó.',
            self::InvalidEmail => 'No tiene una dirección de correo válida.',
            self::DuplicateEmail => 'Otro cliente con la misma dirección ya estaba en esta audiencia.',
            self::Expired => 'El mensaje venció antes de poder enviarse.',
            self::RecipientGone => 'El destinatario ya no existe: se borró entre el congelamiento y el envío.',
        };
    }
}
