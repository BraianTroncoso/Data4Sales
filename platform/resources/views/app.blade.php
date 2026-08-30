<!DOCTYPE html>
{{--
    `lang` fijo en español, a propósito.

    Antes era `app()->getLocale()`, que resuelve a `en` (config/app.php deja el
    default y .env no define APP_LOCALE): la interfaz está íntegramente en
    español y se servía como inglés, así que un lector de pantalla la
    pronunciaba con fonética inglesa.

    No se toca APP_LOCALE acá porque no existe `lang/es/`: cambiar el locale sin
    las traducciones haría que Laravel mostrara las claves crudas (`auth.failed`)
    en lugar de los mensajes. Ese arreglo es de backend y va aparte.
--}}
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="dark">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        {{-- Outfit es la tipografía de marca de Data4Sales (308 usos en su CSS
             de producción); Roboto es la de las cifras tabulares. --}}
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=outfit:300,400,500,600,700|roboto:400,500,700&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @routes
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
