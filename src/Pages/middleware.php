<?php

declare(strict_types=1);

use App\I18n;
use Polidog\Relayer\Http\Request;
use Polidog\Relayer\Http\Response;

/**
 * Locale cache-safety guard.
 *
 * Relayer resolves the request locale before this middleware (path
 * prefix → session → cookie → Accept-Language → default) and serves
 * that language's body inline. That is unsafe to edge-cache on an
 * UNPREFIXED URL: `/` and `/docs/x` carry `Cache-Control: s-maxage`
 * with no `Vary` and no locale in the CDN cache key, so the first
 * cache-fill in a non-default language (an `Accept-Language: en` bot,
 * a `locale` cookie) would be pinned and served to every visitor —
 * the same shared-key-vs-per-user trap that the Set-Cookie/CDN issue
 * was.
 *
 * Fix: keep every cacheable body deterministic *by URL*. The canonical
 * unprefixed URLs are always the default locale; a non-default locale
 * is only ever reached under its `/{locale}` path prefix. So when
 * Relayer resolved a non-default locale for a request whose URL had no
 * locale prefix (i.e. it negotiated via cookie / Accept-Language /
 * session), we do NOT serve that body — we 302 to the prefixed URL,
 * with `no-store` so the negotiated redirect itself is never cached.
 * `/en/...` and default-locale `/...` both pass straight through and
 * stay cacheable, one language per URL.
 *
 * Scope: GET HTML pages only. Single-locale deployments (no
 * APP_LOCALES) and the crawler/data endpoints (sitemap, robots, api,
 * og) are left exactly as they were.
 */
return function (Request $request, Closure $next): void {
    $locale = $request->locale();

    // Nothing to make safe unless the site actually publishes >1
    // locale and Relayer resolved a non-default one.
    if (
        'GET' !== $request->method
        || !I18n::bilingual()
        || null === $locale
        || I18n::DEFAULT === $locale
    ) {
        $next($request);

        return;
    }

    // The ORIGINAL url (Relayer has already stripped any `/{locale}`
    // prefix off $request->path). If it carried the prefix, the body
    // is unambiguous for that URL and safe to cache — pass through.
    $uri = \is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
    $origPath = \parse_url($uri, \PHP_URL_PATH);
    $origPath = \is_string($origPath) && '' !== $origPath ? $origPath : '/';

    $first = \explode('/', \ltrim($origPath, '/'), 2)[0];
    $alreadyPrefixed = \in_array($first, I18n::locales(), true) && I18n::DEFAULT !== $first;

    // Endpoints that must stay canonical/unprefixed regardless of the
    // visitor's language signals (crawlers, API clients, the OG card).
    $exempt = \str_starts_with($origPath, '/api/')
        || \str_starts_with($origPath, '/og/')
        || '/sitemap.xml' === $origPath
        || '/robots.txt' === $origPath;

    if ($alreadyPrefixed || $exempt) {
        $next($request);

        return;
    }

    // Unprefixed URL that negotiated a non-default locale: redirect to
    // the prefixed, cacheable URL instead of serving a varied body.
    $query = \parse_url($uri, \PHP_URL_QUERY);
    $target = I18n::path($locale, $origPath)
        . (\is_string($query) && '' !== $query ? '?' . $query : '');

    Response::redirect($target, 302)
        ->withHeader('Cache-Control', 'private, no-store')
        ->withHeader('Vary', 'Accept-Language, Cookie')
        ->send();
};
