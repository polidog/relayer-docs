<?php

declare(strict_types=1);

namespace App\Docs;

/**
 * The documentation store. One set of SQLite statements that runs
 * identically against a local file (dev) or Turso (prod) — the
 * difference is only the injected {@see SqlConnection}.
 *
 * The CLI writes (migrate/upsert/prune); the web app reads
 * (get/nav/search). The FTS5 mirror is maintained by hand inside the
 * same transaction as the row write, so a half-synced page can never
 * be searchable but unreadable (or vice versa).
 */
final class DocStore
{
    /** Snippet match delimiters — control chars that never occur in docs. */
    private const MARK_OPEN = "\x02";
    private const MARK_CLOSE = "\x03";

    public function __construct(
        private readonly SqlConnection $conn,
    ) {}

    public function migrate(): void
    {
        $this->conn->transactional([
            [
                'CREATE TABLE IF NOT EXISTS documents ('
                . ' slug TEXT PRIMARY KEY,'
                . ' title TEXT NOT NULL,'
                . " description TEXT NOT NULL DEFAULT '',"
                . " category TEXT NOT NULL DEFAULT '',"
                . ' position INTEGER NOT NULL DEFAULT 0,'
                . ' content TEXT NOT NULL,'
                . ' hash TEXT NOT NULL,'
                . ' updated_at TEXT NOT NULL'
                . ')',
                [],
            ],
            [
                'CREATE VIRTUAL TABLE IF NOT EXISTS documents_fts USING fts5('
                . " slug UNINDEXED, title, description, content, tokenize='trigram'"
                . ')',
                [],
            ],
            // Append-only change history. `id` (rowid alias) is the
            // monotonic order; the oldest row per slug is its creation.
            // The full snapshot is kept so a future diff/restore needs
            // no migration.
            [
                'CREATE TABLE IF NOT EXISTS document_revisions ('
                . ' id INTEGER PRIMARY KEY,'
                . ' slug TEXT NOT NULL,'
                . ' title TEXT NOT NULL,'
                . " description TEXT NOT NULL DEFAULT '',"
                . " category TEXT NOT NULL DEFAULT '',"
                . ' content TEXT NOT NULL,'
                . ' hash TEXT NOT NULL,'
                . ' recorded_at TEXT NOT NULL'
                . ')',
                [],
            ],
            [
                'CREATE INDEX IF NOT EXISTS idx_revisions_slug'
                . ' ON document_revisions (slug, id)',
                [],
            ],
            // Backfill: give every existing page one revision (its
            // current state) so history is never empty for docs that
            // predate this table. Idempotent — only slugs with no
            // revision yet are seeded, so it is safe to re-run (migrate
            // runs at the start of every CLI command).
            [
                'INSERT INTO document_revisions'
                . ' (slug, title, description, category, content, hash, recorded_at)'
                . ' SELECT slug, title, description, category, content, hash, updated_at'
                . ' FROM documents d'
                . ' WHERE NOT EXISTS ('
                . '   SELECT 1 FROM document_revisions r WHERE r.slug = d.slug'
                . ' )',
                [],
            ],
        ]);
    }

    /**
     * slug => content hash, for the CLI's change detection.
     *
     * @return array<string, string>
     */
    public function hashes(): array
    {
        $map = [];
        foreach ($this->conn->query('SELECT slug, hash FROM documents') as $row) {
            $map[(string) $row['slug']] = (string) $row['hash'];
        }

        return $map;
    }

    public function get(string $slug): ?DocRecord
    {
        $rows = $this->conn->query(
            'SELECT slug, title, description, category, position, content, hash, updated_at'
            . ' FROM documents WHERE slug = ? LIMIT 1',
            [$slug],
        );

        return [] === $rows ? null : DocRecord::fromRow($rows[0]);
    }

    /**
     * Every page's metadata, ordered for the sidebar (category, then
     * position, then title). Content is omitted — the nav doesn't need
     * it and pages can be large.
     *
     * @return list<DocRecord>
     */
    public function nav(): array
    {
        $rows = $this->conn->query(
            'SELECT slug, title, description, category, position, updated_at'
            . ' FROM documents ORDER BY position, title',
        );

        return \array_map(DocRecord::fromRow(...), $rows);
    }

    /**
     * One page's change history, newest first. The body is not
     * selected (list view shows date + title only); see
     * {@see DocRevision}.
     *
     * @return list<DocRevision>
     */
    public function revisions(string $slug, int $limit = 50): array
    {
        return $this->readRevisions(
            'SELECT id, slug, title, category, hash, recorded_at'
            . ' FROM document_revisions WHERE slug = ?'
            . ' ORDER BY id DESC LIMIT ' . \max(1, \min($limit, 200)),
            [$slug],
        );
    }

    /**
     * Recent changes across every page, newest first — the
     * site-wide changelog feed.
     *
     * @return list<DocRevision>
     */
    public function recentRevisions(int $limit = 200): array
    {
        return $this->readRevisions(
            'SELECT id, slug, title, category, hash, recorded_at'
            . ' FROM document_revisions'
            . ' ORDER BY id DESC LIMIT ' . \max(1, \min($limit, 500)),
        );
    }

