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
# The PSX cache is keyed by the absolute realpath of each source, so
# the build MUST run at the same path the container serves from
# (/var/www/html). relayer 0.8.0 uses ONE cache dir for everything —
# components, pages and layout — at <projectRoot>/var/cache/psx (see
# Relayer::boot, which pins psxCacheDir there; an earlier split into
# src/var/cache/psx is what 0.8.0 explicitly removed). So both source
# roots are compiled together into that single dir: PascalCase
# components land in manifest.php and are resolved via the runtime
# manifest; lowercase page.psx/layout.psx are loaded directly by their
# sha1 path. At runtime the app does zero filesystem writes (Turso
# over HTTP, time-based + ETag cache, NullProfiler).
FROM dunglas/frankenphp:php8.5

# The official php base (shared with FrankenPHP) already bundles
# mbstring/opcache/ctype/curl/openssl/json/tokenizer. Added here:
# unzip (composer dist extraction) and ext-gd built with FreeType,
# which the OG card route needs (App\Og\OgImage → imagettftext). The
# -dev headers are for the build; docker-php-ext-install pulls their
# runtime shared libs in as dependencies, so they persist for the
# extension at runtime. The CJK font is vendored in the repo
# (assets/fonts/ipaexg.ttf), copied with the tree below — no font
# package needed, and the rendered card is byte-identical to local.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        unzip \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev; \
    docker-php-ext-configure gd --with-freetype; \
    docker-php-ext-install -j"$(nproc)" gd; \
    rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Start from PHP's production ini; conf.d/zz-relayer.ini below
# overrides the specifics (opcache, error logging).
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

ENV APP_ENV=production \
    COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /var/www/html

# Full tree first (composer post-install publishes public/usephp.js),
# then install + precompile the PSX cache. One invocation over both
# source roots → one manifest covering the components, with every
# compiled artifact in the single dir relayer resolves from. ABSOLUTE
# --cache so the manifest stores absolute paths that resolve at runtime.
#
# `relayer routes:compile` is the route-level counterpart: it scans
# src/Pages once at build (filesystem only — no Turso) and writes
# var/cache/routes/routes.php, so the runtime reads that OPcache'd
# snapshot instead of walking the tree on every request (classic mode
# = one scan per request otherwise). It reuses the router's own
# PageScanner, so a route-group URL collision or page/route ambiguity
# fails the build here, at deploy, not on the first production request.
COPY . .
RUN set -eux; \
    composer install --no-dev --optimize-autoloader --no-interaction; \
    php vendor/bin/usephp compile src/Components src/Pages --cache=/var/www/html/var/cache/psx; \
    php vendor/bin/relayer routes:compile; \
    rm -rf var/cache/profiler var/cache/etags

COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-relayer.ini

# Caddy serves plain HTTP on :8080 (see docker/Caddyfile); fly-proxy
# terminates TLS at the edge. Matches fly.toml internal_port. The base
# image's entrypoint runs `frankenphp run --config
# /etc/frankenphp/Caddyfile`, so no CMD/ENTRYPOINT override is needed.
EXPOSE 8080
