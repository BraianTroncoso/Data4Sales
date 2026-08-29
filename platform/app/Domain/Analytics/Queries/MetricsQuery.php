<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Queries;

use App\Domain\Analytics\Enums\RfmSegment;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Las consultas que alimentan la pantalla de estadisticas.
 *
 * Vive en el dominio y no en un controlador porque tiene DOS consumidores: la
 * API publica (Parte 1) y el dashboard (Parte 2). Duplicar este SQL en los
 * dos lugares garantizaria que en algun momento devuelvan numeros distintos
 * para la misma pregunta, y que nadie sepa cual de los dos esta bien.
 *
 * NINGUNA de estas consultas toca la tabla de ordenes. Todas leen las tablas
 * derivadas, que tienen una fila por dia o por cliente en vez de una por
 * venta. Es lo que hace que el costo dependa del rango de fechas pedido y no
 * del volumen de ventas que haya adentro.
 */
final class MetricsQuery
{
    /** @var list<string> */
    private const GRANULARITIES = ['day', 'week', 'month'];

    public function __construct(
        private readonly TenantContext $context,
    ) {}

    /**
     * Evolucion temporal de revenue y cantidad de ordenes.
     *
     * @return list<array<string, mixed>>
     */
    public function salesSeries(string $from, string $to, string $granularity): array
    {
        // Lista blanca: el valor se interpola dentro de DATE_TRUNC, asi que
        // no puede venir del cliente sin filtrar.
        $bucket = in_array($granularity, self::GRANULARITIES, strict: true) ? $granularity : 'day';

        $rows = $this->select(
            <<<SQL
            SELECT
                DATE_TRUNC('{$bucket}', date)::date AS bucket,
                SUM(orders_count)          AS orders_count,
                SUM(items_count)           AS items_count,
                SUM(cancelled_count)       AS cancelled_count,
                SUM(returned_count)        AS returned_count,
                SUM(reporting_gross_minor) AS gross_minor,
                SUM(reporting_net_minor)   AS net_minor
            FROM daily_sales_facts
            WHERE tenant_id = ? AND date BETWEEN ? AND ?
            GROUP BY bucket
            ORDER BY bucket
            SQL,
            [$this->tenantId(), $from, $to],
        );

        return array_map(static fn (object $r) => [
            'bucket' => $r->bucket,
            'orders_count' => (int) $r->orders_count,
            'items_count' => (int) $r->items_count,
            'cancelled_count' => (int) $r->cancelled_count,
            'returned_count' => (int) $r->returned_count,
            'gross_minor' => (int) $r->gross_minor,
            'net_minor' => (int) $r->net_minor,
        ], $rows);
    }

    /**
     * Reparto de compradores por comportamiento de compra.
     *
     * @return list<array<string, mixed>>
     */
    public function segments(): array
    {
        $rows = $this->select(
            <<<'SQL'
            SELECT
                COALESCE(segment, 'sin_compras')                    AS segment,
                COUNT(*)                                            AS customers,
                COALESCE(SUM(total_net_reporting_minor), 0)         AS revenue_minor,
                COALESCE(AVG(avg_order_reporting_minor), 0)::bigint AS avg_ticket_minor
            FROM customer_metrics
            WHERE tenant_id = ?
            GROUP BY segment
            ORDER BY revenue_minor DESC
            SQL,
            [$this->tenantId()],
        );

        return array_map(static fn (object $r) => [
            'segment' => $r->segment,
            'label' => RfmSegment::tryFrom($r->segment)?->label() ?? 'Sin compras',
            'customers' => (int) $r->customers,
            'revenue_minor' => (int) $r->revenue_minor,
            'avg_ticket_minor' => (int) $r->avg_ticket_minor,
        ], $rows);
    }

    /**
     * Productos o categorias que mas facturan en el rango.
     *
     * @return list<array<string, mixed>>
     */
    public function topProducts(string $from, string $to, int $limit, bool $byCategory): array
    {
        $rows = $byCategory
            ? $this->select(
                <<<'SQL'
                SELECT COALESCE(category, 'sin categoría') AS label,
                       NULL::uuid                          AS product_id,
                       SUM(units)                          AS units,
                       SUM(reporting_revenue_minor)        AS revenue_minor
                FROM daily_product_facts
                WHERE tenant_id = ? AND date BETWEEN ? AND ?
                GROUP BY category
                ORDER BY revenue_minor DESC
                LIMIT ?
                SQL,
                [$this->tenantId(), $from, $to, $limit],
            )
            : $this->select(
                <<<'SQL'
                SELECT p.name                         AS label,
                       f.product_id                   AS product_id,
                       SUM(f.units)                   AS units,
                       SUM(f.reporting_revenue_minor) AS revenue_minor
                FROM daily_product_facts f
                JOIN products p ON p.id = f.product_id
                WHERE f.tenant_id = ? AND f.date BETWEEN ? AND ?
                GROUP BY f.product_id, p.name
                ORDER BY revenue_minor DESC
                LIMIT ?
                SQL,
                [$this->tenantId(), $from, $to, $limit],
            );

        return array_map(static fn (object $r) => [
            'label' => $r->label,
            'product_id' => $r->product_id,
            'units' => (int) $r->units,
            'revenue_minor' => (int) $r->revenue_minor,
        ], $rows);
    }

