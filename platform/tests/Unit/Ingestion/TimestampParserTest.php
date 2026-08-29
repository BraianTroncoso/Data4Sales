<?php

declare(strict_types=1);

use App\Domain\Ingestion\Support\TimestampParser;

const BUENOS_AIRES = 'America/Argentina/Buenos_Aires';

it('respeta el desplazamiento cuando la fecha lo trae', function (string $input, string $expectedUtc) {
    expect(TimestampParser::parse($input, BUENOS_AIRES)?->toIso8601ZuluString())->toBe($expectedUtc);
})->with([
    'ISO con offset negativo' => ['2026-03-01T10:00:00-03:00', '2026-03-01T13:00:00Z'],
    'ISO con Z' => ['2026-03-01T13:00:00Z', '2026-03-01T13:00:00Z'],
    'offset sin dos puntos' => ['2026-03-01T10:00:00-0300', '2026-03-01T13:00:00Z'],
    'offset positivo' => ['2026-03-01T15:00:00+02:00', '2026-03-01T13:00:00Z'],
]);

it('interpreta una fecha sin zona en la zona del tenant, no del servidor', function () {
    // Una venta a las 22:30 en Buenos Aires es 01:30 UTC del dia siguiente.
    // Si esto se interpretara en UTC, la venta se contaria el dia equivocado.
    $parsed = TimestampParser::parse('2026-03-01 22:30:00', BUENOS_AIRES);

    expect($parsed?->toIso8601ZuluString())->toBe('2026-03-02T01:30:00Z');
});

it('interpreta la misma cadena distinto segun el tenant', function () {
    $buenosAires = TimestampParser::parse('2026-03-01 12:00:00', BUENOS_AIRES);
    $madrid = TimestampParser::parse('2026-03-01 12:00:00', 'Europe/Madrid');

    expect($buenosAires?->toIso8601ZuluString())->toBe('2026-03-01T15:00:00Z')
        ->and($madrid?->toIso8601ZuluString())->toBe('2026-03-01T11:00:00Z');
});

it('acepta los formatos habituales sin zona', function (string $input, string $expectedUtc) {
    expect(TimestampParser::parse($input, 'UTC')?->toIso8601ZuluString())->toBe($expectedUtc);
})->with([
    'ISO sin offset' => ['2026-03-01T10:00:00', '2026-03-01T10:00:00Z'],
    'espacio en vez de T' => ['2026-03-01 10:00:00', '2026-03-01T10:00:00Z'],
    'solo fecha' => ['2026-03-01', '2026-03-01T00:00:00Z'],
    'barra latina' => ['01/03/2026', '2026-03-01T00:00:00Z'],
    'barra latina con hora' => ['01/03/2026 10:00:00', '2026-03-01T10:00:00Z'],
    'guiones invertidos' => ['01-03-2026', '2026-03-01T00:00:00Z'],
]);

it('lee epoch en segundos y en milisegundos', function (mixed $input) {
    expect(TimestampParser::parse($input, 'UTC')?->toIso8601ZuluString())->toBe('2026-03-01T10:00:00Z');
})->with([
    'entero en segundos' => [1772359200],
    'cadena en segundos' => ['1772359200'],
    'milisegundos' => ['1772359200000'],
]);

it('no confunde una fecha compacta con un epoch', function () {
    // 20260301 tiene 8 digitos: no es epoch. Si se leyera como tal daria
    // una fecha de 1970 y nadie lo notaria.
    expect(TimestampParser::parse('20260301', 'UTC'))->toBeNull();
});

it('detecta fechas que se desbordan en vez de aceptarlas', function (string $input) {
    // createFromFormat no explota con "2026-13-45": desborda a otra fecha.
    // Reformatear y comparar es lo que atrapa eso.
    expect(TimestampParser::parse($input, 'UTC'))->toBeNull();
})->with([
    'mes 13' => ['2026-13-01'],
    'dia 45' => ['2026-03-45'],
    'hora 99' => ['2026-03-01 99:00:00'],
]);

it('devuelve null cuando no hay fecha que interpretar', function (mixed $input) {
    expect(TimestampParser::parse($input, 'UTC'))->toBeNull();
})->with([
    'cadena vacia' => [''],
    'espacios' => ['   '],
    'texto' => ['ayer'],
    'nulo' => [null],
    'array' => [[]],
]);
