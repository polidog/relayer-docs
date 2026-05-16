# Relayer docs site — Fly.io image (FrankenPHP, single process).
#
# FrankenPHP (Caddy + embedded PHP) replaces the previous
# nginx + php-fpm pair: one process speaks HTTP directly, so there is
# no FastCGI bridge, no entrypoint port-substitution, and no
# read-only-FS gymnastics. Classic mode (no `worker` directive in the
# Caddyfile) keeps it a drop-in for php-fpm — one script execution per
# request, no state shared between requests — so PSX/Relayer needs no
# adaptation.
#
# The PSX caches are keyed by the absolute realpath of each source, so
# the build MUST run at the same path the container serves from
# (/var/www/html). Two caches are precompiled:
#   - components -> /var/www/html/var/cache/psx       (manifest, absolute paths)
#   - pages/layout -> /var/www/html/src/var/cache/psx (dirname(appDir)/var/cache)
# Pages reference components via `// @psx-runtime` so the batch
# compiler resolves them through the runtime manifest. At runtime the
# app does zero filesystem writes (Turso over HTTP, literal-ETag
# cache, NullProfiler).
FROM dunglas/frankenphp:php8.5

# The official php base (shared with FrankenPHP) already bundles
# mbstring/opcache/ctype/curl/openssl/json/tokenizer. Only unzip
# (composer dist extraction) is added.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends unzip; \
    rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Start from PHP's production ini; conf.d/zz-relayer.ini below
# overrides the specifics (opcache, error logging).
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

ENV APP_ENV=production \
    COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /var/www/html

# Full tree first (composer post-install publishes public/usephp.js),
# then install + precompile both PSX caches with ABSOLUTE --cache so
# the component manifest stores absolute paths that resolve at runtime.
COPY . .
RUN set -eux; \
    composer install --no-dev --optimize-autoloader --no-interaction; \
    php vendor/bin/usephp compile src/Components --cache=/var/www/html/var/cache/psx; \
    php vendor/bin/usephp compile src/Pages --cache=/var/www/html/src/var/cache/psx; \
    rm -rf var/cache/profiler var/cache/etags

COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-relayer.ini

# Caddy serves plain HTTP on :8080 (see docker/Caddyfile); fly-proxy
# terminates TLS at the edge. Matches fly.toml internal_port. The base
# image's entrypoint runs `frankenphp run --config
# /etc/frankenphp/Caddyfile`, so no CMD/ENTRYPOINT override is needed.
EXPOSE 8080