    /**
     * The timestamp of a page's last *content* change (its newest
     * revision), or null when there is no history yet. Preferred over
     * `documents.updated_at` for display: a no-op `bin/docs edit`
     * bumps that column but records no revision, so this stays put.
     */
    public function lastChangedAt(string $slug): ?string
    {
        $revs = $this->revisions($slug, 1);

        return [] === $revs ? null : $revs[0]->recordedAt;
    }

    /**
     * @param list<mixed> $params
     *
     * @return list<DocRevision>
     */
    private function readRevisions(string $sql, array $params = []): array
    {
        try {
            $rows = $this->conn->query($sql, $params);
        } catch (\Throwable $e) {
            // The web app reads Turso but cannot create the table; the
            // schema is provisioned by `bin/docs migrate` from a local
            // machine. If a web deploy briefly precedes that migrate,
            // degrade the *history widget* to empty rather than 500 the
            // whole article. Anything that is not the missing table is
            // a real fault and must still surface.
            if (\str_contains($e->getMessage(), 'document_revisions')) {
                return [];
            }

            throw $e;
        }

        return \array_map(DocRevision::fromRow(...), $rows);
    }

    public function count(): int
    {
        $rows = $this->conn->query('SELECT COUNT(*) AS c FROM documents');

        return (int) ($rows[0]['c'] ?? 0);
    }

    /**
     * A short, order-independent digest of every page's content hash.
     * Used as the ETag for corpus-wide pages (home / search): it only
     * changes when a `bin/docs sync` actually changes some page, so
     * those pages stay 304-cacheable until content moves.
     */
    public function corpusTag(): string
    {
        $hashes = $this->hashes();
        \ksort($hashes);

        return \substr(\hash('sha256', (string) \json_encode($hashes)), 0, 24);
    }

    public function upsert(DocRecord $doc): void
    {
        // A new revision is recorded only when the content actually
        // changed (new page, or a different hash). `bin/docs edit`
        // always bumps `updated_at` even on a no-op save, so keying the
        // history off the hash keeps it free of empty entries. The read
        // is fine outside the transaction: the only writer is the
        // single-process CLI.
        $prev = $this->conn->query(
            'SELECT hash FROM documents WHERE slug = ? LIMIT 1',
            [$doc->slug],
        );
        $changed = [] === $prev || (string) ($prev[0]['hash'] ?? '') !== $doc->hash;

        $ops = [
            [
                'INSERT INTO documents'
                . ' (slug, title, description, category, position, content, hash, updated_at)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                . ' ON CONFLICT(slug) DO UPDATE SET'
                . ' title = excluded.title, description = excluded.description,'
                . ' category = excluded.category, position = excluded.position,'
                . ' content = excluded.content, hash = excluded.hash,'
                . ' updated_at = excluded.updated_at',
                [
                    $doc->slug, $doc->title, $doc->description, $doc->category,
                    $doc->position, $doc->content, $doc->hash, $doc->updatedAt,
                ],
            ],
            ['DELETE FROM documents_fts WHERE slug = ?', [$doc->slug]],
            [
                'INSERT INTO documents_fts (slug, title, description, content) VALUES (?, ?, ?, ?)',
                [$doc->slug, $doc->title, $doc->description, $doc->content],
            ],
        ];

        if ($changed) {
            // Same transaction as the row + FTS write, mirroring the
            // store's invariant: history, content and search index can
            // never disagree about what was published.
            $ops[] = [
                'INSERT INTO document_revisions'
                . ' (slug, title, description, category, content, hash, recorded_at)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $doc->slug, $doc->title, $doc->description, $doc->category,
                    $doc->content, $doc->hash, $doc->updatedAt,
                ],
            ];
        }

