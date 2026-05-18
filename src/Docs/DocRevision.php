<?php

declare(strict_types=1);

namespace App\Docs;

/**
 * One recorded version of a page — appended to `document_revisions`
 * every time {@see DocStore::upsert()} stores a *content change* (a
 * no-op `bin/docs edit` that only bumps the timestamp does not make
 * one). Newest first by `id`; the oldest row for a slug is its
 * original creation.
 *
 * The full body is captured so a future "view this version" / diff
 * can be added without a schema change. List views
 * ({@see DocStore::revisions()} / {@see DocStore::recentRevisions()})
 * leave {@see content} empty on purpose — they only show date + title
 * and a per-row body would be needless weight; fetch a single
 * revision by id when the body is actually needed.
 */
final readonly class DocRevision
{
    public function __construct(
        public int $id,
        public string $slug,
        public string $title,
        public string $category,
        public string $content,
        public string $hash,
        public string $recordedAt,
    ) {}

    /**
     * @param array<string, mixed> $row a row from `document_revisions`
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) ($row['id'] ?? 0),
            slug: (string) $row['slug'],
            title: (string) $row['title'],
            category: (string) ($row['category'] ?? ''),
            content: (string) ($row['content'] ?? ''),
            hash: (string) ($row['hash'] ?? ''),
            recordedAt: (string) ($row['recorded_at'] ?? ''),
        );
    }
}
