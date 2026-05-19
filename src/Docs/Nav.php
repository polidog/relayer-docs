<?php

declare(strict_types=1);

namespace App\Docs;

/**
 * Navigation *data* only — grouping and prev/next computation. It
 * deliberately returns plain arrays/records and never any markup, so
 * the .psx views can render it with JSX (`{array_map(...)}`) instead
 * of building elements in PHP.
 */
final class Nav
{
    /**
     * Sidebar order, flat (used for prev/next).
     *
     * @return list<DocRecord>
     */
    public static function flat(DocStore $store, string $locale = 'ja'): array
    {
        return $store->nav($locale);
    }

    /**
     * Pages grouped by category, source order preserved.
     *
     * @return list<array{category: string, docs: list<DocRecord>}>
     */
    public static function groups(DocStore $store, string $locale = 'ja'): array
    {
        $byCat = [];
        foreach ($store->nav($locale) as $doc) {
            $key = '' === $doc->category ? 'ドキュメント' : $doc->category;
            $byCat[$key][] = $doc;
        }

        $groups = [];
        foreach ($byCat as $category => $docs) {
            $groups[] = ['category' => (string) $category, 'docs' => $docs];
        }

        return $groups;
    }

    /**
     * @param list<DocRecord> $flat
     *
     * @return array{prev: ?DocRecord, next: ?DocRecord}
     */
    public static function prevNext(array $flat, string $slug): array
    {
        $index = null;
        foreach ($flat as $i => $doc) {
            if ($doc->slug === $slug) {
                $index = $i;

                break;
            }
        }

        if (null === $index) {
            return ['prev' => null, 'next' => null];
        }

        return [
            'prev' => $flat[$index - 1] ?? null,
            'next' => $flat[$index + 1] ?? null,
        ];
    }
}
