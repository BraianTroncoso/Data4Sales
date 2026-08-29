<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API keys por tenant. Es lo unico que autentica la ingesta y la API de
 * consulta, y lo que determina sobre que datos se puede leer y escribir.
 *
 * Sobre el hash: se guarda SHA-256 del secreto, no bcrypt. Es deliberado y
 * a contramano del reflejo habitual, por dos razones:
 *
 *   1. Una API key la genera el sistema con 256 bits de entropia. El
 *      trabajo de bcrypt existe para frenar fuerza bruta contra secretos
 *      que eligio un humano; contra un secreto aleatorio de ese tamano no
 *      aporta nada, porque el espacio de busqueda ya es inalcanzable.
 *   2. bcrypt no se puede indexar. Autenticar contra el implicaria traer
 *      todas las keys y compararlas de a una en cada request. SHA-256
 *      permite un lookup por indice unico, en tiempo constante, sin
 *      importar cuantos tenants haya.
 *
 * El `prefix` se guarda en claro a proposito: permite mostrar e identificar
 * una key en la interfaz ("d4s_live_a1b2...") y rastrearla en los logs sin
 * exponer nunca el secreto completo.
 */
return new class extends Migration
{
    public function getConnection(): string
    {
        return 'system';
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->create('api_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name');

            $table->string('prefix', 24);
            $table->char('token_hash', 64)->unique();

            // Una key de ingesta no deberia poder leer el padron de
            // clientes. Separar los alcances permite darle al pipeline del
            // retailer una credencial que solo escribe.
            $table->jsonb('scopes')->default('["ingest:write","query:read"]');

            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('api_keys');
    }
};
