<?php

declare(strict_types=1);

use App\AppConfigurator;
use App\DocumentFactory;
use Polidog\Relayer\Relayer;

require_once __DIR__ . '/../vendor/autoload.php';

// If a PHP session is ever started, PHP's session module injects
// `Cache-Control: no-store, no-cache, must-revalidate`, which would
// clobber the per-page policy set via $ctx->cache(). Empty the
// cache_limiter so the App\PageCache ETag + max-age/s-maxage headers
// stand. (Public docs site; pages opt into caching explicitly.)
//
// Components hold no state, so rendering a page never starts a session
// and never emits `Set-Cookie: PHPSESSID` (which would make Cloudflare
// bypass the edge cache). We deliberately do NOT set
// `session.use_cookies=0`: that would silently break any future page
// that uses auth / CSRF / server actions.
\ini_set('session.cache_limiter', '');

// dirname() (not __DIR__ . '/..') so the project root is a clean
// absolute path. The PSX page/component caches are keyed off it; a
// `/public/..` segment would still resolve at the OS level but makes
// the precompile paths needlessly confusing.
$projectRoot = \dirname(__DIR__);

// The `<head>` lives in App\DocumentFactory — shared verbatim with
// worker.php, the FrankenPHP worker-mode entry point, so the two
// cannot drift. See that class for why the document must be built per
// request rather than once.
Relayer::boot($projectRoot, new AppConfigurator($projectRoot))
    ->setDocument(DocumentFactory::create())
    ->run();
