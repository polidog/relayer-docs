#!/bin/sh
# Container entrypoint for the single-binary (embedded) build.
#
# The binary carries the whole app inside it and untars itself into
# EmbeddedAppPath (pinned to /var/www/html at build time) on every
# process start. Three things happen before Caddy accepts traffic:
#
#   1. APP_ROOT — the embedded Caddyfile needs an absolute document
#      root. bin/embed-root.php reports where the app actually landed
#      rather than trusting the pin, so a future change to the build
#      cannot silently point Caddy at nothing.
#   2. Server config — classic mode by default, worker mode when
#      RELAYER_WORKER=1. Both configs are embedded; the selected one is
#      installed as `Caddyfile`, which is the only name `php-server`
#      looks for. Copying on every boot (rather than only when
#      switching on) means flipping the variable back actually reverts.
#   3. The PSX/route caches — see docker/embed/embed-compile.php for
#      why they cannot be baked at build time.
#
# All of it runs through `php-cli` on the SAME binary, so it compiles
# into exactly the directory `php-server` will serve from.
set -eu

BIN=/usr/local/bin/relayer-doc

APP_ROOT="$("$BIN" php-cli bin/embed-root.php)"
export APP_ROOT

if [ "${RELAYER_WORKER:-0}" = "1" ]; then
    cp "${APP_ROOT}/Caddyfile.worker" "${APP_ROOT}/Caddyfile"
    echo "entrypoint: worker mode (num=${RELAYER_WORKER_NUM:-2})" >&2
else
    cp "${APP_ROOT}/Caddyfile.classic" "${APP_ROOT}/Caddyfile"
    echo "entrypoint: classic mode" >&2
fi

"$BIN" php-cli bin/embed-compile.php

# exec: PID 1 stays the server, so Fly's stop/start signals reach Caddy
# directly. The embedded Caddyfile wins over every flag — php-server
# loads it and returns — so listen address, document root and worker
# config all live in that file, not here.
exec "$BIN" php-server
