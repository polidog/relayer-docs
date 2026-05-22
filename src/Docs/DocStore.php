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
                . " summary TEXT NOT NULL DEFAULT '',"
                . ' recorded_at TEXT NOT NULL'
                . ')',
                [],
            ],
            [
                'CREATE INDEX IF NOT EXISTS idx_revisions_slug'
                . ' ON document_revisions (slug, id)',
                [],
            ],
            // Translations overlay. `documents` stays the canonical
            // Japanese page (untouched — the existing reader path and
            // its ETag are byte-identical); a non-`ja` rendering reads
            // its title/description/content from here and falls back to
            // the `documents` row when a translation is absent. Purely
            // additive, same idempotent risk class as document_revisions
            // above. Structural metadata (category, position) is shared
            // across locales and lives only in `documents`, joined in on
            // read — so the sidebar order can never diverge per locale.
            [
                'CREATE TABLE IF NOT EXISTS document_translations ('
                . ' slug TEXT NOT NULL,'
                . ' locale TEXT NOT NULL,'
                . ' title TEXT NOT NULL,'
                . " description TEXT NOT NULL DEFAULT '',"
                . ' content TEXT NOT NULL,'
                . ' hash TEXT NOT NULL,'
                . ' updated_at TEXT NOT NULL,'
                . ' PRIMARY KEY (slug, locale)'
                . ')',
                [],
            ],
            [
                'CREATE VIRTUAL TABLE IF NOT EXISTS document_translations_fts USING fts5('
                . " slug UNINDEXED, locale UNINDEXED, title, description, content,"
                . " tokenize='trigram'"
                . ')',
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

        // `summary` was added after document_revisions shipped, so a
        // store migrated by an earlier build has the table but not the
        // column (CREATE TABLE IF NOT EXISTS above is then a no-op).
        // SQLite/libSQL has no ADD COLUMN IF NOT EXISTS, so probe
        // table_info and add it once. Idempotent — migrate runs at the
        // start of every CLI command.
        $hasSummary = false;
        foreach ($this->conn->query('PRAGMA table_info(document_revisions)') as $col) {
            if ('summary' === ($col['name'] ?? null)) {
                $hasSummary = true;

                break;
            }
        }
        if (!$hasSummary) {
            $this->conn->execute(
                "ALTER TABLE document_revisions ADD COLUMN summary TEXT NOT NULL DEFAULT ''",
            );
        }

        // `locale` was added after the table (and `summary`) shipped, to
        // give each locale its own change history. Same idempotent
        // probe-then-ALTER as `summary`: every pre-existing revision is
        // canonical ja content, so the column defaults to 'ja' and the
        // ja changelog/history is byte-identical (the read path filters
        // `WHERE locale = 'ja'`, which is exactly the old full set).
        $hasLocale = false;
        foreach ($this->conn->query('PRAGMA table_info(document_revisions)') as $col) {
            if ('locale' === ($col['name'] ?? null)) {
                $hasLocale = true;

                break;
            }
        }
        if (!$hasLocale) {
            $this->conn->execute(
                "ALTER TABLE document_revisions ADD COLUMN locale TEXT NOT NULL DEFAULT 'ja'",
            );
        }

        // The site-wide changelog reads newest-first within a locale, so
        // index (locale, id). Created after the ALTER so the column
        // exists; IF NOT EXISTS keeps it idempotent like every other
        // migrate step.
        $this->conn->execute(
            'CREATE INDEX IF NOT EXISTS idx_revisions_locale'
            . ' ON document_revisions (locale, id)',
        );

        // Backfill: seed one revision (its current state) for every
        // stored translation that has none yet — translations imported
        // before this feature recorded no history, so without this the
        // localized changelog would be empty. recorded_at is the
        // translation's own updated_at; an empty summary renders as the
        // localized "Created" label (oldest revision). category comes
        // from the canonical row (structural, shared). Idempotent via
        // NOT EXISTS, mirroring the canonical backfill above.
        $this->conn->execute(
            'INSERT INTO document_revisions'
            . ' (slug, title, description, category, content, hash, locale, recorded_at)'
            . ' SELECT t.slug, t.title, t.description, d.category, t.content,'
            . ' t.hash, t.locale, t.updated_at'
            . ' FROM document_translations t'
            . ' JOIN documents d ON d.slug = t.slug'
            . ' WHERE NOT EXISTS ('
            . '   SELECT 1 FROM document_revisions r'
            . '   WHERE r.slug = t.slug AND r.locale = t.locale'
            . ' )',
        );
    }

    /**
     * slug => content hash, for the CLI's change detection.
     *
     * @return array<string, string>
     */
    public function hashes(string $locale = 'ja'): array
    {
        $map = [];
        if ('ja' === $locale) {
            foreach ($this->conn->query('SELECT slug, hash FROM documents') as $row) {
                $map[(string) $row['slug']] = (string) $row['hash'];
            }

            return $map;
        }

        // Per slug: the translation's hash when one exists, otherwise
        // the canonical hash (that page renders as a ja fallback in
        // this locale). So a locale's corpus tag flips on a translation
        // edit *and* on a ja edit of a still-untranslated page — the
        // home/search ETag for that locale stays exactly correct.
        foreach (
            $this->conn->query(
                'SELECT d.slug, COALESCE(t.hash, d.hash) AS hash'
                . ' FROM documents d'
                . ' LEFT JOIN document_translations t'
                . ' ON t.slug = d.slug AND t.locale = ?',
                [$locale],
            ) as $row
        ) {
            $map[(string) $row['slug']] = (string) $row['hash'];
        }

        return $map;
    }

    /**
     * One page in the requested locale. `ja` (or any unknown locale)
     * reads the canonical `documents` row — byte-identical to the
     * pre-i18n query so the reader path and its ETag never moved. A
     * non-`ja` locale reads the `document_translations` overlay
     * (joined to `documents` for the shared category/position); when
     * no translation exists it transparently falls back to the
     * canonical `ja` record. The returned record's `locale` is the one
     * actually served, so a caller that asked for `en` and got back
     * `locale === 'ja'` knows to show the "untranslated" notice.
     */
    public function get(string $slug, string $locale = 'ja'): ?DocRecord
    {
        if ('ja' !== $locale) {
            $rows = $this->conn->query(
                'SELECT t.slug, t.title, t.description, d.category, d.position,'
                . ' t.content, t.hash, t.updated_at, t.locale'
                . ' FROM document_translations t'
                . ' JOIN documents d ON d.slug = t.slug'
                . ' WHERE t.slug = ? AND t.locale = ? LIMIT 1',
                [$slug, $locale],
            );
            if ([] !== $rows) {
                return DocRecord::fromRow($rows[0]);
            }
        }

        $rows = $this->conn->query(
            'SELECT slug, title, description, category, position, content, hash, updated_at'
            . ' FROM documents WHERE slug = ? LIMIT 1',
            [$slug],
        );

        return [] === $rows ? null : DocRecord::fromRow($rows[0]);
    }

    /**
     * Slugs that have a stored translation for $locale — the
     * translation-coverage set the CLI's `list` reports against.
     *
     * @return array<string, true>
     */
    public function translatedSlugs(string $locale): array
    {
        $map = [];
        foreach (
            $this->conn->query(
                'SELECT slug FROM document_translations WHERE locale = ?',
                [$locale],
            ) as $row
        ) {
            $map[(string) $row['slug']] = true;
        }

        return $map;
    }

    /**
     * Every page's metadata, ordered for the sidebar (category, then
     * position, then title). Content is omitted — the nav doesn't need
     * it and pages can be large.
     *
     * @return list<DocRecord>
     */
    public function nav(string $locale = 'ja'): array
    {
        if ('ja' === $locale) {
            $rows = $this->conn->query(
                'SELECT slug, title, description, category, position, updated_at'
                . ' FROM documents ORDER BY position, title',
            );

            return \array_map(DocRecord::fromRow(...), $rows);
        }

        // Order and grouping stay structural (always from `documents`)
        // so the sidebar is identical across locales; only the visible
        // title/description are overlaid, COALESCE-ing back to the
        // canonical text for pages without a translation yet.
        $rows = $this->conn->query(
            'SELECT d.slug,'
            . ' COALESCE(t.title, d.title) AS title,'
            . ' COALESCE(t.description, d.description) AS description,'
            . ' d.category, d.position, d.updated_at,'
            . ' CASE WHEN t.slug IS NULL THEN ? ELSE ? END AS locale'
            . ' FROM documents d'
            . ' LEFT JOIN document_translations t'
            . ' ON t.slug = d.slug AND t.locale = ?'
            . ' ORDER BY d.position, d.title',
            ['ja', $locale, $locale],
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
    public function revisions(string $slug, int $limit = 50, string $locale = 'ja'): array
    {
        return $this->readRevisions(
            'SELECT id, slug, title, category, hash, summary, recorded_at'
            . ' FROM document_revisions WHERE slug = ? AND locale = ?'
            . ' ORDER BY id DESC LIMIT ' . \max(1, \min($limit, 200)),
            [$slug, $locale],
        );
    }

    /**
     * Recent changes across every page, newest first — the
     * site-wide changelog feed.
     *
     * @return list<DocRevision>
     */
    public function recentRevisions(int $limit = 200, string $locale = 'ja', int $offset = 0): array
    {
        return $this->readRevisions(
            'SELECT id, slug, title, category, hash, summary, recorded_at'
            . ' FROM document_revisions WHERE locale = ?'
            . ' ORDER BY id DESC LIMIT ' . \max(1, \min($limit, 500))
            . ' OFFSET ' . \max(0, $offset),
            [$locale],
        );
    }

    /**
     * Total recorded revisions for $locale — the row count behind
     * {@see recentRevisions()}, used to paginate the changelog.
     * Degrades to 0 (not a 500) when the revisions table/column is not
     * yet provisioned, mirroring {@see readRevisions()}.
     */
    public function revisionCount(string $locale = 'ja'): int
    {
        try {
            $rows = $this->conn->query(
                'SELECT COUNT(*) AS c FROM document_revisions WHERE locale = ?',
                [$locale],
            );
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (
                \str_contains($msg, 'document_revisions')
                || (\str_contains($msg, 'no such column') && \str_contains($msg, 'locale'))
            ) {
                return 0;
            }

            throw $e;
        }

        return (int) ($rows[0]['c'] ?? 0);
    }

    /**
     * The timestamp of a page's last *content* change (its newest
     * revision), or null when there is no history yet. Preferred over
     * `documents.updated_at` for display: a no-op `bin/docs edit`
     * bumps that column but records no revision, so this stays put.
     */
    public function lastChangedAt(string $slug, string $locale = 'ja'): ?string
    {
        $revs = $this->revisions($slug, 1, $locale);

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
            // whole article. Same for the `summary` column, added after
            // the table shipped: a deploy can briefly precede the
            // migrate that ALTERs it in. Anything else is a real fault
            // and must still surface.
            $msg = $e->getMessage();
            if (
                \str_contains($msg, 'document_revisions')
                || (
                    \str_contains($msg, 'no such column')
                    && (\str_contains($msg, 'summary') || \str_contains($msg, 'locale'))
                )
            ) {
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
    public function corpusTag(string $locale = 'ja'): string
    {
        $hashes = $this->hashes($locale);
        \ksort($hashes);

        // The `ja` payload is byte-identical to the pre-i18n digest so
        // every corpus-wide ETag (home / search / sitemap) is unmoved —
        // no sitewide CDN revalidation on deploy. Only a non-`ja`
        // corpus is namespaced, so locales can't collide.
        $payload = (string) \json_encode($hashes);
        if ('ja' !== $locale) {
            $payload = $locale . "\0" . $payload;
        }

        return \substr(\hash('sha256', $payload), 0, 24);
    }

    /**
     * @param null|string $summary the editor's change note (`--note`).
     *                              When null/blank a summary is derived
     *                              from the diff against the previous
     *                              revision, so history always says
     *                              what changed.
     */
    public function upsert(DocRecord $doc, ?string $summary = null, string $locale = 'ja'): void
    {
        if ('ja' !== $locale) {
            $this->upsertTranslation($doc, $locale, $summary);

            return;
        }

        // A new revision is recorded only when the content actually
        // changed (new page, or a different hash). `bin/docs edit`
        // always bumps `updated_at` even on a no-op save, so keying the
        // history off the hash keeps it free of empty entries. The read
        // is fine outside the transaction: the only writer is the
        // single-process CLI.
        $prev = $this->conn->query(
            'SELECT hash, content FROM documents WHERE slug = ? LIMIT 1',
            [$doc->slug],
        );
        $isNew = [] === $prev;
        $changed = $isNew || (string) ($prev[0]['hash'] ?? '') !== $doc->hash;

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
            $note = null === $summary ? '' : \trim($summary);
            if ('' === $note) {
                $note = self::summarize(
                    $isNew,
                    (string) ($prev[0]['content'] ?? ''),
                    $doc->content,
                );
            } else {
                $note = \mb_substr($note, 0, 200);
            }

            // Same transaction as the row + FTS write, mirroring the
            // store's invariant: history, content and search index can
            // never disagree about what was published. locale='ja' is
            // explicit so the canonical history stays exactly the rows
            // the localized changelog reads for `ja`.
            $ops[] = [
                'INSERT INTO document_revisions'
                . ' (slug, title, description, category, content, hash, summary, locale, recorded_at)'
                . " VALUES (?, ?, ?, ?, ?, ?, ?, 'ja', ?)",
                [
                    $doc->slug, $doc->title, $doc->description, $doc->category,
                    $doc->content, $doc->hash, $note, $doc->updatedAt,
                ],
            ];
        }

        $this->conn->transactional($ops);
    }

    /**
     * Write one locale's overlay for a page. The canonical `documents`
     * row and its FTS mirror are never touched — `category`/`position`
     * come from there on read, so a translation carries only the
     * locale-varying text. The translation FTS mirror is rebuilt in the
     * same transaction as the row, the same invariant the canonical
     * path keeps. A revision is recorded for this locale on a real
     * content change (same hash-keyed rule as the canonical path), so
     * the localized changelog/history is the locale's own.
     *
     * @param null|string $summary the editor's `--note`; auto-derived
     *                              (in $locale) from the diff when blank
     */
    private function upsertTranslation(DocRecord $doc, string $locale, ?string $summary = null): void
    {
        $prev = $this->conn->query(
            'SELECT hash, content FROM document_translations'
            . ' WHERE slug = ? AND locale = ? LIMIT 1',
            [$doc->slug, $locale],
        );
        $isNew = [] === $prev;
        $changed = $isNew || (string) ($prev[0]['hash'] ?? '') !== $doc->hash;

        // category is structural (canonical row); carry it on the
        // revision so the changelog can show it without a join.
        $cat = $this->conn->query(
            'SELECT category FROM documents WHERE slug = ? LIMIT 1',
            [$doc->slug],
        );
        $category = (string) ($cat[0]['category'] ?? '');

        $ops = [
            [
                'INSERT INTO document_translations'
                . ' (slug, locale, title, description, content, hash, updated_at)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?)'
                . ' ON CONFLICT(slug, locale) DO UPDATE SET'
                . ' title = excluded.title, description = excluded.description,'
                . ' content = excluded.content, hash = excluded.hash,'
                . ' updated_at = excluded.updated_at',
                [
                    $doc->slug, $locale, $doc->title, $doc->description,
                    $doc->content, $doc->hash, $doc->updatedAt,
                ],
            ],
            [
                'DELETE FROM document_translations_fts WHERE slug = ? AND locale = ?',
                [$doc->slug, $locale],
            ],
            [
                'INSERT INTO document_translations_fts'
                . ' (slug, locale, title, description, content) VALUES (?, ?, ?, ?, ?)',
                [$doc->slug, $locale, $doc->title, $doc->description, $doc->content],
            ],
        ];

        if ($changed) {
            $note = null === $summary ? '' : \trim($summary);
            $note = '' === $note
                ? self::summarize($isNew, (string) ($prev[0]['content'] ?? ''), $doc->content, $locale)
                : \mb_substr($note, 0, 200);

            $ops[] = [
                'INSERT INTO document_revisions'
                . ' (slug, title, description, category, content, hash, summary, locale, recorded_at)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $doc->slug, $doc->title, $doc->description, $category,
                    $doc->content, $doc->hash, $note, $locale, $doc->updatedAt,
                ],
            ];
        }

        $this->conn->transactional($ops);
    }

    /**
     * Derive a one-line change note (in $locale) from the diff vs the
     * previous content: which Markdown sections were added/removed,
     * plus a non-blank line delta. The fallback when the editor gives
     * no `--note`, so an entry never just says "更新"/"Updated". The
     * line-delta `(+n −m)` is language-neutral.
     */
    private static function summarize(bool $isNew, string $prev, string $new, string $locale = 'ja'): string
    {
        $en = 'en' === $locale;
        if ($isNew) {
            return $en ? 'Created' : '新規作成';
        }

        $headings = static function (string $md): array {
            $out = [];
            foreach (\explode("\n", $md) as $line) {
                if (\preg_match('/^#{1,6}\s+(.+?)\s*$/', $line, $m)) {
                    $out[\trim($m[1])] = true;
                }
            }

            return $out;
        };
        $prevH = $headings($prev);
        $newH = $headings($new);
        $added = \array_keys(\array_diff_key($newH, $prevH));
        $removed = \array_keys(\array_diff_key($prevH, $newH));

        $list = static function (array $names) use ($en): string {
            $shown = \array_slice($names, 0, 2);
            if ($en) {
                $s = \implode(', ', \array_map(static fn (string $n): string => '"' . $n . '"', $shown));

                return \count($names) > 2 ? $s . ' +' . (\count($names) - 2) . ' more' : $s;
            }
            $s = \implode('', \array_map(static fn (string $n): string => '「' . $n . '」', $shown));

            return \count($names) > 2 ? $s . '他' . (\count($names) - 2) . '件' : $s;
        };

        $lines = static fn (string $md): array => \array_values(\array_filter(
            \explode("\n", $md),
            static fn (string $l): bool => '' !== \trim($l),
        ));
        $pc = \array_count_values($lines($prev));
        $nc = \array_count_values($lines($new));
        $plus = 0;
        $minus = 0;
        foreach ($nc as $l => $c) {
            $plus += \max(0, $c - ($pc[$l] ?? 0));
        }
        foreach ($pc as $l => $c) {
            $minus += \max(0, $c - ($nc[$l] ?? 0));
        }
        $delta = ($plus + $minus) > 0 ? ' (+' . $plus . ' −' . $minus . ')' : '';

        $parts = [];
        if ([] !== $added) {
            $parts[] = $en ? 'Added ' . $list($added) : $list($added) . 'を追加';
        }
        if ([] !== $removed) {
            $parts[] = $en ? 'Removed ' . $list($removed) : $list($removed) . 'を削除';
        }
        if ([] === $parts) {
            $parts[] = ($plus + $minus) > 0
                ? ($en ? 'Updated body' : '本文を更新')
                : ($en ? 'Updated metadata' : 'メタ情報を更新');
        }

        return \mb_substr(\implode($en ? '; ' : '・', $parts) . $delta, 0, 120);
    }

    /**
     * Delete a page or just one of its translations. A non-`ja`
     * `$locale` removes only that overlay (the canonical page and
     * every other locale stay). `null` or `'ja'` removes the whole
     * page — the canonical row, its FTS, and *all* translations with
     * their FTS, since a translation cannot exist without its base
     * (the read path JOINs them).
     */
    public function delete(string $slug, ?string $locale = null): void
    {
        if (null !== $locale && 'ja' !== $locale) {
            $this->conn->transactional([
                [
                    'DELETE FROM document_translations WHERE slug = ? AND locale = ?',
                    [$slug, $locale],
                ],
                [
                    'DELETE FROM document_translations_fts WHERE slug = ? AND locale = ?',
                    [$slug, $locale],
                ],
            ]);

            return;
        }

        $this->conn->transactional([
            ['DELETE FROM documents WHERE slug = ?', [$slug]],
            ['DELETE FROM documents_fts WHERE slug = ?', [$slug]],
            ['DELETE FROM document_translations WHERE slug = ?', [$slug]],
            ['DELETE FROM document_translations_fts WHERE slug = ?', [$slug]],
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
    public function search(string $query, int $limit = 20, string $locale = 'ja'): array
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
            $hits = $this->searchFts(\implode(' ', $ftsTerms), $limit, $locale);
            if ([] !== $hits) {
                return $hits;
            }
        }

        return $this->searchLike($query, $limit, $locale);
    }

    /**
     * @return list<SearchHit>
     */
    private function searchFts(string $match, int $limit, string $locale = 'ja'): array
    {
        if ('ja' === $locale) {
            $sql = 'SELECT slug, title, description,'
                . ' snippet(documents_fts, 3, ?, ?, ?, 32) AS snippet'
                . ' FROM documents_fts WHERE documents_fts MATCH ? ORDER BY rank LIMIT ' . $limit;
            $rows = $this->conn->query($sql, [self::MARK_OPEN, self::MARK_CLOSE, '…', $match]);
        } else {
            // `content` is the 5th fts column here (slug, locale,
            // title, description, content), vs the 4th in documents_fts.
            // `locale` is UNINDEXED so it filters as a plain column.
            $sql = 'SELECT slug, title, description,'
                . ' snippet(document_translations_fts, 4, ?, ?, ?, 32) AS snippet'
                . ' FROM document_translations_fts'
                . ' WHERE document_translations_fts MATCH ? AND locale = ?'
                . ' ORDER BY rank LIMIT ' . $limit;
            $rows = $this->conn->query(
                $sql,
                [self::MARK_OPEN, self::MARK_CLOSE, '…', $match, $locale],
            );
        }

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
    private function searchLike(string $query, int $limit, string $locale = 'ja'): array
    {
        $like = '%' . \str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query) . '%';
        if ('ja' === $locale) {
            $sql = 'SELECT slug, title, description, category, content FROM documents'
                . " WHERE title LIKE ? ESCAPE '\\' OR description LIKE ? ESCAPE '\\'"
                . " OR content LIKE ? ESCAPE '\\' ORDER BY position, title LIMIT " . $limit;
            $rows = $this->conn->query($sql, [$like, $like, $like]);
        } else {
            $sql = 'SELECT t.slug, t.title, t.description, d.category, t.content'
                . ' FROM document_translations t'
                . ' JOIN documents d ON d.slug = t.slug'
                . " WHERE t.locale = ? AND (t.title LIKE ? ESCAPE '\\'"
                . " OR t.description LIKE ? ESCAPE '\\' OR t.content LIKE ? ESCAPE '\\')"
                . ' ORDER BY d.position, t.title LIMIT ' . $limit;
            $rows = $this->conn->query($sql, [$locale, $like, $like, $like]);
        }

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
