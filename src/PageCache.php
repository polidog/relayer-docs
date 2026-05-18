<?php

declare(strict_types=1);

namespace App;

use Polidog\Relayer\Http\Cache;

/**
 * Shared HTTP cache policy for the public read endpoints.
 *
 * Time-based, with browser and CDN decoupled. A cache hit never wakes
 * PHP or hits the remote Turso DB. The content-addressed ETag is kept
 * so that once a window lapses the revalidation is a cheap 304 instead
 * of a full render.
 *
 * Two knobs:
 *  - TTL: how long a *browser* holds a response. Kept short because
 *    browser caches cannot be purged — an edit reaches a returning
 *    visitor after at most TTL seconds.
 *  - SHARED_TTL: how long a *shared/edge (CDN)* cache holds it. Set
 *    long to shed load and Turso round-trips; an edit made via
 *    `bin/docs` surfaces on the next natural expiry or immediately on
 *    a CDN purge.
 */
final class PageCache
{
    /** Seconds the browser keeps a response fresh before revalidating. */
    public const TTL = 300;

    /** Seconds a shared/edge (CDN) cache keeps it fresh — 30 days. */
    public const SHARED_TTL = 30 * 24 * 60 * 60;

    public static function timed(string $etag): Cache
    {
        return new Cache(
            public: true,
            maxAge: self::TTL,
            sMaxAge: self::SHARED_TTL,
            etag: $etag,
        );
    }
}
