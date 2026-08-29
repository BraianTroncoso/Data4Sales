<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->luna = Tenant::factory()->create(['slug' => 'luna-'.Str::random(6)]);
    $this->andes = Tenant::factory()->create(['slug' => 'andes-'.Str::random(6)]);
});

function usuario(Tenant $tenant, UserRole $role = UserRole::Admin, array $extra = []): User
{
    return User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => $role,
        ...$extra,
    ]);
}

function ingresar(string $tenantSlug, string $email, string $password = 'password'): TestResponse
{
    return test()->post('/login', [
        'tenant' => $tenantSlug,
        'email' => $email,
        'password' => $password,
    ]);
}

it('deja ingresar con la organización, el email y la contraseña correctos', function () {
    $user = usuario($this->luna);

    ingresar($this->luna->slug, $user->email)->assertRedirect();

    expect(auth()->check())->toBeTrue()
        ->and(auth()->id())->toBe($user->id);
});

it('no deja ingresar a la organización equivocada con credenciales válidas', function () {
    $user = usuario($this->luna);

    // Mismo email, misma contraseña, otro tenant. El tenant_id va DENTRO de
    // las credenciales, no como filtro posterior: nunca existe un instante en
    // el que este usuario quede autenticado.
    ingresar($this->andes->slug, $user->email)->assertSessionHasErrors('email');

    expect(auth()->check())->toBeFalse();
});

it('permite el mismo email en organizaciones distintas', function () {
    $email = 'contacto@example.test';

    $unoLuna = usuario($this->luna, extra: ['email' => $email]);
    $unoAndes = usuario($this->andes, extra: ['email' => $email]);

    // El email es credencial dentro de un tenant, no identidad global.
    ingresar($this->luna->slug, $email);
    expect(auth()->id())->toBe($unoLuna->id);

    auth()->logout();

    ingresar($this->andes->slug, $email);
    expect(auth()->id())->toBe($unoAndes->id);
});

it('no distingue una organización inexistente de una contraseña incorrecta', function () {
    $user = usuario($this->luna);

    $inexistente = ingresar('no-existe-'.Str::random(6), $user->email);
    $malaClave = ingresar($this->luna->slug, $user->email, 'incorrecta');

    // Mensajes idénticos: distinguirlos le diría a quien prueba credenciales
    // qué organizaciones existen en la plataforma.
    expect($inexistente->getSession()->get('errors')->first('email'))
        ->toBe($malaClave->getSession()->get('errors')->first('email'));
});

it('no deja ingresar a un usuario desactivado', function () {
    $user = usuario($this->luna, extra: ['is_active' => false]);

    ingresar($this->luna->slug, $user->email)->assertSessionHasErrors('email');

    expect(auth()->check())->toBeFalse();
});

it('no deja ingresar si la organización está desactivada', function () {
    $user = usuario($this->luna);
    $this->luna->update(['is_active' => false]);

    ingresar($this->luna->slug, $user->email)->assertSessionHasErrors('email');

    expect(auth()->check())->toBeFalse();
});

it('ya no expone el registro público', function () {
    // En una plataforma B2B multi-tenant, un alta pública es una vía directa
    // a crear cuentas sin organización. Los usuarios los crea un
    // administrador dentro de su tenant.
    $this->get('/register')->assertNotFound();
    $this->post('/register', [])->assertNotFound();
});
