<?php

declare(strict_types=1);

use App\Domain\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ventas agregadas por producto y dia.
 *
 * Existe por una razon concreta: "top productos por revenue" sobre el rango
 * completo del dataset implicaria recorrer y agrupar millones de renglones
 * de orden en cada carga de pantalla. Precalcularlo por dia convierte esa
 * consulta en una suma sobre unos pocos miles de filas.
 *
 * La categoria se DESNORMALIZA a proposito. Duplica un dato que ya esta en
 * products, pero evita un join contra el catalogo en la consulta mas usada
 * de la pantalla de estadisticas. Es la clase de duplicacion que se paga
 * sola: la categoria de un producto casi no cambia, y cuando cambia el
 * recomputo del dia la actualiza.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_product_facts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('tenant_id');
            $table->date('date');
            $table->uuid('product_id');
            $table->string('category', 128)->nullable();

            $table->unsignedInteger('orders_count')->default(0);
            $table->unsignedInteger('units')->default(0);
            $table->bigInteger('reporting_revenue_minor')->default(0);

            $table->timestampTz('computed_at');

            $table->unique(['tenant_id', 'date', 'product_id']);

            // El indice por categoria soporta la vista agrupada de la
            // pantalla sin tener que tocar la tabla de productos.
            $table->index(['tenant_id', 'date', 'category']);
            $table->index(['tenant_id', 'reporting_revenue_minor']);

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });

        RowLevelSecurity::protect('daily_product_facts');
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_product_facts');
    }
};
