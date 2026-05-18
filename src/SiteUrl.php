<?php

declare(strict_types=1);

namespace App;

/**
 * The site's canonical absolute base URL (`scheme://host[:port]`).
 *
 * OGP needs absolute `og:url` / `og:image` — relative URLs are ignored
 * by crawlers. The public hostname is intentionally NOT hardcoded: the
 * app runs behind Fly + Cloudflare under a domain that lives only in
 * Fly/Cloudflare config, not the repo. So the base is derived from the
 * request, with an explicit `SITE_URL` env override for when a fixed
 * canonical is wanted (set it as a Fly secret to pin it).
 *
 * Behind Fly's proxy the app speaks plain HTTP on :8080 but the proxy
 * preserves the original `Host` and sets `X-Forwarded-Proto: https`
 * (fly.toml `force_https`), and Cloudflare does the same — so the
 * forwarded proto, then a production assumption, drives the scheme.
 *
 * `Host` is attacker-controlled, so it is format-validated before it
 * goes into a URL. A bogus `Host` only poisons the attacker's own
 * uncached response (Cloudflare keys the edge cache by URL on the
 * single canonical domain, not by `Host`), but it is rejected anyway.
 */
final class SiteUrl
{
    private const DEFAULT_HOST = '127.0.0.1:8000';

    /** `scheme://host`, no trailing slash. */
    public static function base(): string
    {
        $override = self::env('SITE_URL');
        if ('' !== $override) {
            return \rtrim($override, '/');
        }

        return self::scheme() . '://' . self::host();
    }

    /** Absolute URL for an app-absolute path (e.g. `/docs/x`). */
    public static function abs(string $path): string
    {
        return self::base() . '/' . \ltrim($path, '/');
    }

    private static function scheme(): string
    {
        $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        if (\is_string($proto) && '' !== $proto) {
            // May arrive as a comma list ("https,http"); the first hop wins.
            return \str_starts_with(\strtolower(\trim($proto)), 'https') ? 'https' : 'http';
        }

        if (($_SERVER['HTTPS'] ?? '') !== '' && 'off' !== ($_SERVER['HTTPS'] ?? 'off')) {
            return 'https';
        }

        // No proxy header and not directly TLS: HTTPS in production
        // (Fly force_https), HTTP for the local `php -S` dev server.
        return 'production' === ($_ENV['APP_ENV'] ?? \getenv('APP_ENV')) ? 'https' : 'http';
    }

    private static function host(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if (\is_string($host) && \preg_match('/^[A-Za-z0-9.\-]+(:\d{1,5})?$/', $host)) {
            return $host;
        }

        return self::DEFAULT_HOST;
    }

    private static function env(string $key): string
    {
        $v = $_ENV[$key] ?? $_SERVER[$key] ?? \getenv($key);

        return \is_string($v) ? \trim($v) : '';
    }
}
