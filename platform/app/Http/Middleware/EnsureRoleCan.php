<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Control de acceso por rol, en el servidor.
 *
 * El enunciado es explícito: un usuario que intenta acceder a una pantalla o
 * a un dato que no le corresponde no debe poder hacerlo "ni desde la interfaz
 * ni por fuera de ella". Ocultar el enlace en el menú no es control de
 * acceso: es cortesía. El control está acá, y la interfaz simplemente refleja
 * lo mismo.
 *
 * Se declaran CAPACIDADES y no roles (`can:statistics` en vez de
 * `role:analyst`). La diferencia importa cuando mañana un cuarto rol tenga
 * que ver estadísticas: se toca el enum, no las veinte rutas que lo usan.
 */
class EnsureRoleCan
{
    public function handle(Request $request, Closure $next, string $capability): Response
    {
        $user = $request->user();

        $allowed = $user !== null && match ($capability) {
            'statistics' => $user->canViewStatistics(),
            'customers' => $user->canViewCustomerData(),
            'users' => $user->canManageUsers(),
            default => false,
        };

        if (! $allowed) {
            // 403 y no un redirect silencioso: el usuario tiene que saber que
            // el recurso existe y que no le corresponde, no quedarse pensando
            // que la aplicación se rompió. Qué existe dentro de su propio
            // tenant no es información sensible; quién puede verlo, sí.
            throw new AccessDeniedHttpException(
                'Tu rol no tiene acceso a esta sección.'
            );
        }

        return $next($request);
    }
}
