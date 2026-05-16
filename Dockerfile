# Relayer docs site — Cloud Run image (nginx + php-fpm).
#
# Single stage on purpose: the PSX caches are keyed by the absolute
# realpath of each source, so the build must run at the same path the
# container serves from (/var/www/html). Two caches are precompiled:
#   - components -> /var/www/html/var/cache/psx      (manifest, absolute paths)
#   - pages/layout -> /var/www/html/src/var/cache/psx (dirname(appDir)/var/cache)
# Pages reference components via `// @psx-runtime` so the batch
# compiler resolves them through the runtime manifest. At runtime the
# app does zero filesystem writes (Turso over HTTP, literal-ETag
# cache, NullProfiler), so Cloud Run's read-only FS is fine — only
# /tmp (nginx temp + sessions) needs to be writable.
FROM php:8.5-fpm

# php:8.5-fpm already bundles mbstring/opcache/ctype/curl/openssl/
# json/tokenizer. Only nginx + unzip (composer dist) are added.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends nginx unzip; \
    rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV APP_ENV=production \
    COMPOSER_ALLOW_SUPERUSER=1 \
    PORT=8080

WORKDIR /var/www/html

# Full tree first (composer post-install publishes public/usephp.js),
# then install + precompile both PSX caches with ABSOLUTE --cache so
# the component manifest stores absolute paths that resolve at runtime.
COPY . .
RUN set -eux; \
    composer install --no-dev --optimize-autoloader --no-interaction; \
    php vendor/bin/usephp compile src/Components --cache=/var/www/html/var/cache/psx; \
    php vendor/bin/usephp compile src/Pages --cache=/var/www/html/src/var/cache/psx; \
    rm -rf var/cache/profiler var/cache/etags; \
    chown -R www-data:www-data /var/www/html

COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-relayer.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-relayer.ini
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Cloud Run injects $PORT (default 8080); entrypoint binds nginx to it.
EXPOSE 8080
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
