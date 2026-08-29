<?php

declare(strict_types=1);

use App\Domain\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Motor de acciones sobre clientes — recorte vertical de la Parte 3.
 *
 * Son las once tablas que el documento de diseno lista en su seccion 9.2 como
 * el minimo para que una condicion funcione de punta a punta. Las otras nueve
 * entidades del modelo completo (afinidad producto-cliente, eventos del
 * proveedor, membresias del modo por reglas, cuotas e identidades de envio)
 * estan disenadas en docs/parte-3-motor-de-acciones.md y NO estan aca: el
 * enunciado prefiere una condicion funcionando de punta a punta antes que un
 * motor generico incompleto.
 *
 * Tres columnas concentran las garantias del diseno, y conviene tenerlas a la
 * vista antes de leer el resto:
 *
 *   audience_definitions.dsl_hash    identidad estable de una condicion. Dos
 *                                    campanas con la misma condicion comparten
 *                                    hash, y de ahi sale la clave que impide
 *                                    reenviar por la misma causa.
 *
 *   campaign_runs.as_of              el instante congelado. Todo lo que la
 *                                    ejecucion decide se decide contra ese
 *                                    momento, no contra "ahora": si no, dos
 *                                    reintentos de la misma tanda resolverian
 *                                    audiencias distintas.
 *
 *   send_intents.idempotency_key     uuid v5 de (run, paso, cliente). Es la
 *                                    barrera contra el doble envio, y es unica
 *                                    en la base, no en la aplicacion.
 */
