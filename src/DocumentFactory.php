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
        // Typefaces for the blueprint direction: IBM Plex Sans Condensed for
        // display (drafting-sheet headlines), IBM Plex Sans for body, IBM Plex
        // Mono for the labels/eyebrows/figure captions that carry the
        // technical-drawing voice. Latin only — Japanese falls back to the
        // system gothic, which keeps the payload small (a JP webfont is
        // megabytes) and mixes cleanly with Plex.
        $fonts = <<<'HTML'
            <link rel="preconnect" href="https://fonts.googleapis.com">
            <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
            <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Sans+Condensed:wght@600;700&display=swap">
            HTML;

        // Tailwind via the Play CDN (+ typography plugin) — no Node/build step,
        // honoring Relayer's "no build" rule while still being Tailwind-based.
        // The palette is NOT hard-coded per utility: every color resolves to a
        // CSS custom property (`--bp-*`, defined below), so the green direction
        // stays centralized. The vars hold `R G B` triplets so Tailwind's
        // `<alpha-value>` slot still works (`bg-draft/10`).
        $tailwind = <<<'HTML'
            <script src="https://cdn.tailwindcss.com?plugins=typography"></script>
            <script>
              tailwind.config = {
                theme: {
                  extend: {
                    colors: {
                      paper: 'rgb(var(--bp-paper) / <alpha-value>)',
                      sheet: 'rgb(var(--bp-sheet) / <alpha-value>)',
                      ink:   'rgb(var(--bp-ink) / <alpha-value>)',
                      muted: 'rgb(var(--bp-muted) / <alpha-value>)',
                      rule:  'rgb(var(--bp-rule) / <alpha-value>)',
                      draft: 'rgb(var(--bp-draft) / <alpha-value>)',
                      mark:  'rgb(var(--bp-mark) / <alpha-value>)',
                      plate: 'rgb(var(--bp-plate) / <alpha-value>)'
                    },
                    fontFamily: {
                      sans: ['IBM Plex Sans','Hiragino Sans','Noto Sans JP','Yu Gothic UI','Meiryo','sans-serif'],
                      display: ['IBM Plex Sans Condensed','IBM Plex Sans','Hiragino Sans','Noto Sans JP','sans-serif'],
                      mono: ['IBM Plex Mono','ui-monospace','SFMono-Regular','Menlo','monospace']
                    }
                  }
                }
              };
            </script>
            HTML;

        // The design system itself: a botanical drafting-sheet direction.
        // Everything that isn't a
        // Tailwind utility lives here — the graph-paper ground, the corner
        // registration marks, the dotted TOC leaders, the lifecycle-diagram
        // motion, and the typography-plugin variables.
        $blueprint = <<<'HTML'
            <style>
              :root {
                --bp-paper: 243 247 241;
                --bp-sheet: 255 255 255;
                --bp-ink: 18 42 31;
                --bp-muted: 86 113 96;
                --bp-rule: 199 218 204;
                --bp-draft: 24 128 78;
                --bp-mark: 200 63 33;
                --bp-plate: 9 34 25;
                --bp-dot: rgba(35, 125, 80, .17);
                color-scheme: light;
              }
              body {
                background-color: rgb(var(--bp-paper));
                background-image: radial-gradient(var(--bp-dot) 1px, transparent 1px);
                background-size: 24px 24px;
                background-position: -1px -1px;
              }
              ::selection { background: rgb(var(--bp-draft) / .22); }
              :focus-visible { outline: 2px solid rgb(var(--bp-draft)); outline-offset: 2px; }

              /* Registration marks: two opposite corner brackets on a panel,
                 the way a drawing sheet is cropped. Two, not four — one
                 accessory removed. */
              .bp-sheet { position: relative; }
              .bp-sheet::before, .bp-sheet::after {
                content: ''; position: absolute; width: 9px; height: 9px;
                border: 0 solid rgb(var(--bp-draft)); pointer-events: none;
              }
              .bp-sheet::before { top: -1px; left: -1px; border-left-width: 2px; border-top-width: 2px; }
              .bp-sheet::after { bottom: -1px; right: -1px; border-right-width: 2px; border-bottom-width: 2px; }

              /* Table-of-contents leader: title ....... slug */
              .bp-leader {
                flex: 1 1 auto; min-width: 1.25rem; align-self: center;
                border-bottom: 1px dotted rgb(var(--bp-rule));
              }

              /* FIG.01 — the signal travelling down the request rail. */
              @keyframes bp-signal {
                0%   { top: 0;    opacity: 0 }
                8%   { opacity: 1 }
                92%  { opacity: 1 }
                100% { top: 100%; opacity: 0 }
              }
              .bp-signal { animation: bp-signal 4s cubic-bezier(.45,.05,.55,.95) infinite; }
              @media (prefers-reduced-motion: reduce) {
                .bp-signal { animation: none; top: 50%; opacity: .7 }
                * { scroll-behavior: auto !important }
              }

              /* Long-form body copy, tuned to the same palette. The plugin is
                 driven entirely through its own custom properties so there is
                 is deliberate: the Play CDN injects the plugin's own `.prose`
                 rule (with its default gray `--tw-prose-*` values) after this
                 stylesheet, and at equal specificity the later rule would win.
                 Repeating the class only raises specificity; `.prose.prose`
                 still matches an element whose class list contains `prose`
                 once, so the markup stays a plain `class="prose"`. */
              .prose.prose {
                --tw-prose-body: rgb(var(--bp-ink));
                --tw-prose-headings: rgb(var(--bp-ink));
                --tw-prose-lead: rgb(var(--bp-muted));
                --tw-prose-links: rgb(var(--bp-draft));
                --tw-prose-bold: rgb(var(--bp-ink));
                --tw-prose-counters: rgb(var(--bp-muted));
                --tw-prose-bullets: rgb(var(--bp-rule));
                --tw-prose-hr: rgb(var(--bp-rule));
                --tw-prose-quotes: rgb(var(--bp-ink));
                --tw-prose-quote-borders: rgb(var(--bp-draft));
                --tw-prose-captions: rgb(var(--bp-muted));
                --tw-prose-code: rgb(var(--bp-ink));
                --tw-prose-pre-code: #d8f0e4;
                --tw-prose-pre-bg: rgb(var(--bp-plate));
                --tw-prose-th-borders: rgb(var(--bp-rule));
                --tw-prose-td-borders: rgb(var(--bp-rule));
              }
              .prose :is(h1, h2, h3, h4) {
                font-family: 'IBM Plex Sans Condensed', 'IBM Plex Sans', 'Hiragino Sans', 'Noto Sans JP', sans-serif;
                letter-spacing: -.012em;
              }
              .prose h2 {
                margin-top: 2.4em; padding-top: .8em;
                border-top: 1px solid rgb(var(--bp-rule));
              }
              .prose a { text-underline-offset: 3px; text-decoration-thickness: 1px; }
              .prose code::before, .prose code::after { content: none; }
              .prose :not(pre) > code {
                font-weight: 500; padding: .1em .35em;
                background: rgb(var(--bp-draft) / .09);
                border: 1px solid rgb(var(--bp-draft) / .2);
              }
              .prose pre {
                border-radius: 0; border: 1px solid rgb(var(--bp-rule));
              }
              .prose :is(img, blockquote, table, figure) { border-radius: 0; }
              .prose blockquote { font-style: normal; border-left-width: 2px; }
              .prose thead th { font-family: 'IBM Plex Mono', ui-monospace, monospace; font-size: .82em; letter-spacing: .04em; text-transform: uppercase; }
            </style>
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
        // highlight.js targets directly. Code plates are always dark (the
        // `--tw-prose-pre-bg` above is the same ink in both themes), so a
        // single dark theme is used and `.hljs` is made transparent so the
        // existing `pre` keeps providing the background/padding.
        // `dotenv`/`env` fences alias to `ini`.
        $highlight = <<<'HTML'
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.11.1/styles/github-dark.min.css">
            <style>
              pre code.hljs { background: transparent; padding: 0; }
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
        // works regardless of component render order.
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
            <link rel="icon" href="/favicon.svg" type="image/svg+xml">
            <meta name="theme-color" content="#18804e">
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
            ->addHeadHtml($fonts)
            ->addHeadHtml($tailwind)
            ->addHeadHtml($blueprint)
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
