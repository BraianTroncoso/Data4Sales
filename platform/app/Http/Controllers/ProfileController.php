<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
        ]);
    }

    /**
     * Actualiza el perfil del propio usuario.
     *
     * Ya no se anula `email_verified_at` al cambiar el email. El andamiaje de
     * Breeze lo hacia para forzar una nueva verificacion, pero este producto no
     * tiene flujo de verificacion: los usuarios los crea un administrador
     * (D-11). Anularlo dejaria al usuario en un estado que nada puede resolver.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());
        $request->user()->save();

        return Redirect::route('profile.edit');
    }

    /*
     * No hay borrado de la propia cuenta.
     *
     * Breeze lo trae porque asume un producto con autoservicio, donde la
     * cuenta es del usuario. Acá no lo es: las cuentas las crea un
     * administrador dentro de su organización (D-11), y el mecanismo de baja
     * es desactivar, no borrar.
     *
     * La ruta que estaba dejaba pasar exactamente lo que UserController::update
     * bloquea una pantalla más allá: el único administrador de un tenant
     * podía sacarse a sí mismo del sistema, y sin usuarios que puedan crear
     * usuarios la organización queda muerta hasta que alguien toque la base a
     * mano. Borrar además arrastraría el rastro de quién hizo qué.
     */
}
