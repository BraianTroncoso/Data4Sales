<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Str;

/**
 * La consola de API entrega una credencial, y una credencial de API salta por
 * encima de la tabla de roles del dashboard: quien la tiene lee el padrón
 * completo por HTTP aunque /clientes le dé 403.
 *
 * Por eso esta pantalla arrastra tres candados, y hay un test por candado.
 */
beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['slug' => 'luna-'.Str::lower(Str::random(6))]);
});

function comoAdminDe(Tenant $tenant): User
{
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::Admin]);

    test()->actingAs($user);

    return $user;
}

it('no existe fuera del entorno local, ni siquiera para un administrador', function () {
    // El entorno de tests NO es local, así que esto prueba el caso real.
    //
    // 404 y no 403: un 403 confirmaría que la ruta existe. Para una
    // herramienta que muestra credenciales, la respuesta correcta es
    // "acá no hay nada".
    comoAdminDe($this->tenant);

    $this->get('/api-console')->assertNotFound();
});

it('no se sirve sin sesión', function () {
    app()['env'] = 'local';

    // Anónimo va al login. No hay página, y por lo tanto no hay credencial.
    $this->get('/api-console')->assertRedirect('/login');
});

it('respeta la tabla de roles: sólo el administrador la ve', function (UserRole $role, int $esperado) {
    app()['env'] = 'local';

    $user = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => $role]);

    $this->actingAs($user)->get('/api-console')->assertStatus($esperado);
})->with([
    'analista' => [UserRole::Analyst, 403],
    'soporte' => [UserRole::Support, 403],
    'administrador' => [UserRole::Admin, 200],
]);

it('muestra sólo el tenant de quien mira, nunca el de otro', function () {
    app()['env'] = 'local';

    $ajeno = Tenant::factory()->create(['slug' => 'andes-'.Str::lower(Str::random(6))]);

    comoAdminDe($this->tenant);

    // La fuga cruzada sería peor que la de rol: un administrador de luna
    // leyendo el padrón de andes con la credencial de andes.
    $this->get('/api-console')
        ->assertOk()
        ->assertSee('Consola de API')
        ->assertSee($this->tenant->slug)
        ->assertDontSee($ajeno->slug)
        // Autocontenida: sin CDN ni recursos de terceros.
        ->assertDontSee('cdn.jsdelivr.net')
        ->assertDontSee('unpkg.com');
});
