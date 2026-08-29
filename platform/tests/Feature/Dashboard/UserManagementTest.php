<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\UserRole;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['slug' => 'luna-'.Str::random(6)]);
    $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::Admin]);

    $this->actingAs($this->admin);
});

it('crea un usuario dentro de la organización del administrador', function () {
    $this->post('/usuarios', [
        'name' => 'Nueva Analista',
        'email' => 'analista.nueva@example.test',
        'role' => 'analyst',
        'password' => 'clave-larga-y-segura',
        'password_confirmation' => 'clave-larga-y-segura',
    ])->assertRedirect();

    $creado = User::query()->where('email', 'analista.nueva@example.test')->first();

    expect($creado)->not->toBeNull()
        // El tenant sale del contexto de sesión, nunca del formulario.
        ->and($creado->tenant_id)->toBe($this->tenant->id)
        ->and($creado->role)->toBe(UserRole::Analyst)
        ->and($creado->is_active)->toBeTrue()
        ->and(Hash::check('clave-larga-y-segura', $creado->password))->toBeTrue();
});

it('ignora un tenant_id que venga en el formulario', function () {
    $otro = Tenant::factory()->create(['slug' => 'ajeno-'.Str::random(6)]);

    $this->post('/usuarios', [
        'tenant_id' => $otro->id,
        'name' => 'Intento',
        'email' => 'intento@example.test',
        'role' => 'analyst',
        'password' => 'clave-larga-y-segura',
        'password_confirmation' => 'clave-larga-y-segura',
    ])->assertRedirect();

    // Un tenant_id que llega del cliente es un dato a validar, jamás una
    // fuente de autoridad.
    expect(User::query()->where('email', 'intento@example.test')->value('tenant_id'))
        ->toBe($this->tenant->id);
});

it('acepta el mismo email si pertenece a otra organización', function () {
    $otro = Tenant::factory()->create(['slug' => 'otro-'.Str::random(6)]);
    User::factory()->create(['tenant_id' => $otro->id, 'email' => 'compartido@example.test']);

    $this->post('/usuarios', [
        'name' => 'Homónimo',
        'email' => 'compartido@example.test',
        'role' => 'support',
        'password' => 'clave-larga-y-segura',
        'password_confirmation' => 'clave-larga-y-segura',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(User::query()->where('email', 'compartido@example.test')->count())->toBe(2);
});

it('rechaza un email repetido dentro de la misma organización', function () {
    User::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'repetido@example.test']);

    $this->post('/usuarios', [
        'name' => 'Duplicado',
        'email' => 'repetido@example.test',
        'role' => 'analyst',
        'password' => 'clave-larga-y-segura',
        'password_confirmation' => 'clave-larga-y-segura',
    ])->assertSessionHasErrors('email');
});

it('cambia el rol de otro usuario', function () {
    $otro = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::Analyst]);

    $this->patch("/usuarios/{$otro->id}", ['role' => 'support'])->assertRedirect();

    expect($otro->fresh()->role)->toBe(UserRole::Support);
});

it('desactiva y reactiva a otro usuario', function () {
    $otro = User::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->patch("/usuarios/{$otro->id}", ['is_active' => false]);
    expect($otro->fresh()->is_active)->toBeFalse();

    $this->patch("/usuarios/{$otro->id}", ['is_active' => true]);
    expect($otro->fresh()->is_active)->toBeTrue();
});

it('no deja que un administrador se cambie el rol a sí mismo', function () {
    // Sin esta regla, el único administrador de una organización puede
    // dejarse afuera y hace falta tocar la base a mano para devolverle el
    // acceso.
    $this->patch("/usuarios/{$this->admin->id}", ['role' => 'analyst'])
        ->assertSessionHasErrors('role');

    expect($this->admin->fresh()->role)->toBe(UserRole::Admin);
});

it('no deja que un administrador se desactive a sí mismo', function () {
    $this->patch("/usuarios/{$this->admin->id}", ['is_active' => false])
        ->assertSessionHasErrors('role');

    expect($this->admin->fresh()->is_active)->toBeTrue();
});
