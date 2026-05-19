<?php

declare(strict_types=1);

namespace App\Docs;

/**
 * One documentation page: the Markdown body plus the metadata parsed
 * from its front matter. This is the single shape that flows from the
 * CLI (local Markdown) into the store and back out to the web pages.
 *
 * `locale` is the locale this record was actually served in. The
 * canonical Japanese rows in `documents` are `'ja'`; a translation
 * read from `document_translations` carries its own locale. When a
 * caller asks for `en` but no translation exists the store returns the
 * `ja` record, so `locale !== requested` is exactly the "untranslated,
 * showing the original" signal the views surface.
 */
final readonly class DocRecord
{
    public function __construct(
        public string $slug,
        public string $title,
        public string $description,
        public string $category,
        public int $position,
        public string $content,
        public string $hash,
        public string $updatedAt,
        public string $locale = 'ja',
    ) {}

    /**
     * Stable content hash used by the CLI to skip unchanged pages on
     * sync. Covers everything a reader sees so a metadata-only edit
     * (title/order) still counts as a change.
     */
    public static function hashOf(
        string $title,
        string $description,
        string $category,
        int $position,
        string $content,
    ): string {
        return \hash('sha256', $title . "\0" . $description . "\0" . $category . "\0" . $position . "\0" . $content);
    }

    /**
     * @param array<string, mixed> $row a row from `documents` (no
     *                                  `locale` column → defaults to
     *                                  `'ja'`) or a `document_translations`
     *                                  JOIN (carries its own `locale`)
     */
    public static function fromRow(array $row): self
    {
        return new self(
            slug: (string) $row['slug'],
            title: (string) $row['title'],
            description: (string) ($row['description'] ?? ''),
            category: (string) ($row['category'] ?? ''),
            position: (int) ($row['position'] ?? 0),
            content: (string) ($row['content'] ?? ''),
            hash: (string) ($row['hash'] ?? ''),
            updatedAt: (string) ($row['updated_at'] ?? ''),
            locale: '' !== (string) ($row['locale'] ?? '') ? (string) $row['locale'] : 'ja',
        );
    }
}
