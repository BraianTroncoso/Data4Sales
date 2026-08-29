<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Baja de suscripción</title>
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; background: #f8fafc; color: #0f172a;
               display: grid; place-items: center; min-height: 100vh; margin: 0; padding: 1.5rem; }
        .card { background: #fff; border: 1px solid #e2e8f0; border-radius: .75rem;
                padding: 2rem; max-width: 30rem; box-shadow: 0 1px 3px rgb(0 0 0 / .06); }
        h1 { font-size: 1.125rem; margin: 0 0 .75rem; }
        p { color: #475569; line-height: 1.6; margin: 0 0 1rem; font-size: .9375rem; }
        button { background: #0f172a; color: #fff; border: 0; border-radius: .5rem;
                 padding: .625rem 1.25rem; font-size: .9375rem; cursor: pointer; }
        .ok { color: #047857; font-weight: 500; }
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
