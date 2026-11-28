<?php

declare(strict_types=1);

namespace SymSvel\Module\Gdpr\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymSvel\Module\Gdpr\Retention\AnonymizationRequirement;
use SymSvel\Module\Gdpr\Retention\AnonymizationRequirementRegistry;
use SymSvel\Module\Gdpr\Retention\PurgePolicy;

final class AnonymizationRequirementRegistryTest extends TestCase
{
    private function requirement(string $module): AnonymizationRequirement
    {
        return new class($module) implements AnonymizationRequirement {
            public function __construct(private readonly string $module) {}
            public function moduleId(): string { return $this->module; }
        };
    }

    public function testEmptyRegistryIsUnlocked(): void
    {
        $registry = new AnonymizationRequirementRegistry([]);
        self::assertFalse($registry->isLocked());
        self::assertSame([], $registry->requiringAnonymization());
    }

    public function testEachRegisteredModuleForcesAnonymization(): void
    {
        $registry = new AnonymizationRequirementRegistry([
            $this->requirement('invoices'),
            $this->requirement('accounting'),
        ]);
        self::assertTrue($registry->isLocked());
        self::assertSame(['accounting', 'invoices'], $registry->requiringAnonymization());
        self::assertSame(PurgePolicy::ANONYMIZE, (new PurgePolicy())->effectiveMode(PurgePolicy::DELETE, $registry->requiringAnonymization()));

        $remaining = new AnonymizationRequirementRegistry([$this->requirement('accounting')]);
        self::assertTrue($remaining->isLocked());
    }

    public function testDuplicateModuleFailsClosed(): void
    {
        $this->expectException(\LogicException::class);
        new AnonymizationRequirementRegistry([$this->requirement('invoices'), $this->requirement('invoices')]);
    }

    public function testInvalidModuleFailsClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AnonymizationRequirementRegistry([$this->requirement('../invoices')]);
    }

    public function testInvalidProviderFailsClosed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AnonymizationRequirementRegistry([new \stdClass()]);
    }
}
