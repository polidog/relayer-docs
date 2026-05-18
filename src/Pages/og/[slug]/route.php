<?php

declare(strict_types=1);

use App\Docs\DocStore;
use App\Og\OgImage;
use App\PageCache;
use Polidog\Relayer\Http\CachePolicy;
use Polidog\Relayer\Http\Response;
use Polidog\Relayer\Router\Component\PageContext;

/**
 * Dynamic OG card: GET /og/<slug> → image/png (1200×630).
 *
 * One card per doc (its title + category) plus the `home` card for the
 * landing/search/404 pages. Referenced as an absolute `og:image` by
 * App\Meta. `.png` is accepted but optional — crawlers key off the
 * Content-Type, not the extension.
 *
 * Same cache contract as the HTML pages (App\PageCache): a long edge
 * s-maxage plus a content-hash ETag, so the GD render almost never
 * runs — Cloudflare serves it, and a post-expiry revalidation is a
 * cheap 304 here before any pixels are drawn. The ETag binds the doc
 * hash, so an edit via `bin/docs` rolls the card too; `og-v1` is the
 * template version — bump it when OgImage's layout changes.
 *
 * Declaration-free: this file only returns the handler map.
 */
return [
    'GET' => function (PageContext $ctx, DocStore $store): Response {
        $slug = (string) ($ctx->params['slug'] ?? '');
        if (\str_ends_with($slug, '.png')) {
            $slug = \substr($slug, 0, -4);
        }

        if ('' === $slug || 'home' === $slug) {
            $title = 'Relayer ドキュメント';
            $eyebrow = 'PHP フルスタックフレームワーク';
            $tag = 'home';
        } else {
            $doc = $store->get($slug);
            if (null === $doc) {
                return Response::text('Not Found', 404, ['Cache-Control' => 'no-store']);
            }
            $title = $doc->title;
            $eyebrow = '' !== $doc->category ? $doc->category : 'ドキュメント';
            $tag = $doc->hash;
        }

        $cache = PageCache::timed('og-v1-' . $slug . '-' . $tag);
        CachePolicy::emit($cache);
        if (CachePolicy::isNotModified($cache)) {
            CachePolicy::sendNotModified();

            exit;
        }

        return Response::make(
            OgImage::render($title, $eyebrow),
            200,
            ['Content-Type' => 'image/png'],
        );
    },
];
