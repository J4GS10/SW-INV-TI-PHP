# =========================================================================
# API Inventario TI en PHP 8.5 (Symfony) sobre FrankenPHP.
#
# Oracle Database 21c vía la extensión oci8: a diferencia del driver thin de
# Python, PHP necesita Oracle Instant Client (librerías nativas, gratuitas).
# `install-php-extensions` lo descarga (Basic Lite) e instala junto con oci8.
#
#   docker build -f docker/Dockerfile --target dev  -t inventario-php-dev  .
#   docker build -f docker/Dockerfile --target prod -t inventario-php      .
#
# Etapas:
#   base       FrankenPHP + extensiones (compila; trae cabeceras y compiladores)
#   dev        base + Composer + pcov: pruebas y desarrollo (código montado)
#   vendor     dependencias sin las de desarrollo y autoload optimizado
#   ejecucion  reúne SOLO lo que se ejecuta: binarios, extensiones, librerías
#              dinámicas (por ldd, las mismas con las que se compiló) e Instant Client
#   prod       debian:bookworm-slim + ejecucion + la aplicación: sin Composer,
#              sin cabeceras ni compiladores, sin pcov ni herramientas de desarrollo
# =========================================================================

# --------- Base: PHP 8.5 + extensiones ---------
FROM dunglas/frankenphp:1-php8.5-bookworm AS base

ENV TZ=UTC \
    APP_DIR=/app

RUN install-php-extensions \
        oci8 \
        intl \
        opcache \
        redis \
        zip \
        sodium \
        bcmath \
        gd \
    && php -m | grep -qi '^oci8$'

# ext-ldap (Active Directory por LDAPS/StartTLS) y pcntl (el worker de tareas
# termina la tarea en curso antes de detenerse con SIGTERM). Capa aparte: no
# invalida la de oci8 y las demás extensiones.
RUN install-php-extensions ldap pcntl \
    && php -m | grep -qi '^ldap$' \
    && php -m | grep -qi '^pcntl$'

WORKDIR /app


# --------- Desarrollo y pruebas: Composer + código montado ---------
FROM base AS dev
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1 \
    APP_ENV=dev
# PECL por HTTPS: en una red con inspección TLS la CA llega como secreto de BuildKit
# (igual que para Composer) y solo se usa en este paso.
RUN --mount=type=secret,id=ca_corporativa,required=false \
    if [ -s /run/secrets/ca_corporativa ]; then \
        cat /etc/ssl/certs/ca-certificates.crt /run/secrets/ca_corporativa > /tmp/ca.pem && export SSL_CERT_FILE=/tmp/ca.pem; \
    fi \
    && install-php-extensions pcov \
    && rm -f /tmp/ca.pem
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-inventario.ini


# --------- Dependencias de producción y autoload ---------
FROM dev AS vendor
COPY api/composer.json api/composer.lock ./
# Red con inspección TLS (firewall corporativo): la CA se pasa como secreto de
# BuildKit (`--secret id=ca_corporativa,src=<pem>`) y solo la usa Composer en este
# paso; no queda en ninguna capa. Sin el secreto, el build es el de siempre.
RUN --mount=type=secret,id=ca_corporativa,required=false \
    if [ -s /run/secrets/ca_corporativa ]; then \
        cat /etc/ssl/certs/ca-certificates.crt /run/secrets/ca_corporativa > /tmp/ca.pem && export COMPOSER_CAFILE=/tmp/ca.pem; \
    fi \
    && composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress \
    && rm -f /tmp/ca.pem
COPY api/ ./
RUN composer dump-autoload --no-dev --classmap-authoritative \
    && rm -rf var && mkdir -p var/cache var/log


# --------- Sistema de archivos de ejecución ---------
FROM debian:bookworm-slim AS slim

FROM base AS ejecucion
# Se copia a /rootfs solo lo necesario para ejecutar. Las librerías dinámicas
# salen de ldd (binarios, extensiones e Instant Client) y se omiten las que ya
# trae debian:bookworm-slim. Rutas bajo /usr (la base usa /lib -> usr/lib).
# `cp` sin -a: el binario de FrankenPHP pierde la capability CAP_NET_BIND_SERVICE
# (puertos < 1024), que con `cap_drop: ALL` y `no-new-privileges` impide
# ejecutarlo; escucha en :8000 y no la necesita.
RUN --mount=type=bind,from=slim,target=/slim \
    set -eu; \
    ext="$(php -r 'echo ini_get("extension_dir");')"; \
    ic=/usr/lib/oracle/21.1/client64/lib; \
    mkdir -p /rootfs; \
    copiar() { for f in "$@"; do mkdir -p "/rootfs$(dirname "$f")"; cp -L "$f" "/rootfs$f"; done; }; \
    binarios="/usr/local/bin/frankenphp /usr/local/bin/php /usr/bin/curl"; \
    copiar $binarios /usr/local/bin/docker-php-entrypoint "$ext"/*.so \
        "$ic/libclntsh.so.21.1" "$ic/libclntshcore.so.21.1" "$ic/libnnz21.so" "$ic/libociicus.so" \
        "$ic/BASIC_LITE_LICENSE" "$ic/BASIC_LITE_README"; \
    for l in $(for f in $binarios "$ext"/*.so "$ic/libclntsh.so.21.1" "$ic/libociicus.so"; do ldd "$f"; done \
               | awk '/=> \//{print $3}' | sort -u); do \
        p="$(echo "$l" | sed 's#^/lib/#/usr/lib/#')"; \
        [ -e "/slim$p" ] || copiar "$p"; \
    done; \
    # Instant Client: oci8 lo busca por su soname (libclntsh.so.21.1) en ld.so.conf.d.
    mkdir -p /rootfs/etc/ld.so.conf.d; \
    cp /etc/ld.so.conf.d/*.conf /rootfs/etc/ld.so.conf.d/; \
    # Configuración de PHP (extensiones habilitadas), CA públicas, tipos MIME y FrankenPHP.
    extras="/usr/local/etc/php/conf.d /etc/ssl /usr/share/ca-certificates /etc/mime.types /etc/frankenphp"; \
    [ -e /slim/usr/lib/ssl ] || extras="$extras /usr/lib/ssl"; \
    tar -cf - $extras 2>/dev/null | tar -xf - -C /rootfs; \
    rm -f /rootfs/etc/frankenphp/Caddyfile; \
    mkdir -p /rootfs/data/caddy /rootfs/config/caddy /rootfs/app


# --------- Producción ---------
FROM debian:bookworm-slim AS prod
ENV TZ=UTC \
    APP_DIR=/app \
    APP_ENV=prod \
    APP_DEBUG=0 \
    SERVER_NAME=":8000" \
    PHP_INI_DIR=/usr/local/etc/php \
    XDG_CONFIG_HOME=/config \
    XDG_DATA_HOME=/data \
    GODEBUG=cgocheck=0
COPY --from=ejecucion /rootfs/ /
RUN ldconfig \
    && useradd -m -u 1001 -s /bin/bash appuser \
    && chown -R appuser:appuser /data/caddy /config/caddy \
    && php -m | grep -qi '^oci8$' && php -m | grep -qi '^ldap$' && php -m | grep -qi '^intl$' \
    && frankenphp version
WORKDIR /app
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-inventario.ini
# El código queda de root (solo lectura para la aplicación); appuser escribe solo en var/.
COPY --from=vendor /app /app
RUN chown -R appuser:appuser var
USER appuser
EXPOSE 8000
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD curl --fail --silent http://127.0.0.1:8000/health || exit 1
ENTRYPOINT ["docker-php-entrypoint"]
CMD ["--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]
