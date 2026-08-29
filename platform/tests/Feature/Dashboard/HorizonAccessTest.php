<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Str;

/**
 * El tablero de Horizon muestra la cola COMPARTIDA: payloads de trabajos de
 * todos los tenants en la misma pantalla, más reintentar y borrar.
 *
 * La puerta que trae Horizon de fábrica es `app()->environment('local')`, y
 * este compose fija APP_ENV=local. Es decir: sin cerrarlo explícitamente,
 * queda abierto y anónimo en la entrega.
 */
beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['slug' => 'luna-'.Str::lower(Str::random(6))]);
});

it('está cerrado para un anónimo aunque el entorno sea local', function () {
    app()['env'] = 'local';

    $this->get('/horizon/api/stats')->assertForbidden();
});

it('está cerrado para un administrador mientras la bandera esté apagada', function () {
    app()['env'] = 'local';

    $admin = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::Admin]);

    // La bandera está apagada por defecto: ni el rol más alto entra.
    $this->actingAs($admin)->get('/horizon/api/stats')->assertForbidden();
});

it('con la bandera encendida sigue exigiendo administrador', function (UserRole $role, bool $entra) {
    app()['env'] = 'local';
    config(['d4s.horizon.dashboard_enabled' => true]);

    $user = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => $role]);

    $respuesta = $this->actingAs($user)->get('/horizon/api/stats');

    $entra
        ? $respuesta->assertOk()
        : $respuesta->assertForbidden();
})->with([
    'analista' => [UserRole::Analyst, false],
    'soporte' => [UserRole::Support, false],
    'administrador' => [UserRole::Admin, true],
]);

it('con la bandera encendida sigue cerrado para un anónimo', function () {
    app()['env'] = 'local';
    config(['d4s.horizon.dashboard_enabled' => true]);

    $this->get('/horizon/api/stats')->assertForbidden();
});
