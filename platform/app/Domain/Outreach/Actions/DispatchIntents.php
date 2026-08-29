<?php

declare(strict_types=1);

namespace App\Domain\Outreach\Actions;

use App\Domain\Outreach\Enums\DecisionReason;
use App\Domain\Outreach\Enums\IntentState;
use App\Domain\Outreach\Mail\CampaignMessage;
use App\Domain\Outreach\Models\Campaign;
use App\Domain\Outreach\Models\CampaignRun;
use App\Domain\Outreach\Models\CampaignStep;
use App\Domain\Outreach\Rendering\RecipientContext;
use App\Domain\Outreach\Rendering\TemplateRenderer;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Toma intenciones pendientes y las envia.
 *
 * El reclamo con `FOR UPDATE SKIP LOCKED` es la barrera autoritativa contra
 * el doble envio: dos workers no pueden llevarse la misma fila, y el que
 * llega segundo se lleva otras en vez de esperar. Es lo que permite escalar
 * horizontalmente sin coordinacion externa.
 *
 * SOBRE `exactly-once`: no existe. Entre "llamamos al proveedor" y "sabemos
 * que respondio" hay una ventana en la que el proceso puede morir, y ningun
 * mecanismo local puede saber si el correo salio. Lo que si se puede es
 * hacerla VISIBLE: por eso `claimed` e `in_flight` son estados distintos.
 * Un corte deja a lo sumo una intencion en `unresolved`, que una persona
 * puede mirar, en vez de un numero desconocido de duplicados silenciosos.
 */
final class DispatchIntents
{
    /** Cuanto vale un reclamo antes de que el recolector lo devuelva. */
    private const CLAIM_TTL_SECONDS = 300;

    public function __construct(
        private readonly TenantContext $context,
        private readonly TemplateRenderer $renderer,
    ) {}

    /**
     * `paused` forma parte del contrato, no es un extra.
     *
     * Declararlo importa: el llamador decide con esa clave si sigue
     * despachando, y un tipo que la omite hace que el analizador considere esa
     * comparacion siempre falsa. La ruta de pausa queda sin verificar, que es
     * justo la capacidad de "control humano" que pide el enunciado.
     *
     * `unresolved` y `cancelled` tampoco son extras. `failed` afirma que el
     * mensaje NO salio, y hay dos desenlaces que no lo afirman: el proveedor
     * que no contesto (no sabemos) y el destinatario que ya no existe (nunca
     * va a salir). Meterlos en `failed` haria que el resumen de la corrida
     * mienta sobre lo unico que el resumen sirve para saber.
     *
     * @return array{sent: int, suppressed: int, expired: int, failed: int, unresolved: int, cancelled: int, paused: bool}
     */
    public function execute(CampaignRun $run, CampaignStep $step, int $chunkSize = 100, ?string $worker = null): array
    {
        $campaign = $run->campaign;

        // La orden de pausa se lee en CADA tanda, no al empezar. Es lo que
        // hace que pausar surta efecto en segundos.
        if (! $campaign->isDispatchAllowed()) {
            return self::emptyStats(paused: true);
        }

        $worker ??= gethostname().':'.getmypid();
        $intents = $this->claim($run, $step, $chunkSize, $worker);

        $stats = self::emptyStats(paused: false);

        foreach ($intents as $intent) {
            $stats[$this->deliver($run, $campaign, $step, $intent)]++;
        }

        return $stats;
    }

    /**
     * Reclama un lote de intenciones pendientes, de forma atomica.
     *
     * @return list<object>
     */
    private function claim(CampaignRun $run, CampaignStep $step, int $chunkSize, string $worker): array
    {
        return DB::connection($this->connection())->select(
            <<<'SQL'
            UPDATE send_intents
            SET state = 'claimed',
                claimed_by = ?,
                claim_expires_at = NOW() + (? || ' seconds')::interval,
                attempts = attempts + 1,
                updated_at = NOW()
            WHERE id IN (
                SELECT i.id
                FROM send_intents i
                WHERE i.tenant_id = ?
                  AND i.run_id = ?
                  AND i.step_id = ?
                  AND i.state = 'queued'
                  AND i.scheduled_for <= NOW()
                ORDER BY i.id
                LIMIT ?
                FOR UPDATE SKIP LOCKED
            )
            RETURNING id, customer_id, recipient_id, idempotency_key, expires_at
            SQL,
            [
                $worker, self::CLAIM_TTL_SECONDS,
                $this->tenantId(), $run->id, $step->id, $chunkSize,
            ],
        );
    }

