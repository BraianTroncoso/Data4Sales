{{-- Cuerpo en texto plano. El contenido llega ya renderizado por
     TemplateRenderer, que sólo hace reemplazo literal sobre una lista blanca:
     acá no se interpola nada del comprador. --}}
{!! $body !!}

--
Si no querés recibir más estos correos, date de baja acá:
{{ $unsubscribeUrl }}