return new class extends Migration
{
    public function up(): void
    {
        /**
         * Una condicion guardada, con su identidad.
         *
         * El DSL se guarda como arbol JSON y NO como SQL. Guardar SQL ataria
         * la condicion a la forma del esquema del dia que se creo, y una
         * migracion futura la dejaria rota en silencio.
         */
        Schema::create('audience_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name');
            $table->jsonb('dsl');
            $table->char('dsl_hash', 64);
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'dsl_hash']);
        });

        Schema::create('templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name');

            // `uniform` permite envio en lote al proveedor; `personalized` lo
            // prohibe. El motivo esta en la seccion 8.4 del diseno: en un
            // envio en lote, un desalineamiento de indices entre la lista de
            // destinos y la de variables ES la fuga de datos, y no deja rastro.
            $table->string('content_mode', 16)->default('personalized');
            $table->timestampsTz();
        });

        DB::statement(
            "ALTER TABLE templates ADD CONSTRAINT templates_content_mode_check
             CHECK (content_mode IN ('uniform','personalized'))"
        );

        /**
         * El contenido es inmutable y versionado.
         *
         * Una campana apunta a una VERSION, no a una plantilla. Sin eso,
         * editar el texto cambiaria retroactivamente lo que se dice que se
         * envio, y la pregunta "que recibio este cliente" dejaria de tener
         * respuesta.
         */
        Schema::create('template_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('template_id');
            $table->unsignedInteger('version');

            $table->string('subject');
            $table->text('body_text');
            $table->text('body_html')->nullable();

            // Lista blanca de variables que la plantilla puede usar. El
            // renderizador rechaza cualquier otra: es lo que impide que
            // alguien escriba {{ customer.password_hash }} y funcione.
            $table->jsonb('declared_variables')->default('[]');
            $table->char('content_hash', 64);
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'template_id', 'version']);
            $table->foreign('template_id')->references('id')->on('templates')->cascadeOnDelete();
        });

        Schema::create('campaigns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('slug', 64);
            $table->string('name');

            $table->string('mode', 16)->default('static');
            $table->uuid('audience_definition_id');

            $table->string('lifecycle_state', 16)->default('draft');

            // Separado del ciclo de vida a proposito: pausar no es un estado
            // de la campana sino una ORDEN que el despachador lee en cada
            // tanda. Mezclarlos haria que pausar tenga que esperar a que
            // termine lo que esta corriendo.
            $table->string('control_state', 16)->default('running');

            $table->timestampsTz();

            $table->unique(['tenant_id', 'slug']);
            $table->foreign('audience_definition_id')->references('id')->on('audience_definitions');
        });

        DB::statement(
            "ALTER TABLE campaigns ADD CONSTRAINT campaigns_mode_check
             CHECK (mode IN ('static','rules'))"
        );

        DB::statement(
            "ALTER TABLE campaigns ADD CONSTRAINT campaigns_control_state_check
             CHECK (control_state IN ('running','pausing','paused','aborting','aborted'))"
        );

        Schema::create('campaign_steps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('campaign_id');
            $table->unsignedSmallInteger('position')->default(1);

            // Espera respecto del paso anterior, en segundos. El recorte
            // implementa un solo paso; la columna existe porque la secuencia
            // esta disenada y agregarla despues obligaria a migrar datos.
            $table->unsignedInteger('wait_before_seconds')->default(0);

            // Vencimiento del mensaje. Un correo de "te extranamos" que sale
            // tres dias tarde por una caida del proveedor es peor que uno que
            // no sale.
            $table->unsignedInteger('valid_for_seconds')->nullable();

            $table->uuid('template_version_id');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'campaign_id', 'position']);
            $table->foreign('campaign_id')->references('id')->on('campaigns')->cascadeOnDelete();
            $table->foreign('template_version_id')->references('id')->on('template_versions');
        });

        /**
         * Una ejecucion concreta.
         *
         * Guarda una COPIA LITERAL de la condicion, no una referencia. Si
         * alguien edita la definicion despues, esta ejecucion sigue pudiendo
         * explicar contra que se resolvio. Es la diferencia entre poder
         * auditar y tener que confiar.
         */
        Schema::create('campaign_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('campaign_id');

            $table->timestampTz('as_of');
            $table->jsonb('dsl_snapshot');
            $table->string('compiler_version', 32);
            $table->char('sql_fingerprint', 64);

            $table->string('status', 16)->default('resolving');
            $table->unsignedInteger('audience_size')->default(0);

            // Cuan atrasados estaban los agregados al resolver. Sin esto, un
            // "hace 50 dias que no comprás" enviado a alguien que compro ayer
            // seria inexplicable despues del hecho.
            $table->unsignedInteger('metrics_lag_seconds')->nullable();

            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'campaign_id', 'created_at']);
            $table->foreign('campaign_id')->references('id')->on('campaigns')->cascadeOnDelete();
        });

        DB::statement(
            "ALTER TABLE campaign_runs ADD CONSTRAINT campaign_runs_status_check
             CHECK (status IN ('resolving','resolved','sending','completed','failed','aborted'))"
        );

        /**
         * La audiencia congelada.
         *
         * `email_hash` esta aca y no se recalcula al enviar porque es la clave
         * de deduplicacion: dos filas de `customers` pueden compartir email
         * (ver D-08), y sin esto la misma persona recibiria dos copias.
         */
        Schema::create('campaign_recipients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('run_id');
            $table->uuid('customer_id');
            $table->char('email_hash', 64);
            $table->timestampTz('entered_at');
            $table->string('exit_reason', 32)->nullable();

            // Un cliente entra una sola vez por ejecucion.
            $table->unique(['run_id', 'customer_id']);
            // Y una direccion de correo recibe una sola vez, aunque la
            // compartan varios clientes.
            $table->unique(['run_id', 'email_hash']);

            $table->index(['tenant_id', 'run_id']);
            $table->foreign('run_id')->references('id')->on('campaign_runs')->cascadeOnDelete();
        });

        /**
         * La intencion de mandarle un mensaje a alguien.
         *
         * Los estados `claimed` e `in_flight` son DISTINTOS a proposito, y esa
         * distincion es toda la garantia de entrega:
         *
         *   claimed    un worker lo tomo pero todavia no llamo al proveedor.
         *              Si el proceso muere aca, se puede devolver a `queued`
         *              sin riesgo: nadie recibio nada.
         *   in_flight  ya se llamo al proveedor y no sabemos como termino.
         *              Reintentarlo puede duplicar, asi que el recolector lo
         *              marca `unresolved` para que un humano lo mire.
         *
         * Colapsarlos en un solo estado obliga a elegir entre perder mensajes
         * o duplicarlos, sin forma de saber cual de las dos cosas paso.
         */
        Schema::create('send_intents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('run_id');
            $table->uuid('step_id');
            $table->uuid('recipient_id');
            $table->uuid('customer_id');

            // uuid v5 de (run, paso, cliente). Determinista: materializar dos
            // veces la misma tanda produce las mismas claves y el indice unico
            // rechaza la segunda.
            $table->uuid('idempotency_key');

            $table->string('state', 16)->default('queued');
            $table->timestampTz('scheduled_for');
            $table->timestampTz('expires_at')->nullable();

            $table->string('claimed_by', 64)->nullable();
            $table->timestampTz('claim_expires_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('provider_message_id')->nullable();

            $table->timestampsTz();

            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'run_id', 'state']);

            $table->foreign('run_id')->references('id')->on('campaign_runs')->cascadeOnDelete();
            $table->foreign('recipient_id')->references('id')->on('campaign_recipients')->cascadeOnDelete();
        });

        DB::statement(
            "ALTER TABLE send_intents ADD CONSTRAINT send_intents_state_check
             CHECK (state IN ('queued','claimed','in_flight','sent','failed','suppressed','expired','unresolved','cancelled'))"
        );

        // Cola de trabajo: sólo lo que está pendiente. Un índice parcial sobre
        // los estados accionables se mantiene chico aunque la tabla tenga
        // millones de intentos ya enviados.
        DB::statement(
            "CREATE INDEX send_intents_pending_idx
             ON send_intents (tenant_id, scheduled_for)
             WHERE state IN ('queued','claimed')"
        );

        Schema::create('send_attempts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('tenant_id');
            $table->uuid('intent_id');
            $table->unsignedSmallInteger('attempt_no');

            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();

            $table->string('outcome', 16)->nullable();
            $table->string('provider_status', 64)->nullable();
            $table->string('error_class', 64)->nullable();
            $table->text('error_message')->nullable();

            $table->index(['tenant_id', 'intent_id']);
            $table->foreign('intent_id')->references('id')->on('send_intents')->cascadeOnDelete();
        });

        /**
         * Bajas y direcciones a las que no hay que escribir.
         *
         * Por HASH del email, no por cliente: una baja vale para la persona,
         * no para la fila. Si alguien se da de baja y el retailer lo reenvia
         * manana con otro identificador de origen, la supresion tiene que
         * seguir valiendo.
         */
        Schema::create('suppressions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('tenant_id');
            $table->char('email_hash', 64);
            $table->string('reason', 32);
            $table->string('scope', 32)->default('tenant');
            $table->string('source', 64)->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('expires_at')->nullable();

            $table->unique(['tenant_id', 'email_hash', 'scope']);
        });

        DB::statement(
            "ALTER TABLE suppressions ADD CONSTRAINT suppressions_reason_check
             CHECK (reason IN ('unsubscribed','hard_bounce','complaint','manual','erasure'))"
        );

        /**
         * Por que este cliente entro, quedo afuera, o quedo esperando.
         *
         * Es la respuesta a "por que este cliente recibio, o no recibio, este
         * correo", que el enunciado pide tratar como requisito de PRODUCTO.
         *
         * Sólo se registran las exclusiones POSTERIORES a la condicion —
         * supresion, email invalido, vencimiento — porque estan acotadas por
         * el tamano de la audiencia. El no-cumplimiento de la condicion se
         * reconstruye a demanda: registrar por que cada uno de los 150.000
         * clientes que NO entraron no entro seria escribir mas filas de
         * exclusion que de audiencia, en cada ejecucion.
         */
        Schema::create('recipient_decisions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('tenant_id');
            $table->uuid('run_id');
            $table->uuid('customer_id');

            $table->string('decision', 16);
            $table->string('reason_code', 48);
            $table->string('node_id', 64)->nullable();
            $table->jsonb('detail')->nullable();
            $table->timestampTz('decided_at');

            $table->index(['tenant_id', 'run_id', 'customer_id']);
            $table->foreign('run_id')->references('id')->on('campaign_runs')->cascadeOnDelete();
        });

        DB::statement(
            "ALTER TABLE recipient_decisions ADD CONSTRAINT recipient_decisions_decision_check
             CHECK (decision IN ('included','excluded','deferred'))"
        );

        foreach ([
            'audience_definitions', 'templates', 'template_versions', 'campaigns',
            'campaign_steps', 'campaign_runs', 'campaign_recipients',
            'send_intents', 'send_attempts', 'suppressions', 'recipient_decisions',
        ] as $table) {
            RowLevelSecurity::protect($table);
        }
    }

    public function down(): void
    {
        foreach ([
            'recipient_decisions', 'suppressions', 'send_attempts', 'send_intents',
            'campaign_recipients', 'campaign_runs', 'campaign_steps', 'campaigns',
            'template_versions', 'templates', 'audience_definitions',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