        $this->conn->transactional($ops);
    }

    public function delete(string $slug): void
    {
        $this->conn->transactional([
            ['DELETE FROM documents WHERE slug = ?', [$slug]],
            ['DELETE FROM documents_fts WHERE slug = ?', [$slug]],
        ]);
    }

    /**
     * Drop pages that no longer exist locally. Returns the removed
     * slugs so the CLI can report them.
     *
     * @param list<string> $keepSlugs
     *
     * @return list<string>
     */
    public function prune(array $keepSlugs): array
    {
        $keep = \array_fill_keys($keepSlugs, true);
        $removed = [];
        foreach (\array_keys($this->hashes()) as $slug) {
            if (!isset($keep[$slug])) {
                $this->delete($slug);
                $removed[] = $slug;
            }
        }

        return $removed;
    }

    /**
     * Full-text search. Trigram FTS handles Japanese and English
     * substring matching for terms of 3+ chars; anything shorter (or
     * an FTS miss) falls back to a LIKE scan so a query is never
     * silently empty.
     *
     * @return list<SearchHit>
     */
    public function search(string $query, int $limit = 20): array
    {
        $query = \trim($query);
        if ('' === $query) {
            return [];
        }
        $limit = \max(1, \min($limit, 50));

        $tokens = \preg_split('/\s+/u', $query) ?: [];
        $ftsTerms = [];
        foreach ($tokens as $token) {
            if (\mb_strlen($token) >= 3) {
                $ftsTerms[] = '"' . \str_replace('"', '""', $token) . '"';
            }
        }

        if ([] !== $ftsTerms) {
            $hits = $this->searchFts(\implode(' ', $ftsTerms), $limit);
            if ([] !== $hits) {
                return $hits;
            }
        }

        return $this->searchLike($query, $limit);
    }

    /**
     * @return list<SearchHit>
     */
    private function searchFts(string $match, int $limit): array
    {
        $sql = 'SELECT slug, title, description,'
            . ' snippet(documents_fts, 3, ?, ?, ?, 32) AS snippet'
            . ' FROM documents_fts WHERE documents_fts MATCH ? ORDER BY rank LIMIT ' . $limit;

        $rows = $this->conn->query($sql, [self::MARK_OPEN, self::MARK_CLOSE, '…', $match]);

        $hits = [];
        foreach ($rows as $row) {
            $slug = (string) $row['slug'];
            $meta = $this->get($slug);
            $hits[] = new SearchHit(
                slug: $slug,
                title: (string) $row['title'],
                description: (string) ($row['description'] ?? ''),
                category: $meta?->category ?? '',
                segments: $this->splitMarked((string) ($row['snippet'] ?? '')),
            );
        }

        return $hits;
    }

    /**
     * @return list<SearchHit>
     */
    private function searchLike(string $query, int $limit): array
    {
        $like = '%' . \str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query) . '%';
        $sql = 'SELECT slug, title, description, category, content FROM documents'
            . " WHERE title LIKE ? ESCAPE '\\' OR description LIKE ? ESCAPE '\\'"
            . " OR content LIKE ? ESCAPE '\\' ORDER BY position, title LIMIT " . $limit;

        $rows = $this->conn->query($sql, [$like, $like, $like]);

        $hits = [];
        foreach ($rows as $row) {
            $hits[] = new SearchHit(
                slug: (string) $row['slug'],
                title: (string) $row['title'],
                description: (string) ($row['description'] ?? ''),
                category: (string) ($row['category'] ?? ''),
                segments: $this->excerpt((string) ($row['content'] ?? ''), $query),
            );
        }

        return $hits;
    }

    /**
     * Split an FTS snippet on the match delimiters into render-ready
     * segments.
     *
     * @return list<array{text: string, hit: bool}>
     */
    private function splitMarked(string $snippet): array
    {
        $segments = [];
        $parts = \preg_split(
            '/(' . \preg_quote(self::MARK_OPEN, '/') . '|' . \preg_quote(self::MARK_CLOSE, '/') . ')/u',
            $snippet,
            -1,
            \PREG_SPLIT_DELIM_CAPTURE,
        ) ?: [];

        $hit = false;
        foreach ($parts as $part) {
            if (self::MARK_OPEN === $part) {
                $hit = true;

                continue;
            }
            if (self::MARK_CLOSE === $part) {
                $hit = false;

                continue;
            }
            if ('' === $part) {
                continue;
            }
            $segments[] = ['text' => $part, 'hit' => $hit];
        }

        return [] === $segments ? [['text' => $snippet, 'hit' => false]] : $segments;
    }

    /**
     * Build a highlighted excerpt around the first match for the LIKE
     * fallback (no FTS snippet available there).
     *
     * @return list<array{text: string, hit: bool}>
     */
    private function excerpt(string $content, string $query): array
    {
        $content = \trim(\preg_replace('/\s+/u', ' ', $content) ?? $content);
        $pos = \mb_stripos($content, $query);
        if (false === $pos) {
            return [['text' => \mb_substr($content, 0, 160) . (\mb_strlen($content) > 160 ? '…' : ''), 'hit' => false]];
        }

        $start = \max(0, $pos - 60);
        $window = \mb_substr($content, $start, 220);
        $prefix = $start > 0 ? '…' : '';

        $segments = [];
        if ('' !== $prefix) {
            $segments[] = ['text' => $prefix, 'hit' => false];
        }

        $offset = 0;
        $qlen = \mb_strlen($query);
        while (true) {
            $at = \mb_stripos($window, $query, $offset);
            if (false === $at) {
                $tail = \mb_substr($window, $offset);
                if ('' !== $tail) {
                    $segments[] = ['text' => $tail, 'hit' => false];
                }

                break;
            }
            if ($at > $offset) {
                $segments[] = ['text' => \mb_substr($window, $offset, $at - $offset), 'hit' => false];
            }
            $segments[] = ['text' => \mb_substr($window, $at, $qlen), 'hit' => true];
            $offset = $at + $qlen;
        }
        $segments[] = ['text' => '…', 'hit' => false];

        return $segments;
    }
}
