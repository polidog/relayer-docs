<?php

declare(strict_types=1);

use App\AppConfigurator;
use App\DocumentFactory;
use Polidog\Relayer\Relayer;
use Polidog\UsePhp\Runtime\ComponentState;
use Polidog\UsePhp\Storage\StorageFactory;

/**
 * FrankenPHP worker-mode entry point.
 *
 * Classic mode (public/index.php, one script execution per request) is
 * still the default; this script is used only when the container is
 * started with RELAYER_WORKER=1, which makes docker/embed/entrypoint.sh
 * install docker/embed/Caddyfile.worker. Same binary either way, so
 * switching modes — or rolling back — is an env var and a restart, not
 * a rebuild.
 *
 * It deliberately lives OUTSIDE public/: a worker script under the
 * document root would be reachable as /worker.php and Caddy would
 * happily execute it as an ordinary request.
 *
 * What is hoisted out of the request loop, and what is not:
 *
 *   - Hoisted: the Composer autoloader (classes stay loaded in memory),
 *     the DI container, the router with its compiled route table and
 *     PSX manifest, and the Turso HTTP client — i.e. everything
 *     Relayer::boot() builds. That is the entire point of worker mode.
 *   - NOT hoisted: the HtmlDocument. It is mutable and the router
 *     writes to it per request, including addScript(), which appends
 *     and is never drained. See App\DocumentFactory.
 *
 * Known rough edge: relayer answers a conditional request with
 * `CachePolicy::sendNotModified(); exit;` (AppRouter::applyFunctionPageCache,
 * and the PRG path in dispatchStateAction does the same). Under a
 * worker, `exit` terminates the worker script and FrankenPHP restarts
 * it — so every 304 costs a worker reboot. It stays correct, but this
 * site sets an ETag plus max-age=300 on every page, so revalidations
 * are routine traffic rather than an edge case. That belongs upstream
 * in relayer (return/throw instead of exit when running under a
 * worker); until then it is the main reason to measure worker mode
 * here rather than assume it wins.
 */

require_once __DIR__ . '/vendor/autoload.php';

// Same reasoning as public/index.php — see the comment there.
\ini_set('session.cache_limiter', '');

$projectRoot = __DIR__;

// Booted once, reused by every request this worker handles.
$router = Relayer::boot($projectRoot, new AppConfigurator($projectRoot));

$handler = static function () use ($router): void {
    $router->setDocument(DocumentFactory::create())->run();
};

// FrankenPHP's own recycling knob: restart this worker after N requests
// to bound the damage from any leak the resets below miss. 0 (the
// default) means never.
$maxRequests = (int) (\getenv('MAX_REQUESTS') ?: 0);

for ($handled = 0; 0 === $maxRequests || $handled < $maxRequests; ++$handled) {
    $keepRunning = \frankenphp_handle_request($handler);

    // Per-request statics the framework does not clear itself. relayer
    // registers a shutdown function that resets RenderContext,
    // PageContext, Translators and the container's current request, but
    // use-php's component-state maps are keyed by component id and
    // survive: without this, a component's useState from one request
    // would be visible to the next request that renders the same
    // component. This site is stateless today, which is exactly why the
    // reset is cheap insurance rather than a visible fix.
    ComponentState::clearInstances();
    StorageFactory::reset();

    // The PSX render path builds deep object graphs with parent/child
    // references; collecting them here keeps steady-state RSS flat
    // instead of sawtoothing between requests.
    \gc_collect_cycles();

    if (!$keepRunning) {
        break;
    }
}
