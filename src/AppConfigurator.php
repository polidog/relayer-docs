<?php

declare(strict_types=1);

namespace App;

use Polidog\Relayer\AppConfigurator as BaseAppConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Application service registrations and the single composition root.
 *
 * Anything registered here participates in autowiring; the framework
 * applies autowire + public defaults after configure() runs, so a
 * bare register() call is usually enough. The project root is
 * available as $this->projectRoot.
 *
 * This is also the *only* place the environment is read: the doc
 * store's backend (Turso in prod, a local SQLite file in dev) is
 * chosen at runtime, so the relevant vars are resolved into container
 * parameters here and injected into DocStoreFactory via services.yaml.
 * Both the web app and the CLI (bin/docs) run configure(), so the
 * factory itself never touches env. `%env()%` placeholders can't be
 * used: Relayer compiles with resolveEnvPlaceholders=false and runs
 * the container un-dumped, so they never resolve; plain parameters
 * set before compile() do.
 */
final class AppConfigurator extends BaseAppConfigurator
{
    public function configure(ContainerBuilder $container): void
    {
        $container->setParameter('app.turso_url', self::env('TURSO_DATABASE_URL'));
        $container->setParameter('app.turso_token', self::env('TURSO_AUTH_TOKEN'));
        $container->setParameter('app.docs_db_path', self::env('DOCS_DB_PATH'));
    }

    /**
     * Read an env var the way the framework does (Relayer::readEnv):
     * real process env wins over `.env`; missing or non-string values
     * normalize to ''.
     */
    private static function env(string $name): string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? \getenv($name);

        return \is_string($value) ? \trim($value) : '';
    }
}
