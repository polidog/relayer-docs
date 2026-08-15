# syntax=docker/dockerfile:1
# Relayer docs site — SINGLE-BINARY (embedded) Fly.io image.
#
# Sibling of ./Dockerfile, which builds the same app the ordinary way
# (dunglas/frankenphp:php8.5 + the app tree on disk). This one compiles
# PHP, the extensions, Caddy and FrankenPHP from source with
# static-php-cli and tars the whole application INTO the resulting
# executable, so the runtime image is one file plus a CA bundle.
#
# Build and run locally:
#
#   GH_TOKEN="$(gh auth token)" docker build \
#     --secret id=github_token,env=GH_TOKEN \
#     -t relayer-doc-static -f static-build.Dockerfile .
#   docker run --rm -p 8080:8080 --env-file .env.local relayer-doc-static
#
# Classic mode (one script execution per request, like php-fpm) is the
# default. Worker mode is the same binary with an env var:
#
#   docker run --rm -p 8080:8080 --env-file .env.local \
#     -e RELAYER_WORKER=1 relayer-doc-static
#
# Grab just the binary (it is self-contained — it runs on any glibc
# >= 2.17 host, no PHP installed):
#
#   docker create --name x relayer-doc-static && \
#     docker cp x:/usr/local/bin/relayer-doc ./relayer-doc && docker rm x
#   ./relayer-doc php-server
#
# Cost warning: stage 2 builds PHP from source. That is tens of minutes
# on a cold cache, and the `COPY --from=app` line invalidates it on
# every application change, so this is NOT a drop-in replacement for
# the classic image in the per-push deploy path without a persistent
# build cache. Keep fly.toml pointed at ./Dockerfile until that is
# settled.

########################################################################
# Stage 1 — prepare the application tree that gets embedded.
#
# Only Composer runs here. Unlike the classic image, the PSX and route
# caches are NOT precompiled at build time: they are named after the
# absolute path of their sources, and the embedded app's absolute path
# is $TMPDIR/frankenphp_<md5 of the tar> — unknowable before the tar
# exists. docker/embed/entrypoint.sh compiles them at container start
# instead (~0.1s). See docker/embed/embed-compile.php.
########################################################################
FROM dunglas/frankenphp:php8.5 AS app

# unzip for Composer's dist extraction, same as the classic image. No
# gd/-dev headers here: this stage never runs application code, it only
# resolves dependencies — the extensions are compiled in stage 2.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends unzip; \
    rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV APP_ENV=production \
    COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app

# The build path is irrelevant this time (nothing path-keyed is
# generated here), so /app rather than /var/www/html.
COPY . .

RUN composer install --no-dev --optimize-autoloader --no-interaction

# Files that only exist in the embedded build. `php.ini` MUST sit at the
# app root — `frankenphp php-server` appends that directory to
# PHP_INI_SCAN_DIR only for a file of exactly that name.
#
# The two Caddyfiles ship side by side and NEITHER is named `Caddyfile`:
# the entrypoint installs one of them under that name at boot, picked by
# RELAYER_WORKER. `php-server` loads a file called `Caddyfile` from the
# app root and then ignores every CLI flag, so that name is the switch
# between classic and worker mode — and keeping both in the binary makes
# the switch a restart rather than a rebuild.
#
# worker.php itself is not copied here: it lives at the repo root and
# arrives with the tree above, deliberately outside public/ so it can
# never be served as a request.
COPY docker/embed/php.ini ./php.ini
COPY docker/embed/Caddyfile.classic ./Caddyfile.classic
COPY docker/embed/Caddyfile.worker ./Caddyfile.worker
COPY docker/embed/embed-root.php docker/embed/embed-compile.php ./bin/

# vendor/bin holds Composer's symlinks, and FrankenPHP's untar silently
# skips symlinks — they would be dangling references inside the binary.
# Nothing needs them (the entrypoint calls the commands through the two
# bin/embed-*.php scripts), so drop them rather than ship them broken.
RUN rm -rf vendor/bin var

########################################################################
# Stage 2 — compile the static binary with the app embedded.
#
# static-builder-gnu (not -musl): mostly-static, needs glibc >= 2.17 at
# runtime, and upstream recommends it wherever Alpine/scratch is not a
# hard requirement — musl's allocator costs real request throughput.
########################################################################
FROM --platform=linux/amd64 dunglas/frankenphp:static-builder-gnu AS builder

WORKDIR /go/src/app/dist/app
COPY --from=app /app ./

WORKDIR /go/src/app

