<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Activa el contexto de tenant del usuario autenticado.
 *
 * Es el equivalente, para el dashboard, de lo que hace AuthenticateApiKey en
 * la API: resuelve la conexión y publica `app.tenant_id` para las políticas
 * de Row-Level Security.
 *
 * El tenant sale de la SESIÓN, nunca de la URL ni del cuerpo del request. Un
 * `tenant_id` que llega del cliente es un dato a validar, jamás una fuente de
 * autoridad — si lo fuera, cambiar un parámetro alcanzaría para ver los datos
 * de otro retailer.
 */
class BindTenantContext
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Un usuario sin tenant no debería existir: la columna es obligatoria
        // y tiene clave foránea. Si pasara, cerramos la sesión en vez de
        // seguir con un contexto a medias.
        if ($user === null || $user->tenant === null || ! $user->tenant->is_active) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        $this->context->enter($user->tenant);

        try {
            return $next($request);
        } finally {
            $this->context->leave();
        }
    }
}
