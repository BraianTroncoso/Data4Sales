<?php

use App\Domain\Identity\Models\User;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get('/profile');

    $response->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch('/profile', [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile');

    $user->refresh();

    $this->assertSame('Test User', $user->name);
    $this->assertSame('test@example.com', $user->email);
});

test('el email del perfil se puede dejar igual sin efectos secundarios', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch('/profile', [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

    // Ya no se anula email_verified_at: este producto no tiene flujo de
    // verificación, así que anularlo dejaría al usuario en un estado que
    // nada puede resolver.
    $response->assertSessionHasNoErrors()->assertRedirect('/profile');

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('no se puede borrar la propia cuenta', function () {
    $user = User::factory()->create();

    // 405 y no 404 porque /profile sigue existiendo para GET y PATCH: el
    // método DELETE es el que ya no está declarado.
    //
    // Un administrador que se borra a sí mismo deja a su organización sin
    // nadie que pueda crear usuarios, que es justo lo que
    // UserController::update bloquea una pantalla más allá. La baja de un
    // usuario es desactivarlo desde /usuarios.
    $this->actingAs($user)
        ->delete('/profile', ['password' => 'password'])
        ->assertStatus(405);

    expect($user->fresh())->not->toBeNull();
});
