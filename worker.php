<?php

declare(strict_types=1);

use App\AppConfigurator;
use App\DocumentFactory;
use Polidog\Relayer\Relayer;

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

    // Drop the request-scoped statics relayer and Rosanna keep (the
    // render scope, PageContext, translators, anything registered with
    // RequestLifecycle::onReset()) so nothing leaks into the next request.
    Relayer::endRequest();

    // The PSX render path builds deep object graphs with parent/child
    // references; collecting them here keeps steady-state RSS flat
    // instead of sawtoothing between requests.
    \gc_collect_cycles();

    if (!$keepRunning) {
        break;
    }
}
