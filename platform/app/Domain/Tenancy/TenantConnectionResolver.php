<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

/**
 * El unico lugar del sistema que sabe en que base viven los datos de un
 * tenant.
 *
 * Toda la estrategia hibrida cabe en esta clase. Los modelos, los servicios,
 * los trabajos en cola y los controladores nunca preguntan si un tenant es
 * compartido o dedicado: piden la conexion y siguen. Por eso promover un
 * tenant a base propia no toca el codigo de dominio, y por eso mover la
 * plataforma entera a otra topologia manana seria un cambio local.
 *
 * Cuando en la defensa pregunten cuanto trabajo es darle a un tenant su
 * propia instancia, la respuesta se senala aca: es una fila en `tenants` y
 * un movimiento de datos. Nada mas.
 */
final class TenantConnectionResolver
{
    /** Conexion del cluster compartido, aislada por Row-Level Security. */
    public const SHARED_CONNECTION = 'shared';

    /** Plantilla desde la que se derivan las conexiones dedicadas. */
    private const DEDICATED_TEMPLATE = 'dedicated';

    public function __construct(
        private readonly Config $config,
        private readonly DatabaseManager $database,
    ) {}

    /**
     * Devuelve el nombre de la conexion Laravel donde vive este tenant,
     * registrandola en configuracion si todavia no existia.
     */
    public function resolve(Tenant $tenant): string
    {
        if (! $tenant->isDedicated()) {
            return self::SHARED_CONNECTION;
        }

        $name = $tenant->connection_name;
        $database = $tenant->database_name;

        // La restriccion CHECK de la tabla ya lo garantiza; esto es para que,
        // si alguien tocara la fila por fuera, el fallo sea explicito y no
        // una conexion silenciosa a la base equivocada.
        if ($name === null || $database === null) {
            throw new RuntimeException(
                "El tenant [{$tenant->slug}] es dedicado pero no declara conexion ni base."
            );
        }

        $this->registerConnection($name, $database);

        return $name;
    }

    /**
     * Define una conexion dedicada a partir de la plantilla.
     *
     * Solo se sustituye el nombre de la base. Las credenciales y el host
     * salen del entorno, nunca de la tabla de tenants: un SELECT sobre el
     * plano de control no debe alcanzar para conectarse a ningun lado.
     */
    private function registerConnection(string $name, string $database): void
    {
        $existing = $this->config->get("database.connections.{$name}");

        if (is_array($existing) && ($existing['database'] ?? null) === $database) {
            return;
        }

        $template = $this->config->get('database.connections.'.self::DEDICATED_TEMPLATE);

        if (! is_array($template)) {
            throw new RuntimeException(
                'Falta la conexion plantilla [dedicated] en config/database.php.'
            );
        }

        $template['database'] = $database;

        $this->config->set("database.connections.{$name}", $template);

        // Si la conexion ya estaba instanciada con otra base, se descarta
        // para que el proximo uso la reconstruya con la configuracion nueva.
        $this->database->purge($name);
    }
}
