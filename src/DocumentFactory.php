<?php

declare(strict_types=1);

namespace App;

use Polidog\Relayer\Router\Document\HtmlDocument;

/**
 * The site-wide {@see HtmlDocument} — everything that belongs in
 * `<head>` on every page, in one place.
 *
 * This exists as a factory rather than as inline setup in
 * `public/index.php` because there are now two entry points that need
 * the identical document: the classic front controller, and the
 * FrankenPHP worker script (`worker.php`). Duplicating the head markup
 * across them would guarantee they drift.
 *
 * A FRESH instance per request is mandatory, which is the other reason
 * this is a factory. `HtmlDocument` is mutable and the router writes to
 * it while dispatching: `setLang()` per resolved locale, `setMetadata()`
 * per page — and `addScript()`, which **appends** and is never drained
 * by `render()`. Under a long-running worker a shared document would
 * therefore accumulate every page's `$ctx->js()` declaration, so the
 * doc page's highlight.js would start appearing (and then repeating) on
 * the home page. Call this once per request and that whole class of
 * bug is structurally impossible.
 */
final class DocumentFactory
{
    public static function create(): HtmlDocument
    {
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

        // Open Graph / Twitter — only the page-invariant tags live here, once.
        // Everything that varies per page (og:title/description/url/image,
        // og:locale, twitter:title/description/image) is emitted via
        // `$ctx->metadata()` through App\Meta, so there are no duplicate tags.
        // og:locale is per-page now because the site is bilingual. The image
        // box mirrors App\Og\OgImage::WIDTH/HEIGHT — keep them in sync.
        $og = <<<'HTML'
            <meta property="og:type" content="website">
            <meta property="og:site_name" content="Relayer ドキュメント">
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

        $ga = self::googleAnalytics();

        return '' === $ga ? $document : $document->addHeadHtml($ga);
    }

    /**
     * Google Analytics 4 (gtag.js) — global `<head>`, like the snippets
     * above. The Measurement ID comes from GA_MEASUREMENT_ID (a Fly
     * secret); it is read from the real process env, before
     * `Relayer::boot()` loads any `.env`, so it is set in production only
     * and local/dev traffic never reaches the property. The value is
     * validated against the canonical `G-XXXX` shape before it is
     * interpolated, so a malformed or hostile env value cannot inject
     * markup. Cloudflare Rocket Loader is off, so the inline init runs
     * as written.
     *
     * Re-read on every call rather than cached in a static: under the
     * worker this class outlives the request, and a static would pin
     * whatever the environment looked like at boot.
     */
    private static function googleAnalytics(): string
    {
        $gaId = $_ENV['GA_MEASUREMENT_ID'] ?? $_SERVER['GA_MEASUREMENT_ID'] ?? \getenv('GA_MEASUREMENT_ID');
        $gaId = \is_string($gaId) ? \trim($gaId) : '';

        if ('' === $gaId || 1 !== \preg_match('/^G-[A-Z0-9]+$/', $gaId)) {
            return '';
        }

        return <<<HTML
            <script async src="https://www.googletagmanager.com/gtag/js?id={$gaId}"></script>
            <script>
              window.dataLayer = window.dataLayer || [];
              function gtag(){dataLayer.push(arguments);}
              gtag('js', new Date());
              gtag('config', '{$gaId}');
            </script>
            HTML;
    }
}
