<?php

declare(strict_types=1);

use App\PageCache;
use App\SiteUrl;
use Polidog\Relayer\Http\CachePolicy;
use Polidog\Relayer\Http\Response;

/**
 * GET /robots.txt — crawl rules + the absolute sitemap pointer
 * (search engines discover /sitemap.xml from here).
 *
 * Content pages are crawlable; `/api/` (JSON) and `/search` (thin,
 * effectively infinite query permutations) are disallowed so crawl
 * budget goes to the docs. `/og/` stays allowed — those images are
 * fine to index and blocking them can hurt social/image previews.
 *
 * The body only varies with the site's base URL, which is constant
 * on the canonical domain, so a static template ETag is enough.
 *
 * Declaration-free: this file only returns the handler map.
 */
return [
    'GET' => function (): Response {
        $cache = PageCache::timed('robots-v1');
        CachePolicy::emit($cache);
        if (CachePolicy::isNotModified($cache)) {
            CachePolicy::sendNotModified();

            exit;
        }

        $body = "User-agent: *\n"
            . "Allow: /\n"
            . "Disallow: /api/\n"
            . "Disallow: /search\n"
            . "\n"
            . 'Sitemap: ' . SiteUrl::abs('/sitemap.xml') . "\n";

        return Response::make($body, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    },
];
