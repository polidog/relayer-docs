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
// The related `Set-Cookie: PHPSESSID` problem — which made Cloudflare
// hard-bypass the edge cache on every HTML page and defeated
// App\PageCache::SHARED_TTL — is fixed upstream, not here: use-php
// >= 0.7.1 starts the session lazily, so the Session-typed
// ComponentState relayer creates for a stateless page no longer emits
// a cookie. We deliberately do NOT set `session.use_cookies=0` as an
// app-level workaround anymore: that would silently break any future
// page that does use auth / CSRF / useState. Keep relying on the
// framework fix (composer constraint pins relayer ^0.12.1, which
// requires use-php >= 0.7.1).
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
