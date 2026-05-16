<?php

declare(strict_types=1);

namespace App\Docs;

/**
 * Builds the storage backend from explicit configuration. Pure: no
 * environment access, no globals. The selection rule — Turso when a
 * database URL is configured, otherwise a local SQLite file under
 * var/ — is the only logic here.
 *
 * Configuration is always *injected*: AppConfigurator (the single
 * composition root) resolves the env vars into `app.turso_url` /
 * `app.turso_token` / `app.docs_db_path` parameters and services.yaml
 * passes them here. Both the web app and the CLI (bin/docs) build the
 * container the same way, so there is exactly one place that reads the
 * environment and the factory never does.
 */
final class DocStoreFactory
{
    public static function create(
        string $projectRoot,
        string $tursoUrl,
        string $tursoToken,
        string $dbPath,
    ): DocStore {
        return new DocStore(self::connection($projectRoot, $tursoUrl, $tursoToken, $dbPath));
    }

    public static function connection(
        string $projectRoot,
        string $tursoUrl,
        string $tursoToken,
        string $dbPath,
    ): SqlConnection {
        if ('' !== $tursoUrl) {
            return new TursoConnection($tursoUrl, $tursoToken);
        }

        if ('' === $dbPath) {
            $dbPath = \rtrim($projectRoot, '/') . '/var/docs.db';
        }

        return new PdoConnection($dbPath);
    }
}