    /**
     * Recompra: que porcion de los compradores vuelve, y cuanto pesa.
     *
     * Se calcula enteramente sobre customer_metrics, que tiene una fila por
     * cliente. Una cohorte mes a mes exigiria recorrer el historial completo
     * y dejaria de responder en el tenant grande, que es justo donde la
     * metrica importa.
     *
     * @return array<string, mixed>
     */
    public function retention(): array
    {
        $row = $this->selectOne(
            <<<'SQL'
            SELECT
                COUNT(*) FILTER (WHERE net_orders_count >= 1) AS buyers,
                COUNT(*) FILTER (WHERE net_orders_count >= 2) AS repeat_buyers,
                COALESCE(SUM(total_net_reporting_minor), 0)   AS revenue_minor,
                COALESCE(SUM(total_net_reporting_minor) FILTER (WHERE net_orders_count >= 2), 0) AS repeat_revenue_minor,
                COALESCE(AVG(net_orders_count) FILTER (WHERE net_orders_count >= 1), 0) AS avg_orders_per_buyer
            FROM customer_metrics
            WHERE tenant_id = ?
            SQL,
            [$this->tenantId()],
        );

        $buyers = (int) $row->buyers;
        $revenue = (int) $row->revenue_minor;

        return [
            'buyers' => $buyers,
            'repeat_buyers' => (int) $row->repeat_buyers,
            'repeat_rate' => $buyers > 0 ? round((int) $row->repeat_buyers / $buyers, 4) : 0.0,
            // Cuánto del negocio depende de que la gente vuelva. Suele ser el
            // número que más mueve decisiones, y casi nunca se mira.
            'repeat_revenue_share' => $revenue > 0 ? round((int) $row->repeat_revenue_minor / $revenue, 4) : 0.0,
            'avg_orders_per_buyer' => round((float) $row->avg_orders_per_buyer, 2),
        ];
    }

    /**
     * Cuan actualizados estan los agregados.
     *
     * Es la respuesta operativa a "llega una orden de hace 60 dias, que queda
     * mal y por cuanto tiempo". Sin esto el desfasaje seria invisible, y un
     * dashboard que no dice cuan viejo es su dato invita a decisiones tomadas
     * sobre numeros que ya cambiaron.
     *
     * @return array<string, mixed>
     */
    public function freshness(): array
    {
        $row = $this->selectOne(
            <<<'SQL'
            SELECT COUNT(*) AS pending, MIN(created_at) AS oldest
            FROM aggregate_dirty_days
            WHERE tenant_id = ? AND processed_at IS NULL
            SQL,
            [$this->tenantId()],
        );

        $oldest = $row->oldest !== null ? CarbonImmutable::parse($row->oldest) : null;

        return [
            'pending_days' => (int) $row->pending,
            'oldest_pending_minutes' => $oldest !== null ? (int) $oldest->diffInMinutes(CarbonImmutable::now()) : null,
            'is_stale' => (int) $row->pending > 0,
        ];
    }

    /**
     * Primer y ultimo dia con ventas. Es el rango por defecto de la pantalla.
     *
     * Que el default sea el dataset entero y no "el ultimo mes" es
     * deliberado: con datos historicos sembrados, un default temporal
     * mostraria una pantalla vacia y pareceria un error del sistema.
     *
     * @return array{from: string|null, to: string|null}
     */
    public function bounds(): array
    {
        $row = $this->selectOne(
            'SELECT MIN(date)::text AS from_date, MAX(date)::text AS to_date FROM daily_sales_facts WHERE tenant_id = ?',
            [$this->tenantId()],
        );

        return ['from' => $row->from_date, 'to' => $row->to_date];
    }

    /**
     * Normaliza un rango pedido contra el rango real del dataset.
     *
     * @return array{string, string}
     */
    public function resolveRange(?string $from, ?string $to): array
    {
        $bounds = $this->bounds();
        $fallback = CarbonImmutable::now()->format('Y-m-d');

        return [
            self::asDate($from) ?? $bounds['from'] ?? $fallback,
            self::asDate($to) ?? $bounds['to'] ?? $fallback,
        ];
    }

    public static function asDate(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    public static function granularity(?string $value): string
    {
        return in_array($value, self::GRANULARITIES, strict: true) ? $value : 'day';
    }

    /**
     * @param  list<mixed>  $bindings
     * @return list<object>
     */
    private function select(string $sql, array $bindings): array
    {
        return DB::connection($this->context->connectionName())->select($sql, $bindings);
    }

    /**
     * @param  list<mixed>  $bindings
     */
    private function selectOne(string $sql, array $bindings): object
    {
        return DB::connection($this->context->connectionName())->selectOne($sql, $bindings);
    }

    private function tenantId(): string
    {
        return $this->context->currentOrFail()->id;
    }
}
