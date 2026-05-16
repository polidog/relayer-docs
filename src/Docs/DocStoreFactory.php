<?php

declare(strict_types=1);

namespace App\Docs;

/**
 * Picks the storage backend from the environment so the same code
 * path serves dev and prod:
 *
 *  - `TURSO_DATABASE_URL` (+ `TURSO_AUTH_TOKEN`) set  -> Turso (prod)
 *  - otherwise                                        -> local SQLite
 *    file at `var/docs.db` (works with zero credentials)
 *
 * Used by both the DI container (web) and the CLI.
 */
final class DocStoreFactory
{
    public static function create(string $projectRoot): DocStore
    {
        return new DocStore(self::connection($projectRoot));
    }

    public static function connection(string $projectRoot): SqlConnection
    {
        $url = self::env('TURSO_DATABASE_URL');
        $token = self::env('TURSO_AUTH_TOKEN');

        if ('' !== $url) {
            return new TursoConnection($url, $token);
        }

        $path = self::env('DOCS_DB_PATH');
        if ('' === $path) {
            $path = \rtrim($projectRoot, '/') . '/var/docs.db';
        }

        return new PdoConnection($path);
    }

    /**
     * True when the active backend is the remote Turso database (used
     * by the CLI to label where a sync is writing).
     */
    public static function isRemote(): bool
    {
        return '' !== self::env('TURSO_DATABASE_URL');
    }

    private static function env(string $name): string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? \getenv($name);

        return \is_string($value) ? \trim($value) : '';
    }
}
