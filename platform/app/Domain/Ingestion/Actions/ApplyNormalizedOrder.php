<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Actions;

use App\Domain\Analytics\Models\AggregateDirtyDay;
use App\Domain\Ingestion\Pipeline\NormalizedOrder;
use App\Domain\Sales\Models\Customer;
use App\Domain\Sales\Models\Order;
use App\Domain\Sales\Models\OrderItem;
use App\Domain\Sales\Models\OrderStatusHistory;
use App\Domain\Sales\Models\Product;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Persiste una orden normalizada en la zona curada.
 *
 * Dos propiedades que definen todo el diseno de esta clase:
 *
 * IDEMPOTENCIA. Aplicar la misma orden dos veces deja el sistema igual que
 * aplicarla una. Es lo que hace seguro reintentar un lote que se corto a la
 * mitad sin tener que saber por donde iba.
 *
 * GUARDA DE VERSION. Una orden se actualiza solo si el evento que llega es
 * mas nuevo que el estado guardado. Sin esto, un reintento desordenado
 * devolveria una orden entregada al estado "pagada", y el sintoma seria un
 * dashboard que se corrige solo y despues se rompe solo.
 *
 * La guarda se implementa como una condicion DENTRO del upsert, no como un
 * SELECT previo. Es deliberado: entre leer y escribir hay una ventana en la
 * que otro worker puede escribir, y con lotes grandes procesandose en
 * paralelo esa ventana se abre miles de veces por minuto.
 */
final class ApplyNormalizedOrder
{
    public const RESULT_APPLIED = 'applied';

    public const RESULT_STALE = 'stale';

    public function __construct(
        private readonly TenantContext $context,
    ) {}

    /**
     * @return self::RESULT_*
     */
    public function execute(NormalizedOrder $normalized, ?int $rawEventId = null): string
    {
        $tenantId = $this->context->currentOrFail()->id;
        $connection = $this->context->connectionName();
        $now = CarbonImmutable::now();

        return DB::connection($connection)->transaction(function () use ($normalized, $tenantId, $now, $rawEventId): string {
            $customerId = $this->upsertCustomer($normalized, $tenantId, $now);

            // Estado anterior, para decidir si hubo transicion y para saber
            // si la orden cambio de dia (y por lo tanto ensucia dos dias).
            $previous = Order::query()
                ->where('external_id', $normalized->externalId)
                ->first(['id', 'status', 'placed_on', 'source_updated_at']);

            $orderId = $this->upsertOrder($normalized, $tenantId, $customerId, $now);

            if ($orderId === null) {
                // El upsert no toco nada: lo que llego es mas viejo que lo
                // guardado. Se registra para poder investigarlo despues; sin
                // esta huella, "por que esta orden quedo asi" no tiene
                // respuesta.
                $this->recordIgnoredUpdate($normalized, $tenantId, $previous, $rawEventId, $now);

                return self::RESULT_STALE;
            }

            $this->replaceItems($normalized, $tenantId, $orderId, $now);
            $this->recordStatusTransition($normalized, $tenantId, $orderId, $previous, $rawEventId, $now);
            $this->markDirtyDays($normalized, $tenantId, $previous, $now);

            return self::RESULT_APPLIED;
        });
    }

