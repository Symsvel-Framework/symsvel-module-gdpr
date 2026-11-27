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

final class GdprBundleServicesTest extends TestCase
{
    public function testRegistersNativeRetentionServices(): void
    {
        $builder = new ContainerBuilder();
        $configurator = (new \ReflectionClass(ContainerConfigurator::class))->newInstanceWithoutConstructor();
        (new GdprBundle())->loadExtension([], $configurator, $builder);

        self::assertTrue($builder->hasDefinition(PurgePolicy::class));
        self::assertTrue($builder->hasDefinition(InactivityPolicy::class));
        $argument = $builder->getDefinition(AnonymizationRequirementRegistry::class)->getArgument('$requirements');
        self::assertInstanceOf(TaggedIteratorArgument::class, $argument);
        self::assertSame('symsvel.gdpr.anonymization_requirement', $argument->getTag());
    }
}
