<?php

declare(strict_types=1);

use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * El aislamiento entre tenants es la garantia central del sistema, asi que
 * se testea contra el motor y no contra la aplicacion.
 *
 * Estos tests corren con el rol dueno del esquema, que es el que puede
 * migrar. Que aun asi el aislamiento se cumpla es precisamente el efecto de
 * FORCE ROW LEVEL SECURITY: sin esa clausula, el dueno de la tabla se
 * saltearia las politicas y esta suite pasaria en verde midiendo nada.
 */
beforeEach(function () {
    $this->luna = Tenant::factory()->create(['slug' => 'luna-'.Str::random(6)]);
    $this->andes = Tenant::factory()->create(['slug' => 'andes-'.Str::random(6)]);

    $this->context = app(TenantContext::class);
});

afterEach(function () {
    $this->context->leave();
});

/**
 * Inserta un cliente saltandose Eloquent a proposito: si el aislamiento
 * dependiera de un scope de modelo, este helper lo esquivaria.
 */
function insertCustomer(string $tenantId, string $externalId): void
{
    DB::connection('shared')->table('customers')->insert([
        'id' => (string) Str::uuid(),
        'tenant_id' => $tenantId,
        'external_id' => $externalId,
        'name' => 'Comprador '.$externalId,
        'email_status' => 'missing',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('no deja leer los datos de otro tenant ni siquiera con SQL crudo', function () {
    $this->context->run($this->luna, fn () => insertCustomer($this->luna->id, 'CLI-LUNA'));

    $visiblesParaAndes = $this->context->run(
        $this->andes,
        fn () => DB::connection('shared')->table('customers')->count(),
    );

    expect($visiblesParaAndes)->toBe(0);

    $visiblesParaLuna = $this->context->run(
        $this->luna,
        fn () => DB::connection('shared')->table('customers')->count(),
    );

    expect($visiblesParaLuna)->toBe(1);
});

it('rechaza escribir una fila marcada con el tenant de otro', function () {
    $this->context->run($this->andes, function () {
        insertCustomer($this->luna->id, 'ROBADO');
    });
})->throws(QueryException::class, 'row-level security');

it('devuelve cero filas cuando no hay tenant activo, en vez de devolverlas todas', function () {
    $this->context->run($this->luna, fn () => insertCustomer($this->luna->id, 'CLI-LUNA'));

    $this->context->leave();

    $sinContexto = DB::connection('shared')->table('customers')->count();

    expect($sinContexto)->toBe(0);
});

it('limpia el contexto aunque el bloque de trabajo lance una excepcion', function () {
    try {
        $this->context->run($this->luna, function () {
            throw new RuntimeException('fallo de negocio');
        });
    } catch (RuntimeException) {
        // Lo que importa es lo que quedo despues, no la excepcion.
    }

    expect($this->context->hasTenant())->toBeFalse();
});

it('restaura el tenant anterior al salir de un contexto anidado', function () {
    $this->context->run($this->luna, function () {
        $this->context->run($this->andes, function () {
            expect($this->context->current()->id)->toBe($this->andes->id);
        });

        expect($this->context->current()->id)->toBe($this->luna->id);
    });
});
