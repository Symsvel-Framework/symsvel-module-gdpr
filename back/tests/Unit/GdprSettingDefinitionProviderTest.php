<?php

declare(strict_types=1);

namespace SymSvel\Module\Gdpr\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymSvel\Module\Gdpr\Retention\AnonymizationRequirement;
use SymSvel\Module\Gdpr\Retention\AnonymizationRequirementRegistry;
use SymSvel\Module\Gdpr\Settings\GdprSettingDefinitionProvider;
use SymSvel\Module\Settings\Service\SettingRegistry;

final class GdprSettingDefinitionProviderTest extends TestCase
{
    private function definitions(array $modules): SettingRegistry
    {
        $requirements = array_map(static fn (string $module): AnonymizationRequirement => new class($module) implements AnonymizationRequirement {
            public function __construct(private readonly string $module) {}
            public function moduleId(): string { return $this->module; }
        }, $modules);
        return new SettingRegistry([new GdprSettingDefinitionProvider(new AnonymizationRequirementRegistry($requirements))]);
    }

    public function testDefaultsMatchMaintainerDecisions(): void
    {
        $registry = $this->definitions([]);
        foreach ([
            'gdpr.enabled' => '1',
            'gdpr.inactivity_enabled' => '1',
            'gdpr.inactivity_months' => '12',
            'gdpr.inactivity_warning_days' => '7',
            'gdpr.consent_validity_months' => '6',
            'gdpr.deletion_mode' => 'anonymize',
        ] as $key => $value) {
            self::assertSame($value, $registry->get($key)?->defaultValue);
        }
        self::assertSame('0', $registry->get('gdpr.purge_locked')?->forcedValue);
        self::assertFalse($registry->get('gdpr.purge_locked')?->editable);
        self::assertTrue($registry->get('gdpr.deletion_mode')?->editable);
        self::assertArrayHasKey('delete', $registry->get('gdpr.deletion_mode')->choices);
    }

    public function testEveryActiveRequirementLocksTheSettingAndForcesAnonymization(): void
    {
        foreach ([['invoices'], ['accounting'], ['invoices', 'accounting']] as $modules) {
            $registry = $this->definitions($modules);
            self::assertSame('1', $registry->get('gdpr.purge_locked')?->forcedValue);
            $mode = $registry->get('gdpr.deletion_mode');
            self::assertFalse($mode->editable);
            self::assertSame('anonymize', $mode->forcedValue);
            self::assertSame(['anonymize' => 'Anonymisation'], $mode->choices);
        }
    }
}
