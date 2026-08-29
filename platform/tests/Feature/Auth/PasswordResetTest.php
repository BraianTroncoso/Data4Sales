<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * La recuperación de contraseña es la única puerta del sistema que Laravel
 * resuelve por email a secas. Acá el email no identifica a nadie: el único de
 * `users` es (tenant_id, email) por decisión explícita (D-08).
 *
 * El caso que importa es el que justifica esa decisión: la misma dirección en
 * dos organizaciones.
 */
beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['slug' => 'luna-'.Str::lower(Str::random(6))]);
});

function usuarioDe(Tenant $tenant, string $email): User
{
    return User::factory()->create(['tenant_id' => $tenant->id, 'email' => $email]);
}

test('la pantalla del pedido se renderiza', function () {
    $this->get('/forgot-password')->assertOk();
});

test('el pedido exige organización', function () {
    $this->from('/forgot-password')
        ->post('/forgot-password', ['email' => 'quien@luna.test'])
        ->assertSessionHasErrors('tenant');
});

test('se puede pedir el enlace', function () {
    Notification::fake();

    $user = usuarioDe($this->tenant, 'admin@luna.test');

    $this->post('/forgot-password', [
        'tenant' => $this->tenant->slug,
        'email' => $user->email,
    ])->assertSessionHasNoErrors();

    Notification::assertSentTo($user, ResetPassword::class);
});

test('el enlace se le manda al usuario de la organización pedida, no al del mismo email en otra', function () {
    Notification::fake();

    $otra = Tenant::factory()->create(['slug' => 'andes-'.Str::lower(Str::random(6))]);

    $deLuna = usuarioDe($this->tenant, 'contacto@retail.test');
    $deAndes = usuarioDe($otra, 'contacto@retail.test');

    $this->post('/forgot-password', [
        'tenant' => $this->tenant->slug,
        'email' => 'contacto@retail.test',
    ]);

    Notification::assertSentTo($deLuna, ResetPassword::class);
    Notification::assertNotSentTo($deAndes, ResetPassword::class);
});

test('un token emitido para una organización no sirve para la otra', function () {
    Notification::fake();

    $otra = Tenant::factory()->create(['slug' => 'andes-'.Str::lower(Str::random(6))]);

    $deLuna = usuarioDe($this->tenant, 'contacto@retail.test');
    $deAndes = usuarioDe($otra, 'contacto@retail.test');
    $claveOriginalDeAndes = $deAndes->password;

    $this->post('/forgot-password', [
        'tenant' => $this->tenant->slug,
        'email' => 'contacto@retail.test',
    ]);

    Notification::assertSentTo($deLuna, ResetPassword::class, function ($notification) use ($otra, $deAndes, $claveOriginalDeAndes) {
        // Este es el ataque: token legítimo de luna, presentado con el slug
        // de andes. Con la tabla original —clave primaria `email` a secas—
        // cambiaba la contraseña del usuario equivocado.
        $this->from('/reset-password/'.$notification->token)
            ->post('/reset-password', [
                'token' => $notification->token,
                'tenant' => $otra->slug,
                'email' => 'contacto@retail.test',
                'password' => 'contrasena-nueva',
                'password_confirmation' => 'contrasena-nueva',
            ])
            ->assertSessionHasErrors('email');

        expect($deAndes->fresh()->password)->toBe($claveOriginalDeAndes);

        return true;
    });
});

test('pedir el enlace en una organización no invalida el token vigente de la otra', function () {
    Notification::fake();

    $otra = Tenant::factory()->create(['slug' => 'andes-'.Str::lower(Str::random(6))]);

    usuarioDe($this->tenant, 'contacto@retail.test');
    usuarioDe($otra, 'contacto@retail.test');

    $this->post('/forgot-password', ['tenant' => $this->tenant->slug, 'email' => 'contacto@retail.test']);
    $this->post('/forgot-password', ['tenant' => $otra->slug, 'email' => 'contacto@retail.test']);

    // Con la clave primaria vieja compartían fila y quedaba uno solo.
    $filas = DB::connection('system')
        ->table('password_reset_tokens')
        ->where('email', 'contacto@retail.test')
        ->count();

    expect($filas)->toBe(2);
});

test('la pantalla de la nueva contraseña se renderiza', function () {
    Notification::fake();

    $user = usuarioDe($this->tenant, 'admin@luna.test');

    $this->post('/forgot-password', ['tenant' => $this->tenant->slug, 'email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
        $this->get('/reset-password/'.$notification->token)->assertOk();

        return true;
    });
});

test('la contraseña se cambia con un token válido', function () {
    Notification::fake();

    $user = usuarioDe($this->tenant, 'admin@luna.test');

    $this->post('/forgot-password', ['tenant' => $this->tenant->slug, 'email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
        $this->post('/reset-password', [
            'token' => $notification->token,
            'tenant' => $this->tenant->slug,
            'email' => $user->email,
            'password' => 'contrasena-nueva',
            'password_confirmation' => 'contrasena-nueva',
        ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login'));

        expect(Hash::check('contrasena-nueva', $user->fresh()->password))->toBeTrue();

        return true;
    });
});

test('un usuario desactivado no recupera acceso por esta puerta', function () {
    Notification::fake();

    $user = usuarioDe($this->tenant, 'baja@luna.test');
    $user->update(['is_active' => false]);

    $this->post('/forgot-password', [
        'tenant' => $this->tenant->slug,
        'email' => $user->email,
    ])->assertSessionHasNoErrors();

    Notification::assertNothingSent();
});

test('no revela si la organización o la cuenta existen', function () {
    Notification::fake();

    // Organización inexistente y email inexistente devuelven exactamente lo
    // mismo que un pedido válido: sin errores y con el mismo mensaje. Es la
    // misma postura que toma el login.
    $this->post('/forgot-password', ['tenant' => 'no-existe', 'email' => 'nadie@nada.test'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', __('passwords.sent'));

    $this->post('/forgot-password', ['tenant' => $this->tenant->slug, 'email' => 'nadie@nada.test'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', __('passwords.sent'));

    Notification::assertNothingSent();
});