    /**
     * @return 'sent'|'suppressed'|'expired'|'failed'|'unresolved'|'cancelled'
     */
    private function deliver(CampaignRun $run, Campaign $campaign, CampaignStep $step, object $intent): string
    {
        // Vencimiento: un "te extrañamos" que sale tres días tarde por una
        // caída del proveedor es peor que uno que no sale.
        if ($intent->expires_at !== null && CarbonImmutable::parse($intent->expires_at)->isPast()) {
            $this->finish($intent->id, IntentState::Expired);
            $this->decide($run, $intent->customer_id, DecisionReason::Expired);

            return 'expired';
        }

        $row = $this->recipientRow($intent->recipient_id);

        // El destinatario pudo desaparecer entre el congelamiento y el envío:
        // un borrado por derecho al olvido, por ejemplo. Antes esto no se
        // miraba, `(array) null` daba `[]` y el acceso a `email_hash` tiraba
        // un TypeError FUERA del try: reventaba `execute()` entero y las otras
        // noventa y nueve intenciones de la tanda quedaban colgadas en
        // `claimed`. Una fila que ya no existe tiene que costar una intención,
        // no la tanda.
        //
        // `cancelled` y no `failed`: no falló nada, simplemente ya no hay a
        // quién escribirle, y reintentarlo nunca va a cambiar.
        if ($row === null) {
            $this->finish($intent->id, IntentState::Cancelled);
            $this->decide($run, $intent->customer_id, DecisionReason::RecipientGone);

            return 'cancelled';
        }

        // SEGUNDO chequeo de supresión, ahora y no al congelar. Entre que se
        // resolvió la audiencia y que sale el correo pueden pasar horas, y
        // alguien puede haberse dado de baja en el medio. Respetar la baja
        // sólo al congelar sería respetarla a medias.
        if ($this->isSuppressed($row['email_hash'])) {
            $this->finish($intent->id, IntentState::Suppressed);
            $this->decide($run, $intent->customer_id, DecisionReason::Suppressed);

            return 'suppressed';
        }

        $attemptNo = $this->openAttempt($intent->id);

        // ── Preparación: todo lo que ocurre ANTES de tocar al proveedor ──
        //
        // Renderizar y armar el mensaje puede fallar (variable no declarada,
        // destinatario sin nombre, plantilla rota). Son fallos SEGUROS: nadie
        // recibió nada, la intención no salió del proceso, y `failed` lo
        // afirma con razón.
        //
        // Está en su propio try a propósito. Cuando un solo try envolvía la
        // preparación y el envío, un timeout de SMTP —que es el caso
        // motivador de D-24, "llamamos y no sabemos qué pasó"— terminaba
        // marcado `failed`, o sea afirmando que el correo NO salió. Eso
        // colapsa la distinción claimed/in_flight justo donde tiene valor: la
        // intención quedaba cerrada como fallida y el duplicado, si el correo
        // había salido, era silencioso.
        try {
            $context = RecipientContext::fromRow($row);
            $content = $this->renderer->render($step->templateVersion, $context);
            $unsubscribeUrl = $this->unsubscribeUrl($campaign, $row['email_hash']);
        } catch (Throwable $e) {
            $this->finish($intent->id, IntentState::Failed);
            $this->closeAttempt($attemptNo, $intent->id, 'failed', $e);

            return 'failed';
        }

        // A partir de acá ya no se puede reintentar sin riesgo de duplicar:
        // el estado lo dice explícitamente.
        $this->markInFlight($intent->id);

        // ── Transporte: a partir de acá la incertidumbre es real ──
        try {
            Mail::to($context->email)->send(new CampaignMessage(
                subjectLine: $content['subject'],
                textBody: $content['text'],
                htmlBody: $content['html'],
                unsubscribeUrl: $unsubscribeUrl,
                idempotencyKey: $intent->idempotency_key,
            ));
        } catch (Throwable $e) {
            // NO se marca `failed`, y no es una omisión: una excepción del
            // transporte no dice que el correo no salió, dice que no sabemos
            // si salió. El servidor pudo haberlo aceptado y cortar la conexión
            // al responder.
            //
            // La intención se deja en `in_flight` con su reclamo vencible, que
            // es exactamente el estado que el recolector convierte en
            // `unresolved` para que lo mire una persona. El intento sí se
            // cierra, con el error: es lo que esa persona va a leer.
            $this->closeAttempt($attemptNo, $intent->id, 'unresolved', $e);

            return 'unresolved';
        }

        $this->finish($intent->id, IntentState::Sent);
        $this->closeAttempt($attemptNo, $intent->id, 'sent');

        return 'sent';
    }

    /**
     * @return array{sent: int, suppressed: int, expired: int, failed: int, unresolved: int, cancelled: int, paused: bool}
     */
    private static function emptyStats(bool $paused): array
    {
        return [
            'sent' => 0, 'suppressed' => 0, 'expired' => 0,
            'failed' => 0, 'unresolved' => 0, 'cancelled' => 0,
            'paused' => $paused,
        ];
    }

    /**
     * Datos del destinatario, o null si la fila ya no está.
     *
     * El tipo dice `?array` y no `array` porque el caso existe: `selectOne`
     * devuelve null cuando el cliente se borró entre el congelamiento y el
     * envío. Castearlo a `(array) null` producía un `[]` que parecía una fila
     * y estallaba recién al leerle una clave, lejos de acá.
     *
     * @return array<string, mixed>|null
     */
    private function recipientRow(string $recipientId): ?array
    {
        $row = DB::connection($this->connection())->selectOne(
            <<<'SQL'
            SELECT r.customer_id,
                   r.email_hash,
                   c.email_normalized AS email,
                   c.name,
                   COALESCE(m.net_orders_count, 0)          AS orders_count,
                   COALESCE(m.avg_order_reporting_minor, 0) AS avg_order_minor,
                   m.recency_days
            FROM campaign_recipients r
            JOIN customers c ON c.id = r.customer_id
            LEFT JOIN customer_metrics m ON m.customer_id = c.id
            WHERE r.tenant_id = ? AND r.id = ?
            SQL,
            [$this->tenantId(), $recipientId],
        );

        return $row === null ? null : (array) $row;
    }

