<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de tenants: el corazon del plano de control.
 *
 * `isolation_strategy` es lo que hace posible la estrategia hibrida. Un
 * tenant nace `shared` (sus ventas viven en el cluster compartido, aislado
 * por RLS) y puede promoverse a `dedicated` (base propia) sin que cambie
 * una sola linea del codigo de dominio: lo unico que cambia es la conexion
 * que devuelve el resolver.
 *
 * Notar que aca NO se guardan credenciales de base de datos, solo el
 * nombre logico de la conexion y el de la base. Las credenciales salen del
 * entorno; en produccion saldrian de un gestor de secretos. Guardar un DSN
 * completo en una tabla convierte un SELECT en una fuga de acceso total.
 *
 * `timezone` no es cosmetico: los reportes se agrupan por el dia del
 * tenant, no por el dia UTC. Un retailer argentino que vende a las 22:00
 * espera ver esa venta hoy, no manana.
 */
return new class extends Migration
{
    public function getConnection(): string
    {
        return 'system';
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->create('tenants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug', 64)->unique();
            $table->string('name');

            $table->string('isolation_strategy', 16)->default('shared');
            $table->string('connection_name', 64)->nullable();
            $table->string('database_name', 64)->nullable();

            $table->string('timezone', 64)->default('UTC');
            $table->char('reporting_currency', 3)->default('USD');

            $table->unsignedInteger('ingest_max_batch_size')->default(5000);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index('isolation_strategy');
        });

        $db = DB::connection($this->getConnection());

        // La estrategia solo admite dos valores, y `dedicated` obliga a
        // declarar donde vive la base. Que lo garantice el motor evita un
        // tenant a medio promover: apuntado a ningun lado y sin datos.
        $db->statement(
            "ALTER TABLE tenants ADD CONSTRAINT tenants_isolation_strategy_check
             CHECK (isolation_strategy IN ('shared', 'dedicated'))"
        );

        $db->statement(
            "ALTER TABLE tenants ADD CONSTRAINT tenants_dedicated_requires_connection_check
             CHECK (
                 isolation_strategy <> 'dedicated'
                 OR (connection_name IS NOT NULL AND database_name IS NOT NULL)
             )"
        );
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('tenants');
    }
};
