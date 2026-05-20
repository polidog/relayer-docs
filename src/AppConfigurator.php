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
 * The doc store's backend (Turso in prod, local SQLite in dev) and the
 * `app.turso_*` / `app.docs_db_path` parameters that drive it are wired
 * directly in `config/services.yaml` via `%env(...)%` placeholders, not
 * here. That makes the values safe under `vendor/bin/relayer
 * container:compile`: PhpDumper emits per-request `getEnv()` calls for
 * `%env()%` placeholders, so secrets injected at runtime (Fly secrets,
 * Cloud Run env) resolve at every request rather than being baked into
 * the dumped container at build time. See /docs/services-di and
 * /docs/cli.
 *
 * This class is kept (rather than removed) as the explicit composition
 * root: `Relayer::boot()` and `bin/docs` instantiate it the same way,
 * and any future programmatic service registration goes here.
 */
final class AppConfigurator extends BaseAppConfigurator
{
    public function configure(ContainerBuilder $container): void
    {
        // No programmatic registrations yet. Env-derived parameters
        // live in services.yaml; future custom services (registered by
        // hand, autowire off, etc.) would land here.
    }
}