    private function isSuppressed(string $emailHash): bool
    {
        return DB::connection($this->connection())->selectOne(
            <<<'SQL'
            SELECT EXISTS (
                SELECT 1 FROM suppressions
                WHERE tenant_id = ? AND email_hash = ?
                  AND (expires_at IS NULL OR expires_at > NOW())
            ) AS existe
            SQL,
            [$this->tenantId(), $emailHash],
        )->existe;
    }

    /**
     * URL de baja firmada.
     *
     * Firmada y no adivinable: sin firma, cualquiera podria dar de baja a
     * cualquiera conociendo el hash. Y con el hash y no con el identificador
     * del cliente, porque la baja vale para la persona, no para la fila.
     */
    private function unsubscribeUrl(Campaign $campaign, string $emailHash): string
    {
        return URL::signedRoute('outreach.unsubscribe', [
            'tenant' => $this->context->currentOrFail()->slug,
            'campaign' => $campaign->slug,
            'hash' => $emailHash,
        ]);
    }

    /**
     * El reclamo se renueva al pasar a `in_flight`, y no es un detalle.
     *
     * El TTL original se fijo al reclamar la TANDA entera. Sin renovarlo, la
     * ultima intencion de una tanda lenta puede llamar al proveedor con el
     * reclamo ya vencido, y el recolector la marcaria `unresolved` mientras
     * todavia se esta enviando. La ventana de incertidumbre tiene que
     * empezar cuando llamamos al proveedor, que es cuando empieza de verdad.
     */
    private function markInFlight(string $intentId): void
    {
        DB::connection($this->connection())->update(
            <<<'SQL'
            UPDATE send_intents
            SET state = ?,
                claim_expires_at = NOW() + (? || ' seconds')::interval,
                updated_at = NOW()
            WHERE tenant_id = ? AND id = ?
            SQL,
            [IntentState::InFlight->value, self::CLAIM_TTL_SECONDS, $this->tenantId(), $intentId],
        );
    }

    private function finish(string $intentId, IntentState $state): void
    {
        DB::connection($this->connection())->update(
            'UPDATE send_intents SET state = ?, claimed_by = NULL, claim_expires_at = NULL, updated_at = NOW() WHERE tenant_id = ? AND id = ?',
            [$state->value, $this->tenantId(), $intentId],
        );
    }

    private function openAttempt(string $intentId): int
    {
        $row = DB::connection($this->connection())->selectOne(
            'SELECT COALESCE(MAX(attempt_no), 0) + 1 AS n FROM send_attempts WHERE tenant_id = ? AND intent_id = ?',
            [$this->tenantId(), $intentId],
        );

        DB::connection($this->connection())->insert(
            'INSERT INTO send_attempts (tenant_id, intent_id, attempt_no, started_at) VALUES (?,?,?,NOW())',
            [$this->tenantId(), $intentId, $row->n],
        );

        return (int) $row->n;
    }

    private function closeAttempt(int $attemptNo, string $intentId, string $outcome, ?Throwable $e = null): void
    {
        DB::connection($this->connection())->update(
            <<<'SQL'
            UPDATE send_attempts
            SET finished_at = NOW(), outcome = ?, error_class = ?, error_message = ?
            WHERE tenant_id = ? AND intent_id = ? AND attempt_no = ?
            SQL,
            [
                $outcome,
                $e === null ? null : class_basename($e),
                $e?->getMessage(),
                $this->tenantId(), $intentId, $attemptNo,
            ],
        );
    }

    /**
     * `ON CONFLICT DO NOTHING` contra el indice unico (run, cliente, motivo).
     *
     * Sin el, registrar dos veces el mismo motivo para el mismo cliente en la
     * misma ejecucion —cosa que pasa apenas se reintenta algo— pasaria de
     * duplicar una fila a tirar una violacion de unicidad en medio del
     * despacho. La primera decision es la que vale: es la que registra cuando
     * se decidio de verdad.
     */
    private function decide(CampaignRun $run, string $customerId, DecisionReason $reason): void
    {
        DB::connection($this->connection())->insert(
            'INSERT INTO recipient_decisions (tenant_id, run_id, customer_id, decision, reason_code, decided_at) '
            .'VALUES (?,?,?,?,?,NOW()) ON CONFLICT DO NOTHING',
            [$this->tenantId(), $run->id, $customerId, 'excluded', $reason->value],
        );
    }

    private function tenantId(): string
    {
        return $this->context->currentOrFail()->id;
    }

    private function connection(): string
    {
        return $this->context->connectionName();
    }
}
