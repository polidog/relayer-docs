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
 * {@see summary} is what changed (the editor's `--note`, or a
 * diff-derived fallback) — shown in every list view. The full body is
 * still captured so a future "view this version" / diff needs no
 * schema change. List views
 * ({@see DocStore::revisions()} / {@see DocStore::recentRevisions()})
 * leave {@see content} empty on purpose — a per-row body would be
 * needless weight; fetch a single revision by id when it is needed.
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
        public string $summary,
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
            summary: (string) ($row['summary'] ?? ''),
            recordedAt: (string) ($row['recorded_at'] ?? ''),
        );
    }
}
