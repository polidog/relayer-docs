<?php

declare(strict_types=1);

namespace App;

use App\Docs\DocRecord;

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
    public static function forDoc(DocRecord $doc): array
    {
        return self::build(
            $doc->title . self::SUFFIX,
            $doc->description,
            '/docs/' . $doc->slug,
            $doc->slug,
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
    ): array {
        return self::build($title, $description, $path, $ogSlug);
    }

    /**
     * @return array<string, string>
     */
    private static function build(
        string $title,
        string $description,
        string $path,
        string $ogSlug,
    ): array {
        $url = SiteUrl::abs($path);
        $image = SiteUrl::abs('/og/' . $ogSlug);

        return [
            'title' => $title,
            'description' => $description,
            'og:title' => $title,
            'og:description' => $description,
            'og:url' => $url,
            'og:image' => $image,
            'twitter:title' => $title,
            'twitter:description' => $description,
            'twitter:image' => $image,
        ];
    }
}
