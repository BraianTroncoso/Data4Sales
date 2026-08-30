<?php

declare(strict_types=1);

use App\Domain\Outreach\Http\UnsubscribeController;
use App\Http\Controllers\ProfileController;
use App\Http\Dashboard\Controllers\ApiConsoleController;
use App\Http\Dashboard\Controllers\CustomerController;
use App\Http\Dashboard\Controllers\OrderController;
use App\Http\Dashboard\Controllers\StatisticsController;
use App\Http\Dashboard\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Dashboard
|--------------------------------------------------------------------------
|
| Autenticación por sesión. Cada grupo declara la CAPACIDAD que exige, no el
| rol: cuando mañana un cuarto rol tenga que ver estadísticas se toca el
| enum, no cada ruta.
|
| El control vive acá, en el servidor. Que el menú oculte un enlace es
| cortesía para el usuario, no control de acceso: el enunciado pide
| explícitamente que no se pueda acceder "ni desde la interfaz ni por fuera
| de ella".
|
*/

Route::get('/', fn () => redirect()->route('dashboard'))->name('home');

/*
 * Sin `verified`: este producto no tiene auto-registro, los usuarios los crea
 * un administrador dentro de su organización (D-11), así que no hay nada que
 * verificar. El middleware estaba puesto y no hacía nada — `EnsureEmailIsVerified`
 * deja pasar a cualquier usuario que no implemente MustVerifyEmail, y el
 * nuestro no lo implementa. Middleware que parece proteger y no protege es peor
 * que no tenerlo.
 */
Route::middleware(['auth', 'tenant'])->group(function (): void {

    // Punto de entrada. Manda a cada rol a la única pantalla que puede ver,
    // en vez de a un panel vacío con todo deshabilitado.
    Route::get('dashboard', function () {
        $user = request()->user();

        return match (true) {
            $user->canViewStatistics() => redirect()->route('dashboard.statistics'),
            $user->canViewCustomerData() => redirect()->route('dashboard.customers.index'),
            default => abort(403, 'Tu cuenta no tiene ninguna sección asignada.'),
        };
    })->name('dashboard');

    // ── Estadísticas: analista y administrador ───────────────────
    Route::middleware('can.role:statistics')->group(function (): void {
        Route::get('estadisticas', StatisticsController::class)->name('dashboard.statistics');
    });

    // ── Datos de clientes: soporte y administrador ───────────────
    Route::middleware('can.role:customers')->group(function (): void {
        Route::get('clientes', [CustomerController::class, 'index'])->name('dashboard.customers.index');
        Route::get('clientes/{customer}', [CustomerController::class, 'show'])->name('dashboard.customers.show');

        // El detalle de una orden cuelga del cliente y no de /ordenes a secas
        // porque no hay forma legítima de llegar a una orden sin saber de
        // quién es: la URL anidada hace que el control de acceso y la
        // navegación cuenten la misma historia.
        Route::get('clientes/{customer}/ordenes/{order}', [OrderController::class, 'show'])
            ->name('dashboard.customers.orders.show');
    });

    // ── Usuarios: sólo administrador ─────────────────────────────
    Route::middleware('can.role:users')->group(function (): void {
        Route::get('usuarios', [UserController::class, 'index'])->name('dashboard.users.index');
        Route::post('usuarios', [UserController::class, 'store'])->name('dashboard.users.store');
        Route::patch('usuarios/{user}', [UserController::class, 'update'])->name('dashboard.users.update');

        /*
         * Consola de API: herramienta de evaluación, no parte del producto.
         *
         * Vive bajo la MISMA capacidad que /usuarios y no bajo una comprobación
         * de entorno a secas, porque entrega una API key — y una API key salta
         * por encima de esta tabla de roles: quien la tiene lee el padrón
         * completo por HTTP. Dejarla accesible a un analista, o sin sesión,
         * anula la Parte 2 desde una pantalla auxiliar.
         *
         * El controlador agrega los otros dos candados: 404 fuera de `local`, y
         * sólo la clave del tenant de quien mira.
         */
        Route::get('api-console', ApiConsoleController::class)->name('api-console');
    });

    // ── Perfil propio ────────────────────────────────────────────
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    // Sin DELETE: la baja de un usuario es desactivarlo desde /usuarios, no
    // que se borre a sí mismo. Ver ProfileController.
});

/*
|--------------------------------------------------------------------------
| Baja de suscripción
|--------------------------------------------------------------------------
|
| Pública y firmada. Pública porque el destinatario es un comprador, no un
| usuario de la plataforma: exigirle una cuenta para dejar de recibir correos
| es la forma más rápida de que marque el mensaje como spam.
|
| El GET confirma y el POST ejecuta. Los clientes de correo y los escáneres de
| seguridad abren los enlaces de un mensaje automáticamente; con la baja en el
| GET, media lista se daría de baja sola.
|
*/
Route::middleware('signed')->group(function (): void {
    Route::get('baja/{tenant}/{campaign}/{hash}', [UnsubscribeController::class, 'show'])
        ->name('outreach.unsubscribe');

    Route::post('baja/{tenant}/{campaign}/{hash}', [UnsubscribeController::class, 'store'])
        ->name('outreach.unsubscribe.store');
});

require __DIR__.'/auth.php';
