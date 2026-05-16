<?php

declare(strict_types=1);

namespace App\Docs;

/**
 * The thin SQL surface the doc store needs. Both backends speak
 * SQLite dialect — a local file via PDO for development, and a remote
 * Turso/libSQL database over the HTTP pipeline API in production — so
 * {@see DocStore} can share one set of statements and only the
 * transport differs.
 *
 * All statements use positional `?` placeholders (the common subset
 * PDO and Hrana both accept).
 */
interface SqlConnection
{
    /**
     * @param list<mixed> $params
     *
     * @return list<array<string, mixed>> rows as ordered assoc maps
     */
    public function query(string $sql, array $params = []): array;

    /**
     * @param list<mixed> $params
     */
    public function execute(string $sql, array $params = []): void;

    /**
     * Run several writes as one atomic unit. PDO uses a real
     * transaction; Turso sends the whole batch in a single pipeline
     * wrapped in BEGIN/COMMIT.
     *
     * @param list<array{0: string, 1: list<mixed>}> $ops [sql, params] pairs
     */
    public function transactional(array $ops): void;
}
