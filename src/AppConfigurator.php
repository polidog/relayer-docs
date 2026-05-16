<?php

declare(strict_types=1);

namespace App;

use Polidog\Relayer\AppConfigurator as BaseAppConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Application service registrations.
 *
 * Anything registered here participates in autowiring; the
 * framework applies autowire + public defaults after configure()
 * runs, so a bare register() call is usually enough. The project
 * root is available as $this->projectRoot.
 */
final class AppConfigurator extends BaseAppConfigurator
{
    public function configure(ContainerBuilder $container): void
    {
        // Register or override services here.
    }
}
