<?php

declare(strict_types=1);

namespace SymSvel\Module\Gdpr\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use SymSvel\Module\Gdpr\GdprBundle;
use SymSvel\Module\Gdpr\Retention\AnonymizationRequirementRegistry;
use SymSvel\Module\Gdpr\Retention\InactivityPolicy;
use SymSvel\Module\Gdpr\Retention\PurgePolicy;
use SymSvel\Module\Gdpr\Settings\GdprSettingDefinitionProvider;
use SymSvel\Module\Gdpr\Settings\GdprConfiguration;

final class GdprBundleServicesTest extends TestCase
{
    public function testRegistersNativeRetentionServices(): void
    {
        $builder = new ContainerBuilder();
        $configurator = (new \ReflectionClass(ContainerConfigurator::class))->newInstanceWithoutConstructor();
        (new GdprBundle())->loadExtension([], $configurator, $builder);

        self::assertTrue($builder->hasDefinition(PurgePolicy::class));
        self::assertTrue($builder->hasDefinition(InactivityPolicy::class));
        self::assertTrue($builder->getDefinition(GdprSettingDefinitionProvider::class)->hasTag('symsvel.settings.definition_provider'));
        self::assertTrue($builder->hasDefinition(GdprConfiguration::class));
        self::assertSame('symsvel.gdpr.account_retention_owner', $builder->getDefinition(\SymSvel\Module\Gdpr\Retention\AccountRetentionOwnerRegistry::class)->getArgument(0)->getTag());
        self::assertSame('symsvel.gdpr.account_erasure_contributor', $builder->getDefinition(\SymSvel\Module\Gdpr\Retention\AccountErasureContributorRegistry::class)->getArgument(1)->getTag());
        self::assertTrue($builder->getDefinition(\SymSvel\Module\Gdpr\Command\ProcessInactivityCommand::class)->hasTag('console.command'));
        $argument = $builder->getDefinition(AnonymizationRequirementRegistry::class)->getArgument(0);
        self::assertInstanceOf(TaggedIteratorArgument::class, $argument);
        self::assertSame('symsvel.gdpr.anonymization_requirement', $argument->getTag());
    }
}
