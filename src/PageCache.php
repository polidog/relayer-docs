<?php

declare(strict_types=1);

namespace App;

use Polidog\Relayer\Http\Cache;

/**
 * Shared HTTP cache policy for the public read endpoints.
 *
 * Time-based: a short max-age (plus s-maxage for shared/edge caches)
 * means a cache hit never wakes PHP or hits the remote Turso DB. The
 * content-addressed ETag is kept so that once the TTL lapses the
 * revalidation is a cheap 304 instead of a full render. Edits made
 * out-of-band via `bin/docs` become visible after at most TTL seconds.
 *
 * One knob: raise TTL to cut load, lower it to publish edits sooner.
 */
final class PageCache
{
    /** Seconds a response stays fresh before it must be revalidated. */
    public const TTL = 300;

    public static function timed(string $etag): Cache
    {
        return new Cache(
            public: true,
            maxAge: self::TTL,
            sMaxAge: self::TTL,
            etag: $etag,
        );
    }
}
