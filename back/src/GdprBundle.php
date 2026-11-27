<?php

declare(strict_types=1);

namespace SymSvel\Module\Gdpr;

use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use SymSvel\Module\Gdpr\Retention\AnonymizationRequirementRegistry;
use SymSvel\Module\Gdpr\Retention\InactivityPolicy;
use SymSvel\Module\Gdpr\Retention\PurgePolicy;

final class GdprBundle extends AbstractBundle
{
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->register(InactivityPolicy::class);
        $builder->register(PurgePolicy::class);
        $builder->register(AnonymizationRequirementRegistry::class)
            ->setArgument('$requirements', new TaggedIteratorArgument('symsvel.gdpr.anonymization_requirement'));
    }
}
