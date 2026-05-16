<?php

declare(strict_types=1);

use App\Docs\DocStore;
use App\Docs\SearchHit;
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
        $query = \trim((string) ($req->query('q') ?? ''));
        $limit = (int) ($req->query('limit') ?? 20);

        // Same time-based policy as the pages (PageCache::TTL). The
        // ETag binds query + corpus digest, so a post-expiry
        // revalidation short-circuits with 304 before the search runs
        // (mirrors the framework's function-page cache path).
        $cache = PageCache::timed('api-' . \sha1($query . '|' . $store->corpusTag()));
        CachePolicy::emit($cache);
        if (CachePolicy::isNotModified($cache)) {
            CachePolicy::sendNotModified();

            exit;
        }

        $hits = '' !== $query ? $store->search($query, $limit) : [];

        return Response::json([
            'query' => $query,
            'count' => \count($hits),
            'results' => \array_map(
                static fn (SearchHit $h): array => [
                    'slug' => $h->slug,
                    'title' => $h->title,
                    'description' => $h->description,
                    'category' => $h->category,
                    'url' => '/docs/' . $h->slug,
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
