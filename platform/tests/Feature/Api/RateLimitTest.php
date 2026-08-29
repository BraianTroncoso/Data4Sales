<?php

declare(strict_types=1);

use App\Domain\Tenancy\Models\ApiKey;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * La API pública tenía techo sólo en el login del dashboard. Una credencial
 * filtrada podía recorrer el padrón entero a máxima velocidad, y un pipeline
 * con un bucle mal escrito podía tumbar la ingesta de todos los tenants que
 * comparten cluster.
 */
beforeEach(function () {
    RateLimiter::clear('d4s-query');

    $this->tenant = Tenant::factory()->create(['slug' => 'luna-'.Str::lower(Str::random(6))]);

    [, $this->clave] = ApiKey::issue($this->tenant, 'test', ['query:read']);
});

function pedir(string $clave): TestResponse
{
    return test()->getJson('/api/v1/customers', ['Authorization' => 'Bearer '.$clave]);
}

it('corta con 429 y responde en JSON cuando se pasa del cupo', function () {
    config(['d4s.api.rate_limits.query' => 3]);

    for ($i = 0; $i < 3; $i++) {
        pedir($this->clave)->assertOk();
    }

    pedir($this->clave)
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        // JSON y no la página HTML de error: el cliente de esta API es un
        // pipeline, y recibir HTML donde espera JSON es el 429 más difícil
        // de diagnosticar.
        ->assertJsonStructure(['message', 'retry_after_seconds']);
});

it('cuenta por credencial, no por IP', function () {
    config(['d4s.api.rate_limits.query' => 2]);

    $otroTenant = Tenant::factory()->create(['slug' => 'andes-'.Str::lower(Str::random(6))]);

    [, $otraClave] = ApiKey::issue($otroTenant, 'test', ['query:read']);

    pedir($this->clave)->assertOk();
    pedir($this->clave)->assertOk();
    pedir($this->clave)->assertStatus(429);

    // Misma IP, otra organización: no arrastra el cupo del vecino. Contar por
    // IP mezclaría organizaciones que no tienen nada que ver entre sí.
    pedir($otraClave)->assertOk();
});

it('también limita a quien prueba credenciales inventadas', function () {
    config(['d4s.api.rate_limits.query' => 2]);

    // Es tráfico que nunca llega a autenticarse, así que un limitador puesto
    // después del middleware de credencial no lo vería nunca.
    $inventada = 'd4s_no_existe_'.Str::random(20);

    pedir($inventada)->assertUnauthorized();
    pedir($inventada)->assertUnauthorized();
    pedir($inventada)->assertStatus(429);
});
