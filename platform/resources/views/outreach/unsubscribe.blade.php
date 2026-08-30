<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <meta name="robots" content="noindex">
    <title>Baja de suscripción</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=outfit:400,500,600&display=swap" rel="stylesheet">
    <style>
        /* Vista pública: no pasa por Vite ni por Tailwind, así que los valores
           de marca van escritos a mano. Tema OSCURO del producto: canvas #121212,
           superficie #181818, acento violeta #7340ff.
           En este sistema la elevación NO se comunica con sombra: la tarjeta se
           define por un borde de 1px. La sombra azul translúcida del tema viejo
           se sacó (sobre negro no se ve y era de otra paleta). */
        body { font-family: "Outfit", system-ui, sans-serif; background: #121212; color: #f2f2f2;
               display: grid; place-items: center; min-height: 100vh; margin: 0; padding: 1.5rem;
               -webkit-font-smoothing: antialiased; }
        .card { background: #181818; border: 1px solid #2f3237; border-radius: 28px;
                padding: 2.5rem 2.25rem; max-width: 30rem; width: 100%; }
        h1 { font-size: 1.375rem; line-height: 1.3; font-weight: 600; letter-spacing: -.01em;
             color: #f2f2f2; margin: 0 0 .75rem; }
        /* #8b9197 sobre #181818 ≈ 5.5:1. Es el gris secundario de la marca y
           pasa AA sin tener que aclararlo. */
        p { color: #8b9197; line-height: 1.65; margin: 0 0 1rem; font-size: .9375rem; }
        p:last-child { margin-bottom: 0; }
        strong { color: #f2f2f2; font-weight: 600; }
        form { margin: 1.75rem 0 0; }
        /* Única acción de la pantalla: píldora violeta, blanco puro encima
           (el blanco puro sólo se usa sobre violeta). #ffffff sobre #7340ff ≈ 5.4:1. */
        button { background: #7340ff; color: #ffffff; border: 0; border-radius: 999px;
                 min-height: 44px; padding: .75rem 1.75rem; font-family: inherit; font-size: .9375rem;
                 font-weight: 600; line-height: 1.2; cursor: pointer;
                 transition: background-color .15s ease, box-shadow .15s ease; }
        /* El halo violeta es el gesto de interacción de la marca; reemplaza a la
           sombra de elevación, que acá no existe. */
        button:hover { background: #8659ff; box-shadow: 0 0 24px -2px rgba(115, 64, 255, .35); }
        button:active { background: #2c0f7e; box-shadow: none; }
        /* El CSS de producción de la marca no define foco en ningún botón.
           Este anillo es decisión nuestra y se conserva a propósito. */
        button:focus-visible { outline: 2px solid #8659ff; outline-offset: 3px; }
        /* Sobre fondo oscuro el verde pleno SÍ es legible (al revés que sobre
           blanco): #06c996 sobre #181818 ≈ 8.1:1, así que vuelve el tono de marca
           y se descarta el #04815f oscurecido del tema claro, que acá no se leía. */
        .ok { color: #06c996; }
    </style>
</head>
<body>
    <div class="card">
        @if ($alreadyUnsubscribed)
            <h1 class="ok">Listo, no vas a recibir más estos correos</h1>
            <p>
                Tu dirección quedó dada de baja de las comunicaciones de
                <strong>{{ $tenantName }}</strong>. No hace falta que hagas nada más.
            </p>
            {{-- La baja se guarda por dirección, no por cuenta: sigue valiendo
                 aunque el comercio vuelva a cargar sus datos. --}}
            <p>La baja se aplica a esta dirección de correo, aunque el comercio vuelva a cargar sus registros.</p>
        @else
            <h1>¿Querés dejar de recibir estos correos?</h1>
            <p>
                Vas a dejar de recibir las comunicaciones de <strong>{{ $tenantName }}</strong>@if ($campaignName),
                incluida la campaña «{{ $campaignName }}»@endif.
            </p>
            {{-- La baja va en un POST y no en el GET: los clientes de correo y
                 los escaneadores de seguridad abren los enlaces solos, y con la
                 baja en el GET media lista se daría de baja sin pedirlo. --}}
            <form method="POST" action="{{ $action }}">
                @csrf
                <button type="submit">Darme de baja</button>
            </form>
        @endif
    </div>
</body>
</html>
