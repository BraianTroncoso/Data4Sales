<?php

declare(strict_types=1);

use App\Domain\Tenancy\Database\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Zona cruda de la ingesta: lo que llego, tal cual llego.
 *
 * Nada entra directo a las tablas de negocio. Primero aterriza aca, sin
 * interpretar, y recien despues se normaliza. Esa separacion es la que
 * permite responder tres de las preguntas dificiles:
 *
 *   - "Enviame los mismos datos dos veces"  -> el indice unico sobre
 *     (tenant_id, content_hash) lo resuelve el motor, de forma atomica.
 *     No hay ventana de carrera entre consultar y escribir.
 *
 *   - "Un envio se corta a la mitad"        -> el lote queda en `partial`
 *     con la cuenta exacta de lo procesado. Como esta zona es append-only
 *     y la normalizacion es idempotente, reintentar es seguro.
 *
 *   - "De donde salio este dato"            -> siempre hay un puntero al
 *     JSON original que lo produjo.
 *
 * El costo es almacenamiento duplicado. Es un costo aceptado y esta
 * documentado en DECISIONS.md junto con la politica de retencion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingestion_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');

            // Clave de idempotencia que manda el emisor. Reenviar el mismo
            // lote devuelve el lote original en vez de crear uno nuevo:
            // es lo que hace seguro el reintento tras un timeout de red.
            $table->string('idempotency_key', 128)->nullable();

            $table->string('entity_type', 32);
            $table->string('source', 32);
            $table->string('status', 16)->default('received');

            $table->char('payload_hash', 64);

            // Cuantos registros anuncio el emisor. Es lo unico que permite
            // distinguir "mandaron pocos" de "se corto a la mitad": desde el
            // lado del servidor los dos casos se ven identicos.
            $table->unsignedInteger('expected_count')->nullable();

            $table->unsignedInteger('received_count')->default(0);
            $table->unsignedInteger('accepted_count')->default(0);
            $table->unsignedInteger('rejected_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);

            $table->timestampTz('received_at');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->text('failure_reason')->nullable();

            $table->timestampsTz();

            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'received_at']);
        });

        DB::statement(
            "ALTER TABLE ingestion_batches ADD CONSTRAINT ingestion_batches_status_check
             CHECK (status IN ('received','processing','completed','partial','failed'))"
        );

        Schema::create('raw_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('tenant_id');
            $table->uuid('batch_id');

            $table->string('entity_type', 32);

            // Identificador que usa el retailer para esta entidad. Es la
            // clave natural con la que despues se hace el upsert al nucleo.
            $table->string('source_id', 128)->nullable();

            $table->jsonb('payload');

            // SHA-256 del payload canonicalizado (claves ordenadas). Incluye
            // el timestamp del evento de origen, para que dos estados
            // distintos de la misma orden no colisionen entre si.
            $table->char('content_hash', 64);

            $table->string('outcome', 16)->nullable();
            $table->timestampTz('received_at');
            $table->timestampTz('processed_at')->nullable();

            // Deduplicacion exacta garantizada por el motor, no por la
            // aplicacion. Dos workers procesando el mismo lote en paralelo
            // no pueden insertar el mismo evento dos veces.
            $table->unique(['tenant_id', 'content_hash']);

            $table->index(['tenant_id', 'entity_type', 'source_id']);
            $table->index(['batch_id']);
            $table->index(['tenant_id', 'processed_at']);
        });

        DB::statement(
            "ALTER TABLE raw_events ADD CONSTRAINT raw_events_outcome_check
             CHECK (outcome IS NULL OR outcome IN ('accepted','rejected'))"
        );

        Schema::create('ingestion_rejects', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('tenant_id');
            $table->uuid('batch_id');
            $table->unsignedBigInteger('raw_event_id')->nullable();

            $table->string('entity_type', 32);
            $table->string('source_id', 128)->nullable();

            // Motivo tipado, no texto libre. Es lo que permite que la tabla
            // de suciedad del README sea una consulta y no una promesa:
            // agrupar por `reason` dice exactamente que llego mal y cuanto.
            $table->string('reason', 48);
            $table->string('field', 64)->nullable();
            $table->text('message');
            $table->jsonb('payload')->nullable();

            $table->timestampTz('created_at');

            $table->index(['tenant_id', 'batch_id']);
            $table->index(['tenant_id', 'reason']);
        });

        foreach (['ingestion_batches', 'raw_events', 'ingestion_rejects'] as $table) {
            RowLevelSecurity::protect($table);
        }
    }

    public function down(): void
    {
        foreach (['ingestion_rejects', 'raw_events', 'ingestion_batches'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
