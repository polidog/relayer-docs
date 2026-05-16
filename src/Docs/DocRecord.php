<?php

declare(strict_types=1);

namespace App\Docs;

/**
 * One documentation page: the Markdown body plus the metadata parsed
 * from its front matter. This is the single shape that flows from the
 * CLI (local Markdown) into the store and back out to the web pages.
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
     * @param array<string, mixed> $row a row from the `documents` table
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
        );
    }
}
