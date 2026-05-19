<?php

declare(strict_types=1);

use App\Docs\DocStore;
use App\Docs\SearchHit;
use App\I18n;
use App\PageCache;
use Polidog\Relayer\Http\CachePolicy;
use Polidog\Relayer\Http\Request;
use Polidog\Relayer\Http\Response;

/**
 * JSON search endpoint: GET /api/search?q=...&limit=...
 *
 * Same store and trigram query as the HTML search page — handy for
 * scripts, integrations, or a future client-side instant search.
 * Declaration-free: this file only returns the handler map.
 */
return [
    'GET' => function (Request $req, DocStore $store): Response {
        $locale = I18n::normalize($req->locale());
        $query = \trim((string) ($req->query('q') ?? ''));
        $limit = (int) ($req->query('limit') ?? 20);

        // Same time-based policy as the pages (PageCache::TTL). The
        // ETag binds query + corpus digest, so a post-expiry
        // revalidation short-circuits with 304 before the search runs
        // (mirrors the framework's function-page cache path). The ja
        // sha input is byte-identical to the pre-i18n key (corpusTag
        // ('ja') is unchanged) so the canonical endpoint's ETag never
        // moved; only a non-default locale folds its code in.
        $digest = I18n::DEFAULT === $locale
            ? \sha1($query . '|' . $store->corpusTag($locale))
            : \sha1($locale . '|' . $query . '|' . $store->corpusTag($locale));
        $cache = PageCache::timed('api-' . $digest);
        CachePolicy::emit($cache);
        if (CachePolicy::isNotModified($cache)) {
            CachePolicy::sendNotModified();

            exit;
        }

        $hits = '' !== $query ? $store->search($query, $limit, $locale) : [];

        return Response::json([
            'query' => $query,
            'locale' => $locale,
            'count' => \count($hits),
            'results' => \array_map(
                static fn (SearchHit $h): array => [
                    'slug' => $h->slug,
                    'title' => $h->title,
                    'description' => $h->description,
                    'category' => $h->category,
                    'url' => I18n::path($locale, '/docs/' . $h->slug),
                    'snippet' => \implode('', \array_map(
                        static fn (array $s): string => $s['text'],
                        $h->segments,
                    )),
                ],
                $hits,
            ),
        ]);
    },
];
