# syntax=docker/dockerfile:1

# ─────────────────────────────────────────────────────────────
# Stage 1 — vendor: dependencias PHP sin dev, con autoloader
# optimizado. Va primero porque el frontend depende de el: Ziggy
# publica su modulo JavaScript dentro del paquete de Composer, no
# en npm, asi que sin vendor/ el bundle no resuelve.
# ─────────────────────────────────────────────────────────────
FROM composer:2 AS vendor
WORKDIR /build
COPY platform/composer.json platform/composer.lock ./

# La imagen de composer no trae pcntl ni posix, que Horizon exige. La imagen
# de runtime si las tiene (ver stage 3), asi que se ignoran solo aca, durante
# la resolucion de dependencias. Ignorarlas en runtime seria otra cosa: ahi
# significaria que Horizon no puede manejar senales y no se apagaria limpio.
RUN composer install \
      --no-dev \
      --no-scripts \
      --no-autoloader \
      --prefer-dist \
      --no-interaction \
      --ignore-platform-req=ext-pcntl \
      --ignore-platform-req=ext-posix

COPY platform/ ./
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev

# ─────────────────────────────────────────────────────────────
# Stage 2 — assets: compila el frontend (Vue + Inertia + Tailwind).
# Se hace en build time para que `docker compose up` no dependa de
# un dev server: el evaluador levanta y ve la app ya compilada.
# ─────────────────────────────────────────────────────────────
FROM node:22-alpine AS assets
WORKDIR /build
COPY platform/package.json platform/package-lock.json ./
RUN npm ci
COPY platform/ ./
COPY --from=vendor /build/vendor ./vendor
RUN npm run build

# ─────────────────────────────────────────────────────────────
# Stage 3 — runtime
# ─────────────────────────────────────────────────────────────
FROM php:8.4-fpm-alpine AS runtime

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions \
      pdo_pgsql \
      pgsql \
      redis \
      pcntl \
      posix \
      bcmath \
      intl \
      zip \
      opcache

RUN apk add --no-cache postgresql-client

WORKDIR /var/www/html

COPY --from=vendor /build /var/www/html
COPY --from=assets /build/public/build /var/www/html/public/build

COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-d4s.ini
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rw storage bootstrap/cache

USER www-data

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

# ─────────────────────────────────────────────────────────────
# Stage 4 — web: nginx con los archivos publicos ya adentro.
#
# nginx y php-fpm corren en contenedores separados, y nginx necesita
# ver el filesystem para resolver `try_files` y servir los assets
# compilados. Copiarlos a su imagen (en vez de compartir un volumen)
# evita el problema clasico del volumen nombrado que se queda con la
# version vieja despues de un rebuild.
# ─────────────────────────────────────────────────────────────
FROM nginx:1.27-alpine AS web

COPY --from=runtime /var/www/html/public /var/www/html/public
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf

# ─────────────────────────────────────────────────────────────
# Stage 5 — test: la imagen de runtime mas las dependencias de
# desarrollo. Se construye aparte para que la imagen que se
# despliega no cargue con Pest, Larastan ni sus arboles de
# dependencias.
# ─────────────────────────────────────────────────────────────
FROM composer:2 AS vendor-dev
WORKDIR /build
COPY platform/composer.json platform/composer.lock ./
RUN composer install \
      --prefer-dist \
      --no-scripts \
      --no-autoloader \
      --no-interaction \
      --ignore-platform-req=ext-pcntl \
      --ignore-platform-req=ext-posix
COPY platform/ ./
RUN composer dump-autoload

FROM runtime AS test
USER root
COPY --from=vendor-dev /build/vendor /var/www/html/vendor

# phpdotenv lee .env con @file_get_contents. El @ suprime el error en
# ejecucion normal, pero el manejador de errores de PHPUnit lo convierte
# igual en warning y ensucia cada test de la suite. Un .env vacio lo
# silencia sin cambiar nada: la configuracion real viene del entorno.
RUN touch /var/www/html/.env

RUN chown -R www-data:www-data /var/www/html/vendor /var/www/html/.env storage bootstrap/cache
USER www-data
ENTRYPOINT []
CMD ["./vendor/bin/pest"]
