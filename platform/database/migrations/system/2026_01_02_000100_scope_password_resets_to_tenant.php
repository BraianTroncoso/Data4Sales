<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Acota los tokens de recuperacion al tenant.
 *
 * La tabla que trae Laravel tiene clave primaria `email` a secas, y eso da por
 * sentado que el email identifica a una persona en todo el sistema. Aca no:
 * el unico de `users` es (tenant_id, email) por decision explicita (D-08), y el
 * login pide organizacion justamente por eso.
 *
 * Con la clave vieja, dos usuarios que comparten email en organizaciones
 * distintas comparten fila: pedir un reset en una borra el token de la otra, y
 * peor, un token emitido para el usuario de un tenant valida contra el usuario
 * del otro. Es decir, el flujo de recuperacion era la unica puerta del sistema
 * que no distinguia tenants.
 *
 * Se borran los tokens vigentes al migrar. Son efimeros por definicion (expiran
 * en 60 minutos) y no se les puede adivinar el tenant retroactivamente: pedir
 * el enlace de nuevo es mas barato y mas honesto que inventar a quien pertenecen.
 */
return new class extends Migration
{
    public function getConnection(): string
    {
        return 'system';
    }

    public function up(): void
    {
        $connection = DB::connection($this->getConnection());

        $connection->table('password_reset_tokens')->delete();

        Schema::connection($this->getConnection())->table('password_reset_tokens', function ($table) {
            $table->uuid('tenant_id');
        });

        $connection->statement('ALTER TABLE password_reset_tokens DROP CONSTRAINT password_reset_tokens_pkey');
        $connection->statement('ALTER TABLE password_reset_tokens ADD PRIMARY KEY (tenant_id, email)');

        // El borrado en cascada es la contrapartida del aislamiento: si se da
        // de baja una organizacion, no queda un token suyo colgado sirviendo
        // para nada.
        $connection->statement(
            'ALTER TABLE password_reset_tokens
             ADD CONSTRAINT password_reset_tokens_tenant_id_foreign
             FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE'
        );
    }

    public function down(): void
    {
        $connection = DB::connection($this->getConnection());

        $connection->table('password_reset_tokens')->delete();

        $connection->statement('ALTER TABLE password_reset_tokens DROP CONSTRAINT password_reset_tokens_tenant_id_foreign');
        $connection->statement('ALTER TABLE password_reset_tokens DROP CONSTRAINT password_reset_tokens_pkey');

        Schema::connection($this->getConnection())->table('password_reset_tokens', function ($table) {
            $table->dropColumn('tenant_id');
        });

        $connection->statement('ALTER TABLE password_reset_tokens ADD PRIMARY KEY (email)');
    }
};
