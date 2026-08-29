<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Le pone al motor la unicidad que las decisiones ya suponian tener.
 *
 * `campaign_recipients` tiene indices unicos y por eso volver a congelar una
 * ejecucion no duplica destinatarios. `recipient_decisions` no tenia ninguno:
 * solo la PK autoincremental y un indice NO unico de lectura. El resultado,
 * medido, es que un segundo congelamiento de la misma ejecucion dejaba los
 * destinatarios en 183 y las decisiones en 372 — la mitad de las respuestas a
 * "por que este cliente recibio este correo" eran copias.
 *
 * Que la garantia este en la base y no en un `if` de la aplicacion es el mismo
 * criterio que ya usan la clave de idempotencia del envio y la deduplicacion
 * por email: un `if` puede perder una carrera entre dos workers, un indice
 * unico no.
 *
 * CLAVE ELEGIDA: (run_id, customer_id, reason_code).
 *
 *   - `run_id` y `customer_id` porque la pregunta que la tabla contesta es
 *     "que paso con ESTE cliente en ESTA ejecucion".
 *   - `reason_code` porque un mismo cliente si puede acumular motivos
 *     legitimamente distintos: entra por cumplir la condicion y despues queda
 *     excluido porque se dio de baja entre el congelamiento y el envio. Las
 *     dos filas son historia real y tienen que convivir.
 *   - `decision` NO entra en la clave: cada motivo pertenece a una sola
 *     decision (`matched_condition` es siempre `included`, `suppressed` es
 *     siempre `excluded`), asi que agregarla no distinguiria nada y en cambio
 *     dejaria pasar duplicados si alguien escribiera el mismo motivo con otra
 *     decision, que es justamente el caso que habria que rechazar.
 */
return new class extends Migration
{
    private const INDEX = 'recipient_decisions_run_customer_reason_uniq';

    public function up(): void
    {
        // Las filas duplicadas que el bug ya escribio hay que resolverlas
        // antes: `CREATE UNIQUE INDEX` lee la tabla entera —RLS no lo acota— y
        // falla si encuentra una sola repetida.
        //
        // El FORCE se suspende SOLO para esta limpieza. La tabla tiene FORCE
        // ROW LEVEL SECURITY, que alcanza tambien al dueno del esquema: sin
        // suspenderlo, este DELETE correria sin contexto de tenant, la
        // politica no daria verdadero para ninguna fila y borraria cero — la
        // migracion "pasaria" y el CREATE INDEX de abajo explotaria igual.
        // Corre dentro de la transaccion de la migracion, con el rol dueno,
        // que es el unico momento en que la tabla no esta expuesta a nadie.
        DB::statement('ALTER TABLE recipient_decisions NO FORCE ROW LEVEL SECURITY');

        try {
            // Se conserva la de menor `id`, que es la primera que se escribio:
            // la que refleja el momento real de la decision.
            DB::statement(
                <<<'SQL'
                DELETE FROM recipient_decisions d
                USING recipient_decisions otra
                WHERE d.run_id = otra.run_id
                  AND d.customer_id = otra.customer_id
                  AND d.reason_code = otra.reason_code
                  AND d.id > otra.id
                SQL
            );
        } finally {
            DB::statement('ALTER TABLE recipient_decisions FORCE ROW LEVEL SECURITY');
        }

        DB::statement(
            'CREATE UNIQUE INDEX '.self::INDEX.
            ' ON recipient_decisions (run_id, customer_id, reason_code)'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
    }
};
