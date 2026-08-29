<?php

declare(strict_types=1);

use App\Domain\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tablas derivadas que alimentan el dashboard.
 *
 * El problema que resuelven: la pantalla de estadisticas tiene que seguir
 * siendo usable con el tenant de millones de ordenes. Sumar millones de
 * filas en cada carga de pagina no escala, por mas indices que se pongan.
 *
 * La estrategia no es recalcular todo periodicamente, que seria igual de
 * caro. Es INCREMENTAL Y DIRIGIDA: cuando la ingesta toca una orden, marca
 * el dia de esa orden como sucio, y un trabajo recomputa unicamente los
 * dias marcados.
 *
 * Eso es exactamente lo que hace respondible la pregunta de la orden que
 * llega con 60 dias de atraso. La respuesta deja de ser un encogimiento de
 * hombros y pasa a ser: queda mal ese dia puntual, en estas dos tablas,
 * durante lo que tarde el recomputo, y el retraso se puede medir mirando
 * cuanto tiempo lleva sin procesar la fila mas vieja de dirty_days.
 *
 * El precio es consistencia eventual en el dashboard. Esta asumido y
 * documentado: para inteligencia de negocio, unos segundos de desfasaje son
 * irrelevantes; para el detalle de un cliente que mira soporte, se consulta
 * la tabla curada directamente, sin pasar por agregados.
 */
return new class extends Migration
{
    public function up(): void
    {
        /**
         * Ventas agregadas por dia. La grilla es (tenant, dia, moneda): se
         * conserva la moneda original en vez de colapsar todo a una sola,
         * porque perder esa dimension impide auditar el numero contra lo que
         * ve el retailer en su propio sistema.
         */
        Schema::create('daily_sales_facts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('tenant_id');

            // Dia calendario en la zona horaria del tenant, no en UTC.
            $table->date('date');
            $table->char('currency', 3);

            $table->unsignedInteger('orders_count')->default(0);
            $table->unsignedInteger('items_count')->default(0);
            $table->unsignedInteger('cancelled_count')->default(0);
            $table->unsignedInteger('returned_count')->default(0);

            // Bruto: todas las ordenes del dia.
            $table->bigInteger('gross_amount_minor')->default(0);
            $table->bigInteger('reporting_gross_minor')->default(0);

            // Neto: sin canceladas ni devueltas. Es el numero que un gerente
            // considera "ventas de verdad".
            $table->bigInteger('net_amount_minor')->default(0);
            $table->bigInteger('reporting_net_minor')->default(0);

            $table->timestampTz('computed_at');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'date', 'currency']);
            $table->index(['tenant_id', 'date']);
        });

        /**
         * Metricas por cliente. Sirve a dos consumidores a la vez: la
         * segmentacion de la pantalla de estadisticas (Parte 2) y la
         * resolucion de audiencias del motor de acciones (Parte 3).
         *
         * Que exista esta tabla es lo que permite que una condicion como
         * "compro mas de tres veces en los ultimos 90 dias con ticket
         * promedio arriba de X" no tenga que recorrer el historial de
         * ordenes de cada cliente en tiempo de consulta.
         */
        Schema::create('customer_metrics', function (Blueprint $table) {
            $table->uuid('customer_id')->primary();
            $table->uuid('tenant_id');

            $table->timestampTz('first_order_at')->nullable();
            $table->timestampTz('last_order_at')->nullable();

            $table->unsignedInteger('orders_count')->default(0);
            $table->unsignedInteger('net_orders_count')->default(0);
            $table->unsignedInteger('returns_count')->default(0);

            $table->bigInteger('total_net_reporting_minor')->default(0);
            $table->bigInteger('avg_order_reporting_minor')->default(0);

            // Dias desde la ultima compra, respecto del ultimo recomputo.
            $table->unsignedInteger('recency_days')->nullable();

            // Puntajes RFM de 1 a 5, calculados por quintiles DENTRO del
            // tenant. Un cliente "valioso" de una tienda de barrio no es lo
            // mismo que uno de una cadena: comparar contra el resto de la
            // plataforma no significaria nada.
            $table->unsignedTinyInteger('r_score')->nullable();
            $table->unsignedTinyInteger('f_score')->nullable();
            $table->unsignedTinyInteger('m_score')->nullable();
            $table->string('segment', 32)->nullable();

            $table->timestampTz('computed_at');
            $table->timestampsTz();

            $table->index(['tenant_id', 'segment']);
            $table->index(['tenant_id', 'last_order_at']);
            $table->index(['tenant_id', 'total_net_reporting_minor']);
        });

        /**
         * Cola de dias a recomputar.
         *
         * La escribe la ingesta, la consume el trabajo de agregacion. El
         * indice unico parcial (solo sobre lo no procesado) hace que marcar
         * mil veces el mismo dia dentro de un lote grande deje una sola
         * fila, en vez de mil trabajos redundantes.
         */
        Schema::create('aggregate_dirty_days', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('tenant_id');
            $table->date('date');

            // Por que se ensucio: alta, actualizacion de estado, o dato que
            // llego tarde. Sirve para medir cuanto reproceso genera cada
            // causa, que es informacion de producto, no solo de operacion.
            $table->string('reason', 32);

            $table->timestampTz('created_at');
            $table->timestampTz('processed_at')->nullable();

            $table->index(['tenant_id', 'processed_at', 'created_at']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX aggregate_dirty_days_pending_unique
             ON aggregate_dirty_days (tenant_id, date)
             WHERE processed_at IS NULL'
        );

        foreach (['daily_sales_facts', 'customer_metrics', 'aggregate_dirty_days'] as $table) {
            RowLevelSecurity::protect($table);
        }
    }

    public function down(): void
    {
        foreach (['aggregate_dirty_days', 'customer_metrics', 'daily_sales_facts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
