<?php

declare(strict_types=1);

namespace App;

use App\Docs\DocRecord;
use App\I18n;

/**
 * Builds the per-page metadata map passed to `$ctx->metadata()`.
 *
 * Relayer's HtmlDocument turns the map into `<head>` tags: `description`
 * → `<meta name>`, `og:*` → `<meta property>`, anything else →
 * `<meta name>` (so `twitter:*` lands as the `name=` form Twitter
 * expects). The invariant tags that never vary per page
 * (`og:type`, `og:site_name`, `twitter:card`, `og:image:width/height`)
 * are emitted once in public/index.php; this only carries what differs,
 * so there are no duplicate tags.
 *
 * `og:url` / `og:image` are absolute via {@see SiteUrl}; `og:image`
 * points at the dynamic card route (src/Pages/og/[slug]/route.php).
 */
final class Meta
{
    /** Title suffix; the home title is used verbatim (no suffix). */
    private const SUFFIX = ' — Relayer Docs';

    /**
     * @return array<string, string> for `$ctx->metadata()`
     */
    public static function forDoc(DocRecord $doc, string $locale = I18n::DEFAULT): array
    {
        return self::build(
            $doc->title . self::SUFFIX,
            $doc->description,
            '/docs/' . $doc->slug,
            $doc->slug,
            $locale,
        );
    }

    /**
     * @return array<string, string>
     */
    public static function forPage(
        string $title,
        string $description,
        string $path,
        string $ogSlug = 'home',
        string $locale = I18n::DEFAULT,
    ): array {
        return self::build($title, $description, $path, $ogSlug, $locale);
    }

    /**
     * @return array<string, string>
     */
    private static function build(
        string $title,
        string $description,
        string $path,
        string $ogSlug,
        string $locale,
    ): array {
        // The canonical/og:url is the locale's own URL (ja unprefixed,
        // en under /en) so each language self-canonicalizes. The OG
        // card image stays slug-keyed and locale-agnostic.
        $url = SiteUrl::abs(I18n::path($locale, $path));
        $image = SiteUrl::abs('/og/' . $ogSlug);

        return [
            'title' => $title,
            'description' => $description,
            'og:title' => $title,
            'og:description' => $description,
            'og:url' => $url,
            'og:image' => $image,
            'og:locale' => I18n::ogLocale($locale),
            'twitter:title' => $title,
            'twitter:description' => $description,
            'twitter:image' => $image,
        ];
    }
}
