<?php

declare(strict_types=1);

use App\Domain\Outreach\Models\TemplateVersion;
use App\Domain\Outreach\Rendering\RecipientContext;
use App\Domain\Outreach\Rendering\TemplateRenderer;

/*
|--------------------------------------------------------------------------
| El renderizador no puede devolver la plantilla cruda
|--------------------------------------------------------------------------
|
| Vive en Feature y no en Unit porque instancia modelos de Eloquent, que
| necesitan la aplicación levantada. No toca la base: las versiones de
| plantilla se construyen en memoria.
|
*/

function trVersion(string $bodyText, array $declared = ['nombre']): TemplateVersion
{
    return new TemplateVersion([
        'subject' => 'Asunto',
        'body_text' => $bodyText,
        'declared_variables' => $declared,
    ]);
}

function trContexto(?string $nombre = 'Ana'): RecipientContext
{
    return RecipientContext::fromRow([
        'customer_id' => '11111111-1111-4111-8111-111111111111',
        'email' => 'ana@example.test',
        'name' => $nombre,
        'orders_count' => 4,
        'avg_order_minor' => 200000,
        'recency_days' => 3,
    ]);
}

it('reemplaza las variables declaradas', function () {
    $salida = (new TemplateRenderer)->render(trVersion('Hola {{ nombre }}.'), trContexto());

    expect($salida['text'])->toBe('Hola Ana.');
});

it('falla si el reemplazo no se pudo hacer, en vez de mandar la plantilla cruda', function () {
    $original = ini_get('pcre.backtrack_limit');

    // Se fuerza el modo de falla real de PCRE: con un cuerpo grande, el límite
    // de backtracking se agota y `preg_replace_callback` devuelve null.
    ini_set('pcre.backtrack_limit', '1');

    try {
        $version = trVersion('Hola {{ nombre }}, '.str_repeat('gracias por comprar. ', 200));

        // `?? $template` devolvía la plantilla SIN reemplazar: salía un correo
        // que decía literalmente "Hola {{ nombre }}". Es el mismo "Hola ," que
        // el docblock de la clase dice hacer imposible, con el agravante de
        // que no hay error — nadie se entera hasta que lo recibe un cliente.
        //
        // Una campaña que no sale se descubre en el primer envío de prueba.
        expect(fn () => (new TemplateRenderer)->render($version, trContexto()))
            ->toThrow(RuntimeException::class);
    } finally {
        ini_set('pcre.backtrack_limit', $original === false ? '1000000' : $original);
    }
});

it('falla si la variable no está declarada', function () {
    // La lista blanca no es decorativa: sin ella, `{{ password_hash }}` se
    // reemplazaría por lo que hubiera en el contexto.
    expect(fn () => (new TemplateRenderer)->render(trVersion('Hola {{ apellido }}.'), trContexto()))
        ->toThrow(RuntimeException::class);
});

it('falla si el destinatario no tiene valor para una variable declarada', function () {
    // Un correo que dice "Hola ," es peor que uno que no sale.
    expect(fn () => (new TemplateRenderer)->render(trVersion('Hola {{ nombre }}.'), trContexto(nombre: null)))
        ->toThrow(RuntimeException::class);
});
