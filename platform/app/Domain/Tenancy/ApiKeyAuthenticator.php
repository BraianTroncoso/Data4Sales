<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Domain\Tenancy\Models\ApiKey;
use Illuminate\Support\Carbon;

/**
 * Traduce el secreto que llega en el header a una API key valida.
 *
 * Es el primer paso de toda operacion sobre la API, y ocurre necesariamente
 * antes de saber a que base de datos ir: por eso vive en el plano de
 * control, que es unico y no esta particionado por tenant.
 *
 * La busqueda es por indice unico sobre el hash, no un recorrido comparando
 * de a uno. Eso es lo que la mantiene en tiempo constante sin importar
 * cuantos tenants existan, y es la razon por la que el hash es SHA-256 y no
 * bcrypt (ver D-09 en DECISIONS.md).
 */
final class ApiKeyAuthenticator
{
    /**
     * Cada cuanto, como mucho, se registra el uso de una clave.
     *
     * Sin este freno, cada request de ingesta sumaria una escritura al plano
     * de control. Con lotes llegando en paralelo eso convierte una tabla de
     * lectura en un punto de contencion, a cambio de una precision de
     * timestamp que a nadie le sirve.
     */
    private const USAGE_TRACKING_INTERVAL_SECONDS = 60;

    /**
     * Devuelve la clave si el secreto es valido, utilizable y su tenant
     * esta activo. Devuelve null en cualquier otro caso, sin distinguir
     * cual: decirle a quien prueba credenciales si una clave existe pero
     * esta revocada le regala informacion.
     */
    public function authenticate(?string $secret): ?ApiKey
    {
        if ($secret === null || $secret === '') {
            return null;
        }

        $key = ApiKey::query()
            ->with('tenant')
            ->where('token_hash', ApiKey::hash($secret))
            ->first();

        if ($key === null || $key->isRevoked()) {
            return null;
        }

        if ($key->tenant === null || ! $key->tenant->is_active) {
            return null;
        }

        $this->trackUsage($key);

        return $key;
    }

    private function trackUsage(ApiKey $key): void
    {
        $lastUsed = $key->last_used_at;

        if ($lastUsed !== null
            && $lastUsed->diffInSeconds(Carbon::now()) < self::USAGE_TRACKING_INTERVAL_SECONDS) {
            return;
        }

        // Sin timestamps de Eloquent: esto es telemetria, no un cambio de
        // negocio, y no deberia mover updated_at de la clave.
        ApiKey::query()
            ->whereKey($key->getKey())
            ->update(['last_used_at' => Carbon::now()]);
    }
}
