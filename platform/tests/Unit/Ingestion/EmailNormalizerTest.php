<?php

declare(strict_types=1);

use App\Domain\Ingestion\Support\EmailNormalizer;

it('normaliza a minusculas y quita espacios internos', function () {
    $result = EmailNormalizer::normalize('  Maria.Gonzalez @Example.COM  ');

    expect($result['status'])->toBe(EmailNormalizer::STATUS_VALID)
        ->and($result['normalized'])->toBe('maria.gonzalez@example.com')
        // El original se conserva tal cual llego: soporte necesita ver lo
        // que mando el retailer, no lo que nosotros interpretamos.
        ->and($result['original'])->toBe('Maria.Gonzalez @Example.COM');
});

it('marca invalido sin descartar el dato original', function (string $input) {
    $result = EmailNormalizer::normalize($input);

    expect($result['status'])->toBe(EmailNormalizer::STATUS_INVALID)
        ->and($result['normalized'])->toBeNull()
        ->and($result['original'])->toBe(trim($input));
})->with([
    'sin arroba' => ['maria.example.com'],
    'sin dominio' => ['maria@'],
    'dominio sin punto' => ['maria@localhost'],
    'doble arroba' => ['maria@@example.com'],
    'solo texto' => ['no tiene mail'],
]);

it('distingue ausente de invalido', function (mixed $input) {
    $result = EmailNormalizer::normalize($input);

    expect($result['status'])->toBe(EmailNormalizer::STATUS_MISSING)
        ->and($result['original'])->toBeNull();
})->with([
    'nulo' => [null],
    'cadena vacia' => [''],
    'solo espacios' => ['    '],
    'no es cadena' => [123],
]);
