<?php

declare(strict_types=1);

namespace App\Docs;

use PDO;
use RuntimeException;

/**
 * Local development backend: a SQLite file under `var/`. Same SQLite
 * dialect (incl. FTS5 trigram) as Turso, so nothing about the queries
 * changes between dev and prod — only this transport.
 */
final class PdoConnection implements SqlConnection
{
    private PDO $pdo;

    public function __construct(string $filePath)
    {
        $dir = \dirname($filePath);
        if (!\is_dir($dir) && !\mkdir($dir, 0o775, true) && !\is_dir($dir)) {
            throw new RuntimeException("Cannot create database directory: {$dir}");
        }

        $this->pdo = new PDO('sqlite:' . $filePath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        // WAL keeps the read-heavy web server from blocking while the
        // CLI writes during a sync.
        $this->pdo->query('PRAGMA journal_mode = WAL');
        $this->pdo->query('PRAGMA busy_timeout = 5000');
    }

    public function query(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->normalize($params));

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll();

        return $rows;
    }

    public function execute(string $sql, array $params = []): void
    {
        $this->pdo->prepare($sql)->execute($this->normalize($params));
    }

    public function transactional(array $ops): void
    {
        $this->pdo->beginTransaction();
        try {
            foreach ($ops as [$sql, $params]) {
                $this->pdo->prepare($sql)->execute($this->normalize($params));
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
    }

    /**
     * SQLite via PDO binds booleans oddly; normalize to plain scalars
     * so the two backends behave identically.
     *
     * @param list<mixed> $params
     *
     * @return list<mixed>
     */
    private function normalize(array $params): array
    {
        return \array_map(
            static fn (mixed $v): mixed => \is_bool($v) ? ($v ? 1 : 0) : $v,
            $params,
        );
    }
}
