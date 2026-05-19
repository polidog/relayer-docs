<?php

declare(strict_types=1);

namespace App;

/**
 * Locale helpers for the doc site's own UI — the thin layer on top of
 * Relayer's request-side i18n.
 *
 * `ja` is the canonical locale: its URLs are unprefixed and every page
 * is served byte-identically to the pre-i18n site (the doc store reads
 * the canonical `documents` rows, the cache tags are unchanged). A
 * non-default locale lives behind a `/{locale}` path prefix that
 * Relayer strips before route matching, so the same `.psx` page serves
 * every locale; the app only has to *generate* locale-correct links and
 * localize its own chrome (category headings, the language switcher).
 *
 * The framework resolves the request locale (path prefix → session →
 * cookie → Accept-Language → default) and exposes it as
 * `Request::locale()`; this class never re-derives it, it only maps a
 * resolved locale to URLs and labels.
 */
final class I18n
{
    /** Canonical locale: unprefixed, byte-identical to the old site. */
    public const DEFAULT = 'ja';

    /** Locales the framework actually understands as values. */
    private const KNOWN = ['ja', 'en'];

    /**
     * Locales the site actually publishes, from `APP_LOCALES` — the
     * SAME signal Relayer uses to decide whether to bind locale
     * switching, so the app's chrome (switcher, sitemap) can never
     * disagree with what the framework will route. Production sets
     * this in fly.toml (the repo `.env` is .dockerignore'd and only
     * configures local dev); unset/single ⇒ just the canonical locale,
     * and the site behaves byte-identically to the pre-i18n version.
     *
     * @return list<string>
     */
    public static function locales(): array
    {
        $raw = $_ENV['APP_LOCALES'] ?? $_SERVER['APP_LOCALES'] ?? \getenv('APP_LOCALES');
        $out = [];
        foreach (\is_string($raw) ? \explode(',', $raw) : [] as $code) {
            $code = \trim($code);
            if (\in_array($code, self::KNOWN, true) && !\in_array($code, $out, true)) {
                $out[] = $code;
            }
        }

        return [] === $out ? [self::DEFAULT] : $out;
    }

    /** True only when more than one locale is actually published. */
    public static function bilingual(): bool
    {
        return \count(self::locales()) > 1;
    }

    /** Category-heading labels per locale, keyed by the canonical (ja) name. */
    private const CATEGORY = [
        'en' => [
            'はじめに' => 'Getting Started',
            'ルーティング' => 'Routing',
            '機能' => 'Features',
            '開発' => 'Development',
            '運用' => 'Operations',
            'ドキュメント' => 'Documentation',
        ],
    ];

    /**
     * A resolved/served locale narrowed to a supported one. Relayer
     * returns `null` when i18n is unconfigured, and any unexpected
     * value degrades to the canonical locale rather than 404-ing.
     */
    public static function normalize(?string $locale): string
    {
        return null !== $locale && \in_array($locale, self::locales(), true)
            ? $locale
            : self::DEFAULT;
    }

    /**
     * An app-absolute path rewritten for $locale. The canonical locale
     * is unprefixed (so its URLs never moved); a non-default locale
     * gets the `/{locale}` prefix Relayer strips back off on the way in.
     */
    public static function path(string $locale, string $path): string
    {
        $path = '/' . \ltrim($path, '/');
        if (self::DEFAULT === $locale || !\in_array($locale, self::locales(), true)) {
            return $path;
        }

        return '/' === $path ? '/' . $locale . '/' : '/' . $locale . $path;
    }

    /**
     * The same logical path in the other locale — drives the language
     * switcher. Strips a leading supported-locale segment, then
     * re-prefixes for $target.
     */
    public static function switchTo(string $target, string $currentPath): string
    {
        $path = '/' . \ltrim($currentPath, '/');
        foreach (self::locales() as $loc) {
            if (self::DEFAULT === $loc) {
                continue;
            }
            if ('/' . $loc === $path || \str_starts_with($path, '/' . $loc . '/')) {
                $path = \substr($path, \strlen('/' . $loc));
                $path = '' === $path ? '/' : $path;

                break;
            }
        }

        return self::path($target, $path);
    }

    /** A category heading localized for $locale (canonical name as fallback). */
    public static function category(string $locale, string $jaCategory): string
    {
        return self::CATEGORY[$locale][$jaCategory] ?? $jaCategory;
    }

    /** BCP-47-ish locale for `og:locale` (`ja` → `ja_JP`, `en` → `en_US`). */
    public static function ogLocale(string $locale): string
    {
        return 'en' === $locale ? 'en_US' : 'ja_JP';
    }
}
