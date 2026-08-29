<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Limites de peticiones de la API publica.
 *
 * Hasta aca el unico limite del sistema estaba en el login del dashboard. La
 * API quedaba sin techo: una credencial filtrada podia recorrer el padron de
 * compradores entero a maxima velocidad, y un pipeline con un bucle mal
 * escrito podia tumbar la ingesta de todos los tenants que comparten cluster.
 *
 * Dos decisiones que conviene poder defender:
 *
 * 1. El limite se aplica ANTES de autenticar, y la clave es el hash de la
 *    credencial presentada. Asi tambien queda limitado quien prueba claves al
 *    azar, que es justo el trafico que no llega a autenticarse nunca. La clave
 *    nunca se guarda en claro en el almacen del limitador.
 * 2. Se cuenta por credencial y no por IP. Los clientes son pipelines de
 *    servidor: varios tenants pueden salir por la misma IP, y un solo tenant
 *    puede salir por varias. Contar por IP mezclaria organizaciones distintas
 *    en el mismo cupo.
 */
class ApiRateLimitServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for('d4s-ingest', fn (Request $request) => Limit::perMinute(
            (int) config('d4s.api.rate_limits.ingest')
        )->by($this->credencial($request))->response($this->respuesta(...)));

        RateLimiter::for('d4s-query', fn (Request $request) => Limit::perMinute(
            (int) config('d4s.api.rate_limits.query')
        )->by($this->credencial($request))->response($this->respuesta(...)));
    }

    /**
     * Identidad del que llama, sin exponer la credencial.
     *
     * Cae a la IP solo cuando no hay credencial: sin eso, todas las peticiones
     * anonimas compartirian un unico cupo global y una sola de ellas dejaria
     * fuera de servicio a las demas.
     */
    private function credencial(Request $request): string
    {
        $token = $request->bearerToken();

        return $token === null || $token === ''
            ? 'ip:'.$request->ip()
            : 'key:'.hash('sha256', $token);
    }

    /**
     * Error en JSON, como el resto de la API.
     *
     * Devolver la pagina HTML de error por defecto a un pipeline es la forma
     * mas dificil de diagnosticar un 429.
     */
    private function respuesta(Request $request, array $headers): JsonResponse
    {
        return response()->json([
            'message' => 'Demasiadas peticiones. Reintentá cuando expire el límite.',
            'retry_after_seconds' => (int) ($headers['Retry-After'] ?? 60),
        ], 429, $headers);
    }
}
