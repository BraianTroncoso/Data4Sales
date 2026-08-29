<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Actions;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Recalcula los agregados de venta de un dia puntual.
 *
 * Es dirigido, no total: recalcular todo el historico cada vez costaria lo
 * mismo sin importar cuanto cambio, y con tres meses de datos de un tenant
 * grande eso son minutos por cada orden que llega.
 *
 * Se hace en SQL y no en PHP a proposito. Traer un dia de ordenes del tenant
 * grande a memoria para sumarlas es exactamente lo que no hay que hacer:
 * la agregacion es el trabajo que mejor hace la base, y hacerla ahi evita
 * mover millones de filas por la red para tirarlas despues.
 */
final class RecomputeDailyFacts
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  string  $date  Dia calendario del tenant, en formato Y-m-d.
     */
    public function execute(string $date): void
    {
        $tenantId = $this->context->currentOrFail()->id;
        $connection = $this->context->connectionName();

        DB::connection($connection)->transaction(function () use ($date, $tenantId, $connection): void {
            // Se borra y se reinserta en vez de hacer upsert. Es lo unico que
            // maneja bien el caso de una moneda que dejo de tener ventas ese
            // dia: un upsert la dejaria con el valor viejo para siempre.
            DB::connection($connection)->delete(
                'DELETE FROM daily_sales_facts WHERE tenant_id = ? AND date = ?',
                [$tenantId, $date],
            );

            DB::connection($connection)->insert(
                <<<'SQL'
                INSERT INTO daily_sales_facts (
                    tenant_id, date, currency,
                    orders_count, items_count, cancelled_count, returned_count,
                    gross_amount_minor, reporting_gross_minor,
                    net_amount_minor, reporting_net_minor,
                    computed_at, created_at, updated_at
                )
                SELECT
                    o.tenant_id,
                    o.placed_on,
                    o.currency,
                    COUNT(*),
                    COALESCE(SUM(i.units), 0),
                    COUNT(*) FILTER (WHERE o.status = 'cancelled'),
                    COUNT(*) FILTER (WHERE o.status = 'returned'),
                    SUM(o.total_amount_minor),
                    SUM(o.reporting_amount_minor),
                    -- Neto: sin canceladas ni devueltas. Es el número que un
                    -- gerente considera "ventas de verdad".
                    COALESCE(SUM(o.total_amount_minor)
                        FILTER (WHERE o.status NOT IN ('cancelled','returned')), 0),
                    COALESCE(SUM(o.reporting_amount_minor)
                        FILTER (WHERE o.status NOT IN ('cancelled','returned')), 0),
                    NOW(), NOW(), NOW()
                FROM orders o
                LEFT JOIN LATERAL (
                    SELECT COALESCE(SUM(oi.quantity), 0) AS units
                    FROM order_items oi
                    WHERE oi.order_id = o.id
                ) i ON TRUE
                WHERE o.tenant_id = ? AND o.placed_on = ?
                GROUP BY o.tenant_id, o.placed_on, o.currency
                SQL,
                [$tenantId, $date],
            );

            $this->recomputeProductFacts($connection, $tenantId, $date);
        });
    }

    /**
     * Agregado por producto del mismo dia.
     *
     * Solo cuentan las ordenes que son venta neta: incluir canceladas y
     * devueltas en el top de productos mostraria como mas vendido justo lo
     * que la gente devuelve.
     */
    private function recomputeProductFacts(string $connection, string $tenantId, string $date): void
    {
        DB::connection($connection)->delete(
            'DELETE FROM daily_product_facts WHERE tenant_id = ? AND date = ?',
            [$tenantId, $date],
        );

        DB::connection($connection)->insert(
            <<<'SQL'
            INSERT INTO daily_product_facts (
                tenant_id, date, product_id, category,
                orders_count, units, reporting_revenue_minor, computed_at
            )
            SELECT
                o.tenant_id,
                o.placed_on,
                oi.product_id,
                p.category,
                COUNT(DISTINCT o.id),
                SUM(oi.quantity),
                -- El renglón está en la moneda de la orden; se lleva a la de
                -- reporte con la MISMA tasa congelada que usó la orden, no
                -- con una nueva. Recalcular la tasa acá haría que el total
                -- por producto no cerrara contra el total de ventas.
                SUM((oi.total_amount_minor * o.fx_rate)::bigint),
                NOW()
            FROM order_items oi
            JOIN orders o   ON o.id = oi.order_id
            JOIN products p ON p.id = oi.product_id
            WHERE o.tenant_id = ?
              AND o.placed_on = ?
              AND o.status NOT IN ('cancelled','returned')
            GROUP BY o.tenant_id, o.placed_on, oi.product_id, p.category
            SQL,
            [$tenantId, $date],
        );
    }
}