    /**
     * El cliente se crea o se completa, nunca se pisa con datos peores.
     *
     * COALESCE en cada campo hace que un envio posterior sin nombre no borre
     * el nombre que ya teniamos: los retailers mandan payloads parciales todo
     * el tiempo, y tratarlos como la verdad completa vacia el padron.
     */
    private function upsertCustomer(NormalizedOrder $normalized, string $tenantId, CarbonImmutable $now): string
    {
        $customer = $normalized->customer;

        DB::connection($this->context->connectionName())->statement(
            <<<'SQL'
            INSERT INTO customers (
                id, tenant_id, external_id, name, email, email_normalized,
                email_status, phone, country, first_seen_at, last_seen_at,
                created_at, updated_at
            )
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
            ON CONFLICT (tenant_id, external_id) DO UPDATE SET
                name             = COALESCE(EXCLUDED.name, customers.name),
                email            = COALESCE(EXCLUDED.email, customers.email),
                email_normalized = COALESCE(EXCLUDED.email_normalized, customers.email_normalized),
                email_status     = CASE
                                       WHEN EXCLUDED.email_status = 'missing' THEN customers.email_status
                                       ELSE EXCLUDED.email_status
                                   END,
                phone            = COALESCE(EXCLUDED.phone, customers.phone),
                country          = COALESCE(EXCLUDED.country, customers.country),
                last_seen_at     = GREATEST(customers.last_seen_at, EXCLUDED.last_seen_at),
                updated_at       = EXCLUDED.updated_at
            SQL,
            [
                (string) Str::uuid(), $tenantId, $customer->externalId,
                $customer->name, $customer->email, $customer->emailNormalized,
                $customer->emailStatus, $customer->phone, $customer->country,
                $now, $now, $now, $now,
            ],
        );

        return Customer::query()
            ->where('external_id', $customer->externalId)
            ->value('id');
    }

    /**
     * Upsert con guarda de version.
     *
     * El `WHERE` del DO UPDATE es la guarda: si lo guardado es igual o mas
     * nuevo, el upsert no modifica ninguna fila y el RETURNING viene vacio.
     * Esa ausencia de resultado ES la senal de que el evento llego tarde.
     *
     * @return string|null id de la orden, o null si el evento era viejo
     */
    private function upsertOrder(
        NormalizedOrder $normalized,
        string $tenantId,
        string $customerId,
        CarbonImmutable $now,
    ): ?string {
        $rows = DB::connection($this->context->connectionName())->select(
            <<<'SQL'
            INSERT INTO orders (
                id, tenant_id, external_id, customer_id, status, currency,
                total_amount_minor, reporting_amount_minor, fx_rate,
                placed_at, placed_on, source_updated_at, received_at,
                created_at, updated_at
            )
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ON CONFLICT (tenant_id, external_id) DO UPDATE SET
                customer_id            = EXCLUDED.customer_id,
                status                 = EXCLUDED.status,
                currency               = EXCLUDED.currency,
                total_amount_minor     = EXCLUDED.total_amount_minor,
                reporting_amount_minor = EXCLUDED.reporting_amount_minor,
                fx_rate                = EXCLUDED.fx_rate,
                placed_at              = EXCLUDED.placed_at,
                placed_on              = EXCLUDED.placed_on,
                source_updated_at      = EXCLUDED.source_updated_at,
                received_at            = EXCLUDED.received_at,
                updated_at             = EXCLUDED.updated_at
            WHERE orders.source_updated_at < EXCLUDED.source_updated_at
            RETURNING id
            SQL,
            [
                (string) Str::uuid(), $tenantId, $normalized->externalId, $customerId,
                $normalized->status->value, $normalized->currency,
                $normalized->totalAmountMinor, $normalized->reportingAmountMinor, $normalized->fxRate,
                $normalized->placedAt, $normalized->placedOn, $normalized->sourceUpdatedAt, $now,
                $now, $now,
            ],
        );

        return $rows === [] ? null : $rows[0]->id;
    }

