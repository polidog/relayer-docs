<?php

declare(strict_types=1);

use App\AppConfigurator;
use Polidog\Relayer\Relayer;
use Polidog\Relayer\Router\Document\HtmlDocument;

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

// Tailwind via the Play CDN (+ typography plugin) — no Node/build step,
// honoring Relayer's "no build" rule while still being Tailwind-based.
// Class-strategy dark mode with a no-FOUC init that runs before paint
// and a delegated toggle handler for the header button.
$tailwind = <<<'HTML'
<script src="https://cdn.tailwindcss.com?plugins=typography"></script>
<script>
  tailwind.config = {
    darkMode: 'class',
    theme: { extend: { fontFamily: { sans: ['-apple-system','BlinkMacSystemFont','Segoe UI','Hiragino Sans','Noto Sans JP','Yu Gothic UI','Meiryo','Roboto','sans-serif'] } } }
  };
</script>
<script>
  (function () {
    try {
      var t = localStorage.getItem('theme');
      if (t === 'dark' || (!t && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        document.documentElement.classList.add('dark');
      }
    } catch (e) {}
    document.addEventListener('click', function (e) {
      var b = e.target.closest && e.target.closest('#theme-toggle');
      if (!b) return;
      var r = document.documentElement;
      r.classList.toggle('dark');
      try { localStorage.setItem('theme', r.classList.contains('dark') ? 'dark' : 'light'); } catch (e) {}
    });
  })();
</script>
HTML;

// Syntax highlighting theme + init for highlight.js. The library itself
// (`highlight.min.js`) is NOT loaded here: only the doc page has
// `<pre><code>` blocks, so it declares the lib per-page via
// `$ctx->js()` (relayer 0.6.0+), emitted at end of <body> on doc pages
// only instead of globally on every route.
//
// What stays global (head): the theme CSS + a one-line `.hljs` override
// + the init script. `$ctx->js()` is src-only by design, so the inline
// init lives here; it's guarded by `if (!window.hljs)` so it's an inert
// no-op on routes that never load the library (home, search, 404). The
// Markdown renderer emits `<pre><code class="language-xxx">`, which
// highlight.js targets directly. Code blocks are always dark (the doc
// page forces `prose-pre:bg-slate-900`), so a single dark theme is used
// and `.hljs` is made transparent so the existing `pre` keeps providing
// the background/padding. `dotenv`/`env` fences alias to `ini`.
$highlight = <<<'HTML'
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.11.1/styles/github-dark.min.css">
<style>
  .prose pre code.hljs { background: transparent; padding: 0; }
</style>
<script>
  (function () {
    function run() {
      if (!window.hljs) return;
      hljs.registerAliases(['dotenv', 'env'], { languageName: 'ini' });
      hljs.configure({ ignoreUnescapedHTML: true });
      document.querySelectorAll('pre code:not(.hljs)').forEach(function (el) {
        hljs.highlightElement(el);
      });
    }
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', run);
    } else {
      run();
    }
    // Re-highlight content brought in by usePHP partial updates.
    window.addEventListener('pageshow', run);
  })();
</script>
HTML;

// Mobile nav: the sidebar is `hidden md:block`, so the hamburger
// (#nav-toggle, md:hidden) toggles the `hidden` class on #sidebar.
// Tapping a sidebar link closes it again on phones. Delegated so it
// works regardless of component render order (same pattern as the
// theme toggle).
$nav = <<<'HTML'
<script>
  (function () {
    document.addEventListener('click', function (e) {
      if (!e.target.closest) return;
      if (e.target.closest('#nav-toggle')) {
        var s = document.getElementById('sidebar');
        if (s) s.classList.toggle('hidden');
        return;
      }
      if (e.target.closest('#sidebar a') &&
          window.matchMedia('(max-width: 767px)').matches) {
        var sb = document.getElementById('sidebar');
        if (sb) sb.classList.add('hidden');
      }
    });
  })();
</script>
HTML;

// Google Analytics 4 (gtag.js) — global <head>, like the snippets
// above. The Measurement ID comes from GA_MEASUREMENT_ID (a Fly
// secret); it is read here from the real process env, before
// Relayer::boot() loads any `.env`, so it is set in production only
// and local/dev traffic never reaches the property. The value is
// validated against the canonical `G-XXXX` shape before it is
// interpolated, so a malformed or hostile env value cannot inject
// markup. Cloudflare Rocket Loader is off, so the inline init runs
// as written.
$gaId = $_ENV['GA_MEASUREMENT_ID'] ?? $_SERVER['GA_MEASUREMENT_ID'] ?? \getenv('GA_MEASUREMENT_ID');
$gaId = \is_string($gaId) ? \trim($gaId) : '';
$ga = '';
if ('' !== $gaId && \preg_match('/^G-[A-Z0-9]+$/', $gaId)) {
    $ga = <<<HTML
    <script async src="https://www.googletagmanager.com/gtag/js?id={$gaId}"></script>
    <script>
      window.dataLayer = window.dataLayer || [];
      function gtag(){dataLayer.push(arguments);}
      gtag('js', new Date());
      gtag('config', '{$gaId}');
    </script>
    HTML;
}

// Open Graph / Twitter — only the page-invariant tags live here, once.
// Everything that varies per page (og:title/description/url/image,
// twitter:title/description/image) is emitted via `$ctx->metadata()`
// through App\Meta, so there are no duplicate tags. The image box
// mirrors App\Og\OgImage::WIDTH/HEIGHT — keep them in sync.
$og = <<<'HTML'
<meta property="og:type" content="website">
<meta property="og:site_name" content="Relayer ドキュメント">
<meta property="og:locale" content="ja_JP">
<meta property="og:image:type" content="image/png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="Relayer ドキュメント">
<meta name="twitter:card" content="summary_large_image">
HTML;

$document = HtmlDocument::create()
    ->setLang('ja')
    ->setTitle('Relayer ドキュメント')
    ->disableDefaultStyles()
    ->addHeadHtml($tailwind)
    ->addHeadHtml($highlight)
    ->addHeadHtml($nav)
    ->addHeadHtml($og);

if ('' !== $ga) {
    $document = $document->addHeadHtml($ga);
}

// dirname() (not __DIR__ . '/..') so the project root is a clean
// absolute path. The PSX page/component caches are keyed off it; a
// `/public/..` segment would still resolve at the OS level but makes
// the precompile paths needlessly confusing.
$projectRoot = \dirname(__DIR__);

Relayer::boot($projectRoot, new AppConfigurator($projectRoot))
    ->setDocument($document)
    ->run();
