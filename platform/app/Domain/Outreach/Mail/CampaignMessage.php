<?php

declare(strict_types=1);

namespace App\Domain\Outreach\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * Un correo de campana.
 *
 * El contenido llega YA renderizado. Este objeto no toca variables ni sabe
 * nada del destinatario mas alla de lo que recibe: si tuviera acceso al
 * contexto, el mismo riesgo de fuga entre destinatarios que el renderizador
 * evita volveria a aparecer una capa mas abajo.
 *
 * Las cabeceras `List-Unsubscribe` no son cortesia: sin ellas, Gmail y
 * Outlook penalizan la reputacion del dominio emisor, y la gente que no
 * encuentra como darse de baja marca el correo como spam — que es
 * infinitamente peor que una baja.
 */
class CampaignMessage extends Mailable
{
    public function __construct(
        private readonly string $subjectLine,
        private readonly string $textBody,
        private readonly ?string $htmlBody,
        private readonly string $unsubscribeUrl,
        private readonly string $idempotencyKey,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(
            htmlString: $this->htmlBody,
            text: 'mail.campaign-text',
            with: ['body' => $this->textBody, 'unsubscribeUrl' => $this->unsubscribeUrl],
        );
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => "<{$this->unsubscribeUrl}>",
            // Le dice al cliente de correo que puede dar de baja con un POST,
            // sin que la persona tenga que abrir nada.
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            // Viaja al proveedor para poder cruzar un envío con su intento
            // cuando algo sale mal.
            'X-D4S-Intent' => $this->idempotencyKey,
        ]);
    }
}
