<?php

declare(strict_types=1);

namespace SymSvel\Module\Gdpr;

use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use SymSvel\Module\Gdpr\Retention\AnonymizationRequirementRegistry;
use SymSvel\Module\Gdpr\Retention\InactivityPolicy;
use SymSvel\Module\Gdpr\Consent\ConsentPolicy;
use SymSvel\Module\Gdpr\Retention\PurgePolicy;
use SymSvel\Module\Gdpr\Settings\GdprSettingDefinitionProvider;
use SymSvel\Module\Gdpr\Settings\GdprConfiguration;

final class GdprBundle extends AbstractBundle
{
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->register(InactivityPolicy::class);
        $builder->register(ConsentPolicy::class);
        $builder->register(PurgePolicy::class);
        $builder->register(GdprSettingDefinitionProvider::class)->setAutowired(true)->addTag('symsvel.settings.definition_provider');
        $builder->register(GdprConfiguration::class)->setAutowired(true);
        $builder->register(AnonymizationRequirementRegistry::class)
            ->setArgument(0, new TaggedIteratorArgument('symsvel.gdpr.anonymization_requirement'));
    }
}
