<?php

declare(strict_types=1);

namespace App\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Test environment only: keeps every App\ service and alias public so
 * integration tests can fetch a port (e.g. AccountRepository) before any
 * controller or command depends on it and the container would inline it.
 */
final class PublicAppServicesForTestsPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        foreach ($container->getDefinitions() as $id => $definition) {
            // Entities, events and DTOs are registered too but carry autowiring
            // errors; leave them private so the container still removes them.
            if (str_starts_with($id, 'App\\') && !$definition->isAbstract() && !$definition->hasErrors()) {
                $definition->setPublic(true);
            }
        }
        foreach ($container->getAliases() as $id => $alias) {
            if (str_starts_with($id, 'App\\')) {
                $alias->setPublic(true);
            }
        }
    }
}
