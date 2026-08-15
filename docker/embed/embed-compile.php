<?php

declare(strict_types=1);

use Polidog\Relayer\Scaffold\InitCommand;
use Polidog\UsePhp\Psx\CompileCommand;

/**
 * Build the precompiled artifacts inside the embedded app, at boot.
 *
 * The classic (non-embedded) image runs `usephp compile` and `relayer
 * routes:compile` at *build* time — it can, because the build path and
 * the serve path are the same `/var/www/html`. A single-binary build
 * cannot: the PSX cache names every compiled file `sha1(<absolute path
 * of the source>)` (see use-php's CompileCommand), and the absolute
 * path only exists once the binary has untarred itself into
 * `$TMPDIR/frankenphp_<md5 of app.tar>`. Baking the cache into the tar
 * would change the tar's checksum, which changes the extraction path,
 * which invalidates the cache that was just baked — a fixed point with
 * no solution short of pinning `frankenphp.EmbeddedAppPath` via Go
 * ldflags (static-php-cli hardcodes XCADDY_GO_BUILD_FLAGS, so there is
 * no injection point for that today).
 *
 * So compile once per process start instead, before the web server
 * boots. Measured on this app: ~70ms for the 7 .psx files and ~25ms
 * for the 8 routes — irrelevant next to a Fly cold start, and it buys
 * back exactly the same OPcache-able artifacts the classic image ships.
 *
 * Idempotent: rerunning overwrites. Restarts of an already-warm machine
 * re-extract nothing (untar skips existing files) and recompile into
 * the same directory.
 *
 * Invoked by docker/embed/entrypoint.sh as
 * `<binary> php-cli bin/embed-compile.php`.
 */

$root = \dirname(__DIR__);

// The relayer/use-php commands take the project root explicitly, but
// keep cwd aligned with it anyway so anything reading getcwd() (or a
// relative path inside a compiled artifact) agrees.
\chdir($root);

require $root . '/vendor/autoload.php';

// Same two source roots and the same single cache dir as the classic
// Dockerfile: PascalCase components land in manifest.php, lowercase
// page.psx/layout.psx are loaded by their sha1 path, and Relayer::boot
// pins <projectRoot>/var/cache/psx for both.
$status = (new CompileCommand())->run(
    [
        $root . '/src/Components',
        $root . '/src/Pages',
        '--cache=' . $root . '/var/cache/psx',
    ],
    $root,
);

if (0 !== $status) {
    \fwrite(\STDERR, "embed-compile: usephp compile failed\n");

    exit($status);
}

// Route-level counterpart: scans src/Pages once (filesystem only, no
// Turso) into var/cache/routes/routes.php so the runtime reads an
// OPcache'd snapshot instead of walking the tree per request. It also
// fails loudly on a route-group URL collision — here that is a failed
// container start rather than a failed deploy, so the machine never
// comes up serving a broken route table.
$status = InitCommand::run(['routes:compile'], null, $root);

if (0 !== $status) {
    \fwrite(\STDERR, "embed-compile: relayer routes:compile failed\n");

    exit($status);
}

exit(0);
