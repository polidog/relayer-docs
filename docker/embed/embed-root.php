<?php

declare(strict_types=1);

/**
 * Print the directory the embedded app was extracted to.
 *
 * A FrankenPHP binary with an app embedded in it untars that app at
 * process start into `$TMPDIR/frankenphp_<md5 of app.tar>` (see
 * frankenphp's embed.go). The checksum is only known once the tar
 * exists, so the path cannot be baked into the image at build time —
 * but every process started from the SAME binary lands on the SAME
 * path, so it is stable for the life of a deploy and can simply be
 * asked for at runtime.
 *
 * `frankenphp php-cli <relative>.php` resolves the script against the
 * extraction directory, so `dirname(__DIR__)` here IS the app root.
 * docker/embed/entrypoint.sh captures this on stdout and exports it as
 * APP_ROOT for the embedded Caddyfile (`root {$APP_ROOT}/public`).
 *
 * Keep this script free of any other output — the entrypoint reads the
 * whole of stdout as the path.
 */

echo \dirname(__DIR__), "\n";
