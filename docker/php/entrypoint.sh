#!/bin/sh
set -e

# El contenedor arranca sin ninguna cache para que las variables de entorno
# del compose manden. Se cachea despues, ya resueltas.
#
# Se borran las TRES y no solo la de config. Con el override de desarrollo el
# codigo entra por bind mount, asi que bootstrap/cache/ es un directorio del
# HOST: una cache que quedo escrita ahi en otra corrida entra al contenedor y
# le gana a las variables del compose. El sintoma es opaco — la aplicacion
# intenta conectarse a 127.0.0.1 y devuelve 500 — y no tiene nada que ver con
# el codigo que uno acaba de tocar.
rm -f bootstrap/cache/config.php bootstrap/cache/routes-v7.php bootstrap/cache/events.php

if [ "${D4S_CACHE_CONFIG:-true}" = "true" ]; then
  php artisan config:cache >/dev/null
  php artisan route:cache >/dev/null
  php artisan event:cache >/dev/null
fi

exec "$@"
