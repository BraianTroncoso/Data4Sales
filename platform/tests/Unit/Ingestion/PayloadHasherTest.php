<?php

declare(strict_types=1);

use App\Domain\Ingestion\Support\PayloadHasher;

it('da la misma huella sin importar el orden de las claves', function () {
    $a = ['order_id' => 'ORD-1', 'total' => 100, 'status' => 'paid'];
    $b = ['status' => 'paid', 'order_id' => 'ORD-1', 'total' => 100];

    expect(PayloadHasher::hash($a))->toBe(PayloadHasher::hash($b));
});

it('ordena tambien las claves anidadas', function () {
    $a = ['id' => 1, 'customer' => ['email' => 'a@b.com', 'name' => 'Ana']];
    $b = ['customer' => ['name' => 'Ana', 'email' => 'a@b.com'], 'id' => 1];

    expect(PayloadHasher::hash($a))->toBe(PayloadHasher::hash($b));
});

it('NO ignora el orden de las listas', function () {
    // Dos items en distinto orden son la misma orden, pero tratarlos como
    // identicos ocultaria un reordenamiento real del renglon. Se prefiere
    // procesar de mas antes que descartar de menos.
    $a = ['items' => [['sku' => 'A'], ['sku' => 'B']]];
    $b = ['items' => [['sku' => 'B'], ['sku' => 'A']]];

    expect(PayloadHasher::hash($a))->not->toBe(PayloadHasher::hash($b));
});

it('cambia la huella si cambia cualquier valor', function () {
    $base = ['order_id' => 'ORD-1', 'status' => 'paid'];
    $changed = ['order_id' => 'ORD-1', 'status' => 'shipped'];

    expect(PayloadHasher::hash($base))->not->toBe(PayloadHasher::hash($changed));
});

it('produce un sha256 en hexadecimal', function () {
    expect(PayloadHasher::hash(['a' => 1]))->toMatch('/^[0-9a-f]{64}$/');
});