# Keep the spc that ships INSIDE this image instead of re-downloading it.
#
# The image already carries the static-php-cli driver (2.8.6) at
# dist/static-php-cli/spc together with a fully prebuilt buildroot —
# libcurl.a, libssl.a, libldap.a and friends, all compiled by exactly
# that spc. build-static.sh nevertheless overwrites the binary with a
# nightly from dl.static-php.dev on every run, which fails two ways:
#
#   * That host is regularly unreachable from here (one build died on
#     `curl: (18) transfer closed` after 400s at ~2KB/s; IPv4 returned
#     zero bytes in 20s).
#   * A spc that does not match the prebuilt buildroot links garbage.
#     Pulling 2.8.5 from GitHub Releases as a workaround got further and
#     then died in xcaddy on hundreds of `undefined reference to
#     ldap_*`: the image's libcurl.a was built WITH LDAP by 2.8.6, while
#     2.8.5 composes CGO_LDFLAGS without -lldap.
#
# So the fetch is simply skipped whenever a spc is already present. This
# removes a network dependency AND guarantees driver/buildroot parity.
# The grep is the guard: if a future build-static.sh renames or reshapes
# that line the patch stops applying, and this turns that into an
# immediate build failure instead of a silent 400-second hang.
RUN set -eux; \
    sed -i 's#^\([[:space:]]*\)curl -o spc -fsSL#\1[ -f spc ] || curl -o spc -fsSL#' build-static.sh; \
    grep -q '\[ -f spc \] ||' build-static.sh; \
    test -x dist/static-php-cli/spc

# Pin where the binary untars itself, instead of taking FrankenPHP's
# default of $TMPDIR/frankenphp_<md5 of the embedded tar>.
#
# That default is a moving target: the checksum changes with every
# application change, so the app's absolute path changes on every
# deploy. usePHP names each compiled component `sha1(<absolute path of
# the source>)` and emits those ids into the HTML as
# `data-usephp="FC@<sha1>.php"`, so a moving root means the markup
# changes on every deploy even when the content does not — while the
# ETag, which is derived from the document content, stays put. With
# s-maxage=30d in front of this site, that is a recipe for a CDN copy
# whose component ids no longer match the running deployment.
#
# Pinning to /var/www/html — the path the classic image serves from —
# makes the two builds produce byte-identical HTML and makes the ids
# stable across deploys. FrankenPHP exposes this as a Go ldflag
# (`-X ...EmbeddedAppPath=`), but static-php-cli hardcodes
# XCADDY_GO_BUILD_FLAGS with no injection point, so set the default in
# the source instead. The grep is the guard: if upstream ever changes
# that declaration, the build fails here rather than silently reverting
# to a per-deploy temp directory.
RUN set -eux; \
    sed -i 's#^var EmbeddedAppPath string$#var EmbeddedAppPath = "/var/www/html"#' embed.go; \
    grep -q 'var EmbeddedAppPath = "/var/www/html"' embed.go

