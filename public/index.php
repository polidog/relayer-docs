<?php

declare(strict_types=1);

use App\AppConfigurator;
use Polidog\Relayer\Relayer;
use Polidog\Relayer\Router\Document\HtmlDocument;

require_once __DIR__ . '/../vendor/autoload.php';

// PHP's session module otherwise injects `Cache-Control: no-store,
// no-cache, must-revalidate` on session_start(), clobbering the
// per-page policy we set via $ctx->cache(). Disable that so the
// content-addressed ETag + max-age headers stand. (This is a public
// docs site; pages opt into caching explicitly.)
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

// Syntax highlighting via highlight.js (Play CDN, no build step). The
// Markdown renderer already emits `<pre><code class="language-xxx">`,
// which highlight.js targets directly. Code blocks are always dark
// (the doc page forces `prose-pre:bg-slate-900`), so a single dark
// theme is used and `.hljs` is made transparent so the existing `pre`
// keeps providing the background/padding — only token colors come from
// the theme. `dotenv`/`env` fences are aliased to `ini` (KEY=value).
$highlight = <<<'HTML'
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.11.1/styles/github-dark.min.css">
<style>
  .prose pre code.hljs { background: transparent; padding: 0; }
</style>
<script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.11.1/highlight.min.js"></script>
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

$document = HtmlDocument::create()
    ->setLang('ja')
    ->setTitle('Relayer ドキュメント')
    ->disableDefaultStyles()
    ->addHeadHtml($tailwind)
    ->addHeadHtml($highlight)
    ->addHeadHtml($nav);

// dirname() (not __DIR__ . '/..') so the project root is a clean
// absolute path. The PSX page/component caches are keyed off it; a
// `/public/..` segment would still resolve at the OS level but makes
// the precompile paths needlessly confusing.
$projectRoot = \dirname(__DIR__);

Relayer::boot($projectRoot, new AppConfigurator($projectRoot))
    ->setDocument($document)
    ->run();
