<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Database;

use Illuminate\Support\Facades\DB;

/**
 * Aislamiento de tenants a nivel motor de base de datos.
 *
 * Esta clase es el punto exacto donde se vuelve imposible leer o escribir
 * datos de otro tenant. No es un scope de Eloquent ni un middleware: son
 * politicas de PostgreSQL, evaluadas por el motor en cada fila, debajo de
 * cualquier cosa que la aplicacion pueda hacer.
 *
 * Tres propiedades que vale la pena entender:
 *
 * 1. FORCE ROW LEVEL SECURITY. En Postgres el dueno de una tabla se saltea
 *    RLS por defecto. FORCE se lo aplica igual. Sumado a que el rol de la
 *    aplicacion no es dueno de nada, son dos candados sobre el mismo punto.
 *
 * 2. Falla cerrada. La politica lee el contexto con `current_setting(...,
 *    true)`, que devuelve NULL si nadie lo seteo. Comparar contra NULL no
 *    da verdadero, asi que una consulta sin contexto de tenant devuelve
 *    cero filas en vez de devolverlas todas. Olvidarse de setear el
 *    contexto rompe la funcionalidad, nunca el aislamiento.
 *
 * 3. WITH CHECK. Ademas de filtrar lo que se lee, prohibe escribir una fila
 *    con un tenant_id que no sea el del contexto. Es la ultima linea contra
 *    el lote que trae registros de otro tenant: aunque la validacion de la
 *    aplicacion fallara, el INSERT es rechazado por el motor.
 */
final class RowLevelSecurity
{
    /**
     * Variable de sesion de PostgreSQL que transporta el tenant activo.
     */
    public const CONTEXT_SETTING = 'app.tenant_id';

    private const POLICY_NAME = 'tenant_isolation';

    /**
     * Expresion que resuelve el tenant del contexto actual.
     *
     * NULLIF colapsa la cadena vacia a NULL para que limpiar el contexto
     * (setearlo en '') tenga el mismo efecto que no haberlo seteado nunca.
     */
    private const CONTEXT_EXPRESSION = "NULLIF(current_setting('"
        .self::CONTEXT_SETTING."', true), '')::uuid";

    /**
     * Activa el aislamiento por tenant sobre una tabla del plano de datos.
     *
     * Se llama desde las migraciones, que corren con el rol dueno del
     * esquema. El rol de la aplicacion no tiene permiso para deshacerlo.
     *
     * La conexion se omite a proposito en las migraciones del plano de
     * datos: Laravel fija la conexion activa mientras corre la migracion,
     * asi que el mismo archivo sirve tanto para la base compartida como
     * para la base propia de un tenant dedicado.
     */
    public static function protect(string $table, ?string $connection = null): void
    {
        $db = DB::connection($connection);

        $db->statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        $db->statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");

        $db->statement(sprintf(
            'CREATE POLICY %s ON %s USING (tenant_id = %s) WITH CHECK (tenant_id = %s)',
            self::POLICY_NAME,
            $table,
            self::CONTEXT_EXPRESSION,
            self::CONTEXT_EXPRESSION,
        ));
    }

    /**
     * Revierte el aislamiento. Solo se usa en el rollback de migraciones.
     */
    public static function unprotect(string $table, ?string $connection = null): void
    {
        $db = DB::connection($connection);

        $db->statement(sprintf('DROP POLICY IF EXISTS %s ON %s', self::POLICY_NAME, $table));
        $db->statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
        $db->statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
    }
}
