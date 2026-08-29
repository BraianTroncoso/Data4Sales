<?php

declare(strict_types=1);

it('redirige la raíz al dashboard', function () {
    // La raíz no tiene contenido propio: esta es una herramienta interna, no
    // un sitio público. Quien entra sin sesión termina en el login, y quien
    // la tiene va a la pantalla que su rol puede ver.
    $this->get('/')->assertRedirect(route('dashboard'));
});

it('responde el chequeo de salud', function () {
    $this->get('/up')->assertOk();
});
