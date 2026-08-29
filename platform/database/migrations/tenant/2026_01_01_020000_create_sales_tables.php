<?php

declare(strict_types=1);

use App\Domain\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Zona curada: el modelo de negocio ya normalizado y validado.
 *
 * Cuatro decisiones estructurales que conviene entender antes de leer las
 * columnas, porque explican casi todo lo demas:
 *
 * 1. EL DINERO ES UN ENTERO. Todos los montos van en la unidad minima de
 *    su moneda (centavos), en bigint. Nunca float, nunca decimal. Un float
 *    no puede representar 0.10 exactamente, y sumar millones de ordenes
 *    con ese error da un revenue que no cierra contra el del retailer.
 *
 * 2. LA MONEDA NO SE CONVIERTE AL VUELO. Cada orden guarda su monto
 *    original con su moneda, y ademas un monto en la moneda de reporte del
 *    tenant con la tasa que regia el dia de la orden, congelada. Si se
 *    convirtiera con la tasa de hoy, el revenue de marzo cambiaria cada vez
 *    que alguien abre el dashboard, y ningun reporte seria reproducible.
 *
 * 3. HAY TRES TIEMPOS DISTINTOS, y confundirlos es el error clasico:
 *      placed_at         cuando ocurrio en el mundo real
 *      source_updated_at cuando el sistema de origen genero este estado
 *      received_at       cuando nos llego a nosotros
 *    La diferencia entre el primero y el tercero es "dato que llega tarde".
 *    El segundo es la guarda de version que evita que un evento viejo que
 *    llega desordenado pise un estado mas nuevo.
 *
 * 4. LA FECHA DEL TENANT ESTA PRECALCULADA. `placed_on` es el dia calendario
 *    en la zona horaria del tenant. Calcularlo en cada consulta obligaria a
 *    aplicar una funcion sobre millones de filas y tiraria el indice a la
 *    basura. Precalculado, agrupar por dia es un recorrido de indice.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Busqueda por nombre parcial con respuesta rapida sobre cientos de
        // miles de clientes. Son extensiones confiables, asi que las puede
        // instalar el dueno de la base sin ser superusuario.
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');

        /**
         * Envoltorio INMUTABLE de unaccent().
         *
         * PostgreSQL marca `unaccent()` como STABLE, no IMMUTABLE, porque su
         * resultado depende de un diccionario que en teoria puede cambiar. Y
         * no se puede indexar una expresion que no sea inmutable.
         *
         * Este envoltorio fija el diccionario explicitamente y declara la
         * funcion inmutable, que es lo que permite construir el indice. El
         * compromiso es real y conviene conocerlo: si alguien cambiara el
         * diccionario `unaccent`, los indices quedarian desactualizados y
         * habria que reconstruirlos. A cambio, buscar "gonzalez" encuentra
         * "González" usando indice, en vez de recorrer la tabla entera.
         */
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION immutable_unaccent(text)
            RETURNS text
            LANGUAGE sql
            IMMUTABLE PARALLEL SAFE STRICT
            AS $$ SELECT public.unaccent('public.unaccent', $1) $$
        SQL);

        Schema::create('customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');

            // Clave natural del retailer. Es la que usa el upsert, y por eso
            // es unica por tenant: dos retailers distintos pueden usar el
            // mismo identificador sin pisarse.
            $table->string('external_id', 128);

            $table->string('name')->nullable();
            $table->string('email')->nullable();

            // Version normalizada (minusculas, sin espacios) para busqueda e
            // indice. Se guarda aparte del email original porque el soporte
            // necesita ver lo que el retailer mando, no lo que nosotros
            // interpretamos.
            $table->string('email_normalized')->nullable();

            // Un email invalido no descarta al cliente. Se guarda el cliente,
            // se marca el estado del email y se anota el rechazo del campo.
            // Perder un comprador entero porque su mail tiene un espacio de
            // mas es peor que quedarse sin ese mail.
            $table->string('email_status', 16)->default('missing');

            $table->string('phone', 32)->nullable();
            $table->char('country', 2)->nullable();

            $table->timestampTz('source_created_at')->nullable();
            $table->timestampTz('source_updated_at')->nullable();
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'external_id']);

            // Deliberadamente NO unico. El enunciado exige emails repetidos
            // entre clientes distintos, y en la realidad pasa: familias que
            // comparten casilla, cuentas corporativas, cajas de tienda. El
            // email es un atributo de contacto, no una identidad.
            $table->index(['tenant_id', 'email_normalized']);

            $table->index(['tenant_id', 'last_seen_at']);
        });

        DB::statement(
            "ALTER TABLE customers ADD CONSTRAINT customers_email_status_check
             CHECK (email_status IN ('valid','invalid','missing'))"
        );

        /**
         * Indices trigrama SOBRE LA FORMA SIN ACENTOS.
         *
         * Buscar 'gonza' dentro de 'María González' usa indice en vez de
         * recorrer la tabla entera. Y lo hace sin acentos a proposito: un
         * agente de soporte atendiendo un llamado escribe "gonzalez", no
         * "González". Indexar el nombre crudo haria que esa busqueda -que es
         * la normal- no encontrara nada.
         *
         * La consulta tiene que aplicar la misma funcion a los dos lados, y
         * eso lo hace CustomerSearch.
         */
        DB::statement('CREATE INDEX customers_name_trgm_idx ON customers USING gin (immutable_unaccent(name) gin_trgm_ops)');
        DB::statement('CREATE INDEX customers_email_trgm_idx ON customers USING gin (immutable_unaccent(email_normalized) gin_trgm_ops)');

        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('external_id', 128);

            $table->string('sku', 128)->nullable();
            $table->string('name');
            $table->string('category', 128)->nullable();

            $table->timestampTz('source_updated_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'external_id']);
            $table->index(['tenant_id', 'category']);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('external_id', 128);
            $table->uuid('customer_id');

            $table->string('status', 16);

            $table->char('currency', 3);
            $table->bigInteger('total_amount_minor');

            // Mismo monto en la moneda de reporte del tenant, con la tasa
            // del dia de la orden ya aplicada y guardada. Poder ver la tasa
            // usada es lo que hace auditable el numero del dashboard.
            $table->bigInteger('reporting_amount_minor');
            $table->decimal('fx_rate', 18, 8)->default(1);

            $table->timestampTz('placed_at');
            $table->date('placed_on');
            $table->timestampTz('source_updated_at');
            $table->timestampTz('received_at');

            $table->timestampsTz();

            $table->unique(['tenant_id', 'external_id']);
            $table->index(['tenant_id', 'placed_on']);
            $table->index(['tenant_id', 'status', 'placed_at']);
            $table->index(['tenant_id', 'customer_id', 'placed_at']);

            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
        });

        DB::statement(
            "ALTER TABLE orders ADD CONSTRAINT orders_status_check
             CHECK (status IN ('created','paid','shipped','delivered','cancelled','returned'))"
        );

        // El motor tampoco acepta un monto negativo. Los rechazos de la
        // aplicacion son la primera linea; esto es la ultima.
        DB::statement(
            'ALTER TABLE orders ADD CONSTRAINT orders_total_non_negative_check
             CHECK (total_amount_minor >= 0)'
        );

        Schema::create('order_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('tenant_id');
            $table->uuid('order_id');
            $table->uuid('product_id');

            $table->string('external_id', 128)->nullable();
            $table->unsignedInteger('quantity');
            $table->bigInteger('unit_amount_minor');
            $table->bigInteger('total_amount_minor');
            $table->char('currency', 3);

            $table->timestampsTz();

            $table->index(['tenant_id', 'order_id']);
            $table->index(['tenant_id', 'product_id']);

            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });

        /**
         * Historial de estados. No es auditoria decorativa: es lo que
         * permite responder "esta orden estuvo entregada y despues volvio
         * como devolucion", que es justo lo que necesitan las condiciones
         * de la Parte 3. Reconstruirlo desde los eventos crudos en cada
         * consulta seria carisimo.
         */
        Schema::create('order_status_history', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('tenant_id');
            $table->uuid('order_id');

            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16);

            $table->timestampTz('occurred_at');
            $table->timestampTz('received_at');
            $table->unsignedBigInteger('raw_event_id')->nullable();

            $table->index(['tenant_id', 'order_id', 'occurred_at']);
            $table->index(['tenant_id', 'to_status', 'occurred_at']);

            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
        });

        foreach (['customers', 'products', 'orders', 'order_items', 'order_status_history'] as $table) {
            RowLevelSecurity::protect($table);
        }
    }

    public function down(): void
    {
        foreach (['order_status_history', 'order_items', 'orders', 'products', 'customers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
