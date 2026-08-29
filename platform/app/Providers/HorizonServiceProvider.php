<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Horizon;

/**
 * Cierra el tablero de Horizon.
 *
 * Horizon trae una puerta por defecto que devuelve `app()->environment('local')`,
 * y este compose fija APP_ENV=local. Sin este proveedor, /horizon responde 200
 * a un anonimo: la cola de todos los tenants con sus payloads, y los botones de
 * reintentar y borrar trabajos.
 *
 * La regla que se aplica es la del resto del sistema, no una excepcion: la cola
 * es compartida, asi que el tablero cruza tenants por construccion. Ningun
 * usuario de esta plataforma pertenece a mas de una organizacion, asi que
 * ninguno tiene derecho a esa pantalla. Se cierra para todos.
 *
 * Queda una salida deliberada para depurar, con DOS condiciones a la vez:
 * la bandera D4S_HORIZON_DASHBOARD encendida a mano por el operador, y sesion
 * de administrador. Apagada por defecto. La via normal de operacion es la CLI
 * dentro del contenedor (`php artisan horizon:status`), que no expone payloads
 * de nadie.
 */
class HorizonServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Horizon::auth(function (Request $request): bool {
            if (config('d4s.horizon.dashboard_enabled') !== true) {
                return false;
            }

            $user = $request->user();

            return $user instanceof User && $user->canManageUsers();
        });
    }
}
