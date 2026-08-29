<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Actions;

use App\Domain\Analytics\Enums\RfmSegment;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Recalcula las metricas por cliente y su segmento RFM.
 *
 * Va en dos pasos que no se pueden fusionar, y entender por que explica el
 * diseno entero:
 *
 *   1. METRICAS BASE. Se pueden calcular cliente por cliente, mirando solo
 *      sus ordenes. Se recalculan unicamente para los afectados.
 *
 *   2. PUNTAJES RFM. Son quintiles: para saber si un cliente esta en el 20%
 *      que mas gasta hay que mirar a TODOS los demas. No hay forma de
 *      calcularlo mirando un cliente aislado.
 *
 * Por eso el paso 2 recorre el tenant entero. Se resuelve con una sola
 * consulta usando funciones de ventana: una pasada, no una consulta por
 * cliente.
 *
 * Los quintiles son POR TENANT, nunca globales. Un cliente "campeon" de una
 * tienda de barrio no es comparable con uno de una cadena, y medirlo contra
 * el resto de la plataforma no significaria nada.
 */
final class RecomputeCustomerMetrics
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  list<string>|null  $customerIds  Null recalcula todo el tenant.
     */
    public function execute(?array $customerIds = null): void
    {
        $tenantId = $this->context->currentOrFail()->id;
        $connection = $this->context->connectionName();

        DB::connection($connection)->transaction(function () use ($customerIds, $tenantId, $connection): void {
            $this->recomputeBaseMetrics($connection, $tenantId, $customerIds);
            $this->recomputeRfmScores($connection, $tenantId);
        });
    }

    /**
     * @param  list<string>|null  $customerIds
     */
    private function recomputeBaseMetrics(string $connection, string $tenantId, ?array $customerIds): void
    {
        $bindings = [$tenantId];
        $filter = '';

        if ($customerIds !== null) {
            if ($customerIds === []) {
                return;
            }

            // El conjunto viaja como UN parametro de tipo arreglo, no como un
            // marcador por cliente. Con un binding por id, un backfill de un
            // tenant grande supera los 65.535 parametros que admite el
            // protocolo de PostgreSQL y la consulta ni siquiera llega a
            // ejecutarse: el sintoma es que el recomputo explota justo cuando
            // mas datos hay que arreglar. Hay tenants con decenas de miles de
            // clientes, asi que el limite es alcanzable, no teorico.
            $filter = 'AND c.id = ANY(?::uuid[])';
            $bindings[] = '{'.implode(',', $customerIds).'}';
        }

        DB::connection($connection)->insert(
            <<<SQL
            INSERT INTO customer_metrics (
                customer_id, tenant_id, first_order_at, last_order_at,
                orders_count, net_orders_count, returns_count,
                total_net_reporting_minor, avg_order_reporting_minor,
                recency_days, computed_at, created_at, updated_at
            )
            SELECT
                c.id,
                c.tenant_id,
                MIN(o.placed_at),
                MAX(o.placed_at),
                COUNT(o.id),
                COUNT(o.id) FILTER (WHERE o.status NOT IN ('cancelled','returned')),
                COUNT(o.id) FILTER (WHERE o.status = 'returned'),
                COALESCE(SUM(o.reporting_amount_minor)
                    FILTER (WHERE o.status NOT IN ('cancelled','returned')), 0),
                -- Ticket promedio sobre las ventas que cuentan. Dividir por
                -- el total de órdenes incluiría las canceladas y daría un
                -- promedio más bajo que cualquier venta real.
                COALESCE(
                    SUM(o.reporting_amount_minor) FILTER (WHERE o.status NOT IN ('cancelled','returned'))
                    / NULLIF(COUNT(o.id) FILTER (WHERE o.status NOT IN ('cancelled','returned')), 0),
                    0
                ),
                EXTRACT(DAY FROM NOW() - MAX(o.placed_at))::int,
                NOW(), NOW(), NOW()
            FROM customers c
            LEFT JOIN orders o ON o.customer_id = c.id AND o.tenant_id = c.tenant_id
            WHERE c.tenant_id = ? {$filter}
            GROUP BY c.id, c.tenant_id
            ON CONFLICT (customer_id) DO UPDATE SET
                first_order_at            = EXCLUDED.first_order_at,
                last_order_at             = EXCLUDED.last_order_at,
                orders_count              = EXCLUDED.orders_count,
                net_orders_count          = EXCLUDED.net_orders_count,
                returns_count             = EXCLUDED.returns_count,
                total_net_reporting_minor = EXCLUDED.total_net_reporting_minor,
                avg_order_reporting_minor = EXCLUDED.avg_order_reporting_minor,
                recency_days              = EXCLUDED.recency_days,
                computed_at               = EXCLUDED.computed_at,
                updated_at                = EXCLUDED.updated_at
            SQL,
            $bindings,
        );
    }

    /**
     * Asigna los quintiles y el segmento resultante.
     *
     * La tabla de segmentos se genera desde el enum de PHP (ver
     * RfmSegment::sqlValues), asi que la regla de negocio vive en un solo
     * lugar. Duplicarla a mano en SQL seria la clase de divergencia que no
     * se nota hasta que alguien pregunta por que el dashboard y una campana
     * cuentan distinto la misma cosa.
     */
    private function recomputeRfmScores(string $connection, string $tenantId): void
    {
        $segments = RfmSegment::sqlValues();

        DB::connection($connection)->update(
            <<<SQL
            WITH ranked AS (
                SELECT
                    customer_id,
                    NTILE(5) OVER (ORDER BY last_order_at ASC NULLS FIRST)            AS r,
                    NTILE(5) OVER (ORDER BY net_orders_count ASC, last_order_at ASC)  AS f,
                    NTILE(5) OVER (ORDER BY total_net_reporting_minor ASC)            AS m
                FROM customer_metrics
                WHERE tenant_id = ?
                  -- Sin compras no hay comportamiento que segmentar; incluirlos
                  -- correría los quintiles de todos los demás.
                  AND net_orders_count > 0
            )
            UPDATE customer_metrics cm
            SET r_score = ranked.r,
                f_score = ranked.f,
                m_score = ranked.m,
                segment = seg.name
            FROM ranked
            JOIN (VALUES {$segments}) AS seg(r, f, m, name)
              ON seg.r = ranked.r AND seg.f = ranked.f AND seg.m = ranked.m
            WHERE cm.customer_id = ranked.customer_id
            SQL,
            [$tenantId],
        );
    }
}