# Build everything from source (--with-clean), with the extension set
# this app actually uses.
#
# The tempting shortcut is to reuse the buildroot this image ships
# prebuilt — it holds libcurl.a, libssl.a, libldap.a and ~80 others, and
# skipping their compilation turns a 45-minute build into a 7-minute
# one. It does not work, in two escalating ways:
#
#   * spc reuses those .a files instead of rebuilding them, but composes
#     CGO_LDFLAGS from whatever extension list it was given. The
#     prebuilt libcurl.a wants nghttp2, zstd, libssh2 and openldap; a
#     narrower list omits those -l flags and the build dies in the final
#     xcaddy link with hundreds of `undefined reference to
#     ZSTD_versionNumber / libssh2_* / ldap_*`.
#   * Matching the image's own default extension list dodges that and
#     gets all the way through "Build complete" and the frankenphp
#     sanity check — then dies immediately after `License path:`. spc's
#     last step collects each library's licence out of
#     dist/static-php-cli/source/<lib>/, and the image ships NO source
#     trees at all (only buildroot). There is nothing to collect, and no
#     amount of extension juggling fixes that.
#
# So: full from-source build. It costs the 45 minutes the shortcut was
# trying to save, and in exchange the extension list can be exactly what
# the app needs instead of the 60+ the image was built with (no
# imagick/mongodb/amqp/parallel in a docs site's binary).
#
# --rebuild, NOT --with-clean: build-static.sh runs `spc download`
# (which extracts every source tree) and then `spc build`, and
# --with-clean deletes `source/` as well as `buildroot/` at the start of
# that second step — it throws away the php-src it is about to compile
# and dies on `Reading file source/php-src/main/main.c failed`.
# --rebuild drops only the prebuilt artifacts, which is exactly the part
# that has to go.
#
# PHP_EXTENSIONS is explicit rather than left to `spc dump-extensions`,
# which reads composer.json/lock `ext-*` constraints and would miss gd
# (the OG card route's imagettftext), session (relayer's ComponentState
# storage), opcache and pdo_sqlite (the local-SQLite doc store
# fallback), while dragging in sodium — a *suggest* of firebase/php-jwt
# for EdDSA on a site with no auth. Every entry is something the app
# reaches for; a missing one surfaces as a 500 at runtime rather than a
# build error, so keep this list and composer.json honest with each
# other.
#
# PHP_EXTENSION_LIBS ADDS to build-static.sh's defaults rather than
# replacing them: dropping nghttp2/nghttp3/ngtcp2 breaks the curl link,
# and watcher is FrankenPHP's file watcher. freetype/libjpeg/libwebp are
# gd's image libraries — freetype is the one that matters here, since
# App\Og\OgImage draws the card with imagettftext and the vendored
# assets/fonts/ipaexg.ttf. (brotli is appended by build-static.sh itself
# for caddy-cbrotli.)
#
# No BuildKit cache mount on dist/static-php-cli/downloads, deliberately.
# Caching the ~35 source tarballs is tempting (they are minutes of
# network on every build), but mounting that directory made spc die
# immediately after `License path:` at the very end of an otherwise
# successful build — after "Build complete" and the frankenphp sanity
# check, with no error message. The identical command run by hand in
# this same image, without the mount, exits 0 and produces the binary.
# The re-download is the cheaper problem; if this is revisited, verify
# against a full build rather than the log tail, which looks like a
# clean finish either way.
#
# PHP_EXTENSION_LIBS ADDS to build-static.sh's default list rather than
# replacing it — the variable is an override, and dropping the defaults
# breaks the build late and confusingly: libcurl is compiled with
# HTTP/2 support regardless, so without nghttp2 the final xcaddy link
# dies on `undefined reference to nghttp2_session_*` after ~17 minutes
# of compiling. So: upstream's libavif,nghttp2,nghttp3,ngtcp2,watcher
# verbatim, plus gd's image libraries. freetype is the one this app
# genuinely needs — App\Og\OgImage draws the card with imagettftext and
# the vendored assets/fonts/ipaexg.ttf. (brotli is appended by
# build-static.sh itself for caddy-cbrotli.)
# The github_token secret is OPTIONAL and read-only: spc resolves the
# latest release of ~35 sources through api.github.com, where anonymous
# callers get 60 requests an hour per IP. A single build plus a retry
# exhausts that, and the failure mode is a mid-download 403 rather than
# anything self-explanatory. With a token the ceiling is 5000/h. Build
# without it and spc simply falls back to anonymous requests.
#
#   GH_TOKEN="$(gh auth token)" docker build \
#     --secret id=github_token,env=GH_TOKEN \
#     -t relayer-doc-static -f static-build.Dockerfile .
#
# In GitHub Actions, pass the job's own ${{ github.token }} the same way.
RUN --mount=type=secret,id=github_token \
    GITHUB_TOKEN="$(cat /run/secrets/github_token 2>/dev/null || true)" \
    PHP_VERSION=8.5 \
    PHP_EXTENSIONS="ctype,curl,filter,gd,iconv,mbstring,opcache,openssl,pdo,pdo_sqlite,session,tokenizer,zlib" \
    PHP_EXTENSION_LIBS="libavif,nghttp2,nghttp3,ngtcp2,watcher,freetype,libjpeg,libwebp" \
    SPC_OPT_BUILD_ARGS="--rebuild" \
    EMBED=dist/app/ \
    ./build-static.sh

########################################################################
# Stage 3 — runtime. One binary, one CA bundle, one entrypoint.
########################################################################
FROM debian:bookworm-slim

# ca-certificates only: the binary carries PHP, the extensions, Caddy
# and the application. A statically linked OpenSSL has no trust store of
# its own, and docker/embed/php.ini points openssl.cafile/curl.cainfo at
# this bundle so the Turso API calls verify.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends ca-certificates; \
    rm -rf /var/lib/apt/lists/*

COPY --from=builder /go/src/app/dist/frankenphp-linux-x86_64 /usr/local/bin/relayer-doc
COPY docker/embed/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/relayer-doc /usr/local/bin/entrypoint.sh

ENV APP_ENV=production

# Same port as the classic image and fly.toml's internal_port; the
# address is set by the embedded Caddyfile, not by a flag.
EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
