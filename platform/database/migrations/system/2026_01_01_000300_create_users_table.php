<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Usuarios del dashboard. Distintos de los clientes/compradores: estos son
 * los empleados del retailer que usan la plataforma.
 *
 * La decision que mas se nota esta en el indice unico: es (tenant_id,
 * email), no email a secas. El email no es un identificador global. La
 * misma persona puede trabajar para dos retailers, y dos retailers pueden
 * tener un contacto@ cada uno. Modelarlo como unico global obligaria a
 * inventar aliases y romperia el aislamiento conceptual entre tenants.
 *
 * La consecuencia es que el login necesita saber el tenant: la pantalla
 * pide slug del tenant + email + contrasena, igual que Slack pide el
 * workspace. Es mas realista para B2B que un email global.
 *
 * El rol vive aca y no en una tabla de permisos porque son tres roles
 * fijos definidos por el enunciado. Un CHECK del motor es mas barato y mas
 * seguro que una tabla de roles para un dominio cerrado; si manana los
 * roles fueran configurables por tenant, esto se convierte en tabla.
 */
return new class extends Migration
{
    public function getConnection(): string
    {
        return 'system';
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');

            $table->string('name');
            $table->string('email');
            $table->string('password');
            $table->string('role', 16);

            $table->boolean('is_active')->default(true);
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'email']);
            $table->index(['tenant_id', 'role']);
        });

        DB::connection($this->getConnection())->statement(
            "ALTER TABLE users ADD CONSTRAINT users_role_check
             CHECK (role IN ('analyst', 'support', 'admin'))"
        );
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('users');
    }
};
