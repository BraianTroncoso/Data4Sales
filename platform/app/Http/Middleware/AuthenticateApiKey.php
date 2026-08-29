<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenancy\ApiKeyAuthenticator;
use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puerta de entrada de la API.
 *
 * Hace tres cosas en orden, y ninguna se puede saltear:
 *
 *   1. Resuelve la API key. Sin clave valida no hay tenant.
 *   2. Verifica el alcance requerido por la ruta. Una clave de ingesta no
 *      deberia poder leer el padron de clientes: separar alcances permite
 *      darle al pipeline del retailer una credencial que solo escribe.
 *   3. Activa el contexto de tenant, que resuelve la conexion y publica
 *      app.tenant_id para las politicas de Row-Level Security.
 *
 * El paso 3 es el que conecta la autenticacion con el aislamiento. A partir
 * de ahi, ninguna consulta del request puede ver datos de otro tenant, ni
 * siquiera si el codigo estuviera mal escrito.
 *
 * El tenant NUNCA se toma de un parametro del request. Sale de la credencial
 * y solo de ahi: un tenant_id en el body es un dato mas a validar, jamas una
 * fuente de autoridad.
 */
class AuthenticateApiKey
{
    public function __construct(
        private readonly ApiKeyAuthenticator $authenticator,
        private readonly TenantContext $context,
    ) {}

    public function handle(Request $request, Closure $next, ?string $scope = null): Response
    {
        $key = $this->authenticator->authenticate($this->extractSecret($request));

        if ($key === null) {
            return $this->deny('Credencial ausente, inválida o revocada.', 401);
        }

        if ($scope !== null && ! $key->hasScope($scope)) {
            return $this->deny("La credencial no tiene el alcance [{$scope}].", 403);
        }

        $this->context->enter($key->tenant);

        $request->attributes->set('api_key', $key);
        $request->attributes->set('tenant', $key->tenant);

        try {
            return $next($request);
        } finally {
            // php-fpm estrena conexion en cada request, pero octane y los
            // workers no. Limpiar siempre hace que el comportamiento no
            // dependa de como este desplegada la aplicacion.
            $this->context->leave();
        }
    }

    /**
     * Acepta `Authorization: Bearer <secreto>`. Se soporta tambien el header
     * `X-API-Key` porque muchos pipelines de retailers no pueden mandar
     * cabeceras de autorizacion arbitrarias.
     */
    private function extractSecret(Request $request): ?string
    {
        $bearer = $request->bearerToken();

        if ($bearer !== null && $bearer !== '') {
            return $bearer;
        }

        $header = $request->header('X-API-Key');

        return is_string($header) && $header !== '' ? $header : null;
    }

    private function deny(string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => $status === 401 ? 'unauthenticated' : 'forbidden',
                'message' => $message,
            ],
        ], $status);
    }
}
