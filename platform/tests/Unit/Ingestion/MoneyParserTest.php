<?php

declare(strict_types=1);

use App\Domain\Ingestion\Support\MoneyParser;

it('convierte numeros nativos a unidades minimas', function (mixed $input, int $expected) {
    expect(MoneyParser::toMinorUnits($input, 'USD'))->toBe($expected);
})->with([
    'entero' => [1234, 123400],
    'float con centavos' => [19.99, 1999],
    'float que en binario no es exacto' => [0.1 + 0.2, 30],
    'cero' => [0, 0],
]);

it('resuelve las dos convenciones decimales cuando estan los dos separadores', function (string $input, int $expected) {
    expect(MoneyParser::toMinorUnits($input, 'USD'))->toBe($expected);
})->with([
    'latina' => ['1.234,56', 123456],
    'anglosajona' => ['1,234.56', 123456],
    'latina con millones' => ['1.234.567,89', 123456789],
    'anglosajona con millones' => ['1,234,567.89', 123456789],
]);

it('asume separador de miles cuando lo siguen exactamente tres digitos', function (string $input, int $expected) {
    expect(MoneyParser::toMinorUnits($input, 'USD'))->toBe($expected);
})->with([
    'punto como miles' => ['1.234', 123400],
    'coma como miles' => ['1,234', 123400],
]);

it('asume decimal cuando no lo siguen tres digitos', function (string $input, int $expected) {
    expect(MoneyParser::toMinorUnits($input, 'USD'))->toBe($expected);
})->with([
    'un decimal' => ['1.5', 150],
    'dos decimales' => ['19.99', 1999],
    'coma decimal' => ['19,99', 1999],
]);

it('respeta la cantidad de decimales de cada moneda', function () {
    // El peso chileno y el yen no tienen centavos: 1000 CLP son 1000
    // unidades minimas, no 100.000. Asumir dos decimales para todo
    // multiplicaria por cien el revenue de un pais entero.
    expect(MoneyParser::toMinorUnits('1000', 'CLP'))->toBe(1000)
        ->and(MoneyParser::toMinorUnits('1000', 'JPY'))->toBe(1000)
        ->and(MoneyParser::toMinorUnits('1000', 'USD'))->toBe(100000);
});

it('tolera simbolos de moneda y espacios', function (string $input, int $expected) {
    expect(MoneyParser::toMinorUnits($input, 'USD'))->toBe($expected);
})->with([
    'simbolo adelante' => ['$ 1.234,56', 123456],
    'codigo atras' => ['1234.56 USD', 123456],
    'espacios' => ['  19,99  ', 1999],
]);

it('conserva el signo negativo para que lo decida quien llama', function () {
    expect(MoneyParser::toMinorUnits('-19.99', 'USD'))->toBe(-1999);
});

it('devuelve null cuando no hay numero que interpretar', function (mixed $input) {
    expect(MoneyParser::toMinorUnits($input, 'USD'))->toBeNull();
})->with([
    'cadena vacia' => [''],
    'solo espacios' => ['   '],
    'texto' => ['no disponible'],
    'nulo' => [null],
    'array' => [[1, 2]],
    'booleano' => [true],
    'infinito' => [INF],
]);
