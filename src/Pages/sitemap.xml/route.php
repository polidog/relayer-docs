<?php

declare(strict_types=1);

use App\Docs\DocStore;
use App\PageCache;
use App\SiteUrl;
use Polidog\Relayer\Http\CachePolicy;
use Polidog\Relayer\Http\Response;

/**
 * XML sitemap: GET /sitemap.xml (referenced from /robots.txt).
 *
 * Lists the indexable content URLs only — home, the changelog, and
 * every doc page. The search page (`/search`), the JSON API and the
 * OG image route are deliberately excluded: thin/duplicate or
 * non-content, they would only waste crawl budget.
 *
 * `lastmod` is `documents.updated_at` (the date part, UTC). That
 * column is always present, so the sitemap does not depend on the
 * `document_revisions` table existing yet — robust even before
 * `bin/docs migrate` has run for the history feature.
 *
 * Same cache contract as the other corpus endpoints: a long edge
 * s-maxage plus an ETag, so it is regenerated only when its output
 * could differ and a post-expiry hit is a cheap 304. The ETag binds
 * both inputs to the body: `corpusTag` (which page changed) AND a
 * digest of `SiteUrl::base()` — every `<loc>` is absolute, so a
 * base-URL change (domain move / `SITE_URL`) must also bust it,
 * otherwise a conditional request could 304 to a stale host.
 *
 * Declaration-free: this file only returns the handler map.
 */
return [
    'GET' => function (DocStore $store): Response {
        $base = SiteUrl::base();
        $cache = PageCache::timed(
            'sitemap-' . \substr(\sha1($base), 0, 8) . '-' . $store->corpusTag(),
        );
        CachePolicy::emit($cache);
        if (CachePolicy::isNotModified($cache)) {
            CachePolicy::sendNotModified();

            exit;
        }

        // `YYYY-MM-DD` (UTC) prefix of a stored ISO-8601 timestamp.
        // Day-stable so the sitemap/cache doesn't churn on sub-day
        // precision; a malformed value yields no `lastmod`.
        $day = static fn (string $iso): string => \preg_match('/^\d{4}-\d{2}-\d{2}/', $iso, $m)
            ? $m[0]
            : '';

        $url = static function (
            string $loc,
            string $lastmod,
            string $changefreq,
            string $priority,
        ): string {
            $tag = '  <url><loc>'
                . \htmlspecialchars($loc, \ENT_XML1, 'UTF-8') . '</loc>';
            if ('' !== $lastmod) {
                $tag .= '<lastmod>' . $lastmod . '</lastmod>';
            }

            return $tag
                . '<changefreq>' . $changefreq . '</changefreq>'
                . '<priority>' . $priority . '</priority></url>';
        };

        $docs = $store->nav();

        $latest = '';
        foreach ($docs as $d) {
            if ($d->updatedAt > $latest) {
                $latest = $d->updatedAt;
            }
        }
        $corpusDate = $day($latest);

        $urls = [
            $url($base . '/', $corpusDate, 'weekly', '1.0'),
            $url($base . '/changelog', $corpusDate, 'weekly', '0.5'),
        ];
        foreach ($docs as $d) {
            $urls[] = $url(
                $base . '/docs/' . $d->slug,
                $day($d->updatedAt),
                'monthly',
                '0.8',
            );
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n"
            . \implode("\n", $urls) . "\n"
            . '</urlset>' . "\n";

        return Response::make($xml, 200, [
            'Content-Type' => 'application/xml; charset=utf-8',
        ]);
    },
];
