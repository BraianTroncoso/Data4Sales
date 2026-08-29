<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Str;

/**
 * La tabla de roles del enunciado, convertida en tests.
 *
 *   Rol                Estadísticas   Clientes   Usuarios
 *   Analista           Sí             No         No
 *   Customer Support   No             Sí         No
 *   Administrador      Sí             Sí         Sí
 *
 * Y la regla que la atraviesa: "no debe poder hacerlo ni desde la interfaz
 * ni por fuera de ella". Estos tests van por fuera: piden la URL
 * directamente, sin pasar por ningún menú.
 */
beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['slug' => 'luna-'.Str::random(6)]);
});

function comoRol(Tenant $tenant, UserRole $role): User
{
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => $role]);

    test()->actingAs($user);

    return $user;
}

it('respeta la tabla de roles del enunciado', function (UserRole $role, string $ruta, int $esperado) {
    comoRol($this->tenant, $role);

    test()->get($ruta)->assertStatus($esperado);
})->with([
    // Analista: sólo estadísticas.
    'analista → estadísticas' => [UserRole::Analyst, '/estadisticas', 200],
    'analista → clientes' => [UserRole::Analyst, '/clientes', 403],
    'analista → usuarios' => [UserRole::Analyst, '/usuarios', 403],

    // Customer Support: sólo datos de clientes.
    'soporte → estadísticas' => [UserRole::Support, '/estadisticas', 403],
    'soporte → clientes' => [UserRole::Support, '/clientes', 200],
    'soporte → usuarios' => [UserRole::Support, '/usuarios', 403],

    // Administrador: todo.
    'admin → estadísticas' => [UserRole::Admin, '/estadisticas', 200],
    'admin → clientes' => [UserRole::Admin, '/clientes', 200],
    'admin → usuarios' => [UserRole::Admin, '/usuarios', 200],
]);

it('bloquea también las acciones de escritura, no sólo las pantallas', function (UserRole $role) {
    comoRol($this->tenant, $role);

    // Ocultar el formulario no es control de acceso. El endpoint tiene que
    // rechazar igual.
    test()->post('/usuarios', [
        'name' => 'Intruso',
        'email' => 'intruso@example.test',
        'role' => 'admin',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertStatus(403);

    expect(User::query()->where('email', 'intruso@example.test')->exists())->toBeFalse();
})->with([UserRole::Analyst, UserRole::Support]);

it('manda a cada rol a la pantalla que puede ver', function (UserRole $role, string $destino) {
    comoRol($this->tenant, $role);

    test()->get('/dashboard')->assertRedirect($destino);
})->with([
    'analista' => [UserRole::Analyst, '/estadisticas'],
    'soporte' => [UserRole::Support, '/clientes'],
    'admin' => [UserRole::Admin, '/estadisticas'],
]);

it('exige sesión para todo el dashboard', function (string $ruta) {
    test()->get($ruta)->assertRedirect('/login');
})->with(['/dashboard', '/estadisticas', '/clientes', '/usuarios']);