    /**
     * Los renglones se reemplazan enteros, no se concilian uno a uno.
     *
     * Una orden trae pocos renglones y el origen manda siempre la orden
     * completa, asi que reemplazar es mas simple y mas correcto que intentar
     * adivinar que renglon corresponde a cual cuando el retailer los
     * reordena o cambia un identificador.
     */
    private function replaceItems(NormalizedOrder $normalized, string $tenantId, string $orderId, CarbonImmutable $now): void
    {
        OrderItem::query()->where('order_id', $orderId)->delete();

        if ($normalized->items === []) {
            return;
        }

        $rows = [];

        foreach ($normalized->items as $item) {
            $rows[] = [
                'tenant_id' => $tenantId,
                'order_id' => $orderId,
                'product_id' => $this->upsertProduct($item->productExternalId, $item->sku, $item->name, $item->category, $tenantId, $now),
                'external_id' => $item->externalId,
                'quantity' => $item->quantity,
                'unit_amount_minor' => $item->unitAmountMinor,
                'total_amount_minor' => $item->totalAmountMinor,
                'currency' => $item->currency,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        OrderItem::query()->insert($rows);
    }

    private function upsertProduct(
        string $externalId,
        ?string $sku,
        string $name,
        ?string $category,
        string $tenantId,
        CarbonImmutable $now,
    ): string {
        DB::connection($this->context->connectionName())->statement(
            <<<'SQL'
            INSERT INTO products (id, tenant_id, external_id, sku, name, category, created_at, updated_at)
            VALUES (?,?,?,?,?,?,?,?)
            ON CONFLICT (tenant_id, external_id) DO UPDATE SET
                sku      = COALESCE(EXCLUDED.sku, products.sku),
                name     = COALESCE(EXCLUDED.name, products.name),
                category = COALESCE(EXCLUDED.category, products.category),
                updated_at = EXCLUDED.updated_at
            SQL,
            [(string) Str::uuid(), $tenantId, $externalId, $sku, $name, $category, $now, $now],
        );

        return Product::query()->where('external_id', $externalId)->value('id');
    }

    private function recordStatusTransition(
        NormalizedOrder $normalized,
        string $tenantId,
        string $orderId,
        ?Order $previous,
        ?int $rawEventId,
        CarbonImmutable $now,
    ): void {
        // Nada cambio: no se ensucia el historial con ruido.
        if ($previous !== null && $previous->status === $normalized->status) {
            return;
        }

        OrderStatusHistory::query()->insert([
            'tenant_id' => $tenantId,
            'order_id' => $orderId,
            'from_status' => $previous?->status?->value,
            'to_status' => $normalized->status->value,
            'occurred_at' => $normalized->sourceUpdatedAt,
            'received_at' => $now,
            'raw_event_id' => $rawEventId,
        ]);
    }

    /**
     * Deja constancia de una actualizacion que se ignoro por llegar tarde.
     *
     * Se guarda con el estado de origen y el de destino iguales, para que se
     * distinga de una transicion real al leer el historial.
     */
    private function recordIgnoredUpdate(
        NormalizedOrder $normalized,
        string $tenantId,
        ?Order $previous,
        ?int $rawEventId,
        CarbonImmutable $now,
    ): void {
        if ($previous === null) {
            return;
        }

        OrderStatusHistory::query()->insert([
            'tenant_id' => $tenantId,
            'order_id' => $previous->id,
            'from_status' => $previous->status->value,
            'to_status' => $previous->status->value,
            'occurred_at' => $normalized->sourceUpdatedAt,
            'received_at' => $now,
            'raw_event_id' => $rawEventId,
        ]);
    }

    /**
     * Marca los dias cuyos agregados quedaron desactualizados.
     *
     * Si la orden cambio de fecha, se ensucian los DOS dias: el que la
     * pierde y el que la gana. Olvidarse del primero deja un revenue
     * inflado en el pasado que nadie vuelve a mirar.
     */
    private function markDirtyDays(
        NormalizedOrder $normalized,
        string $tenantId,
        ?Order $previous,
        CarbonImmutable $now,
    ): void {
        $reason = match (true) {
            $previous === null && $normalized->placedAt->diffInDays($now) > 1 => AggregateDirtyDay::REASON_LATE_ARRIVAL,
            $previous === null => AggregateDirtyDay::REASON_NEW_ORDER,
            default => AggregateDirtyDay::REASON_STATUS_CHANGE,
        };

        $days = [$normalized->placedOn];

        if ($previous !== null && $previous->placed_on->format('Y-m-d') !== $normalized->placedOn) {
            $days[] = $previous->placed_on->format('Y-m-d');
        }

        foreach (array_filter(array_unique($days)) as $day) {
            DB::connection($this->context->connectionName())->statement(
                <<<'SQL'
                INSERT INTO aggregate_dirty_days (tenant_id, date, reason, created_at)
                VALUES (?,?,?,?)
                ON CONFLICT (tenant_id, date) WHERE processed_at IS NULL DO NOTHING
                SQL,
                [$tenantId, $day, $reason, $now],
            );
        }
    }
}
