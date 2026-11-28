<?php

declare(strict_types=1);

namespace SymSvel\Module\Gdpr\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymSvel\Module\Gdpr\Retention\PurgePolicy;

final class PurgePolicyTest extends TestCase
{
    public function testAnonymizationIsDefaultAndDeleteIsExplicit(): void
    {
        $policy = new PurgePolicy();
        self::assertFalse($policy->isLocked([]));
        self::assertSame(PurgePolicy::ANONYMIZE, $policy->effectiveMode(null, []));
        self::assertSame(PurgePolicy::DELETE, $policy->effectiveMode(PurgePolicy::DELETE, []));
        $policy->validateSelection(PurgePolicy::DELETE, []);
    }

    public function testAnyActiveRequirementForcesAnonymization(): void
    {
        $policy = new PurgePolicy();
        foreach ([['invoices', 'accounting'], ['accounting'], ['invoices']] as $modules) {
            self::assertTrue($policy->isLocked($modules));
            self::assertSame(PurgePolicy::ANONYMIZE, $policy->effectiveMode(PurgePolicy::DELETE, $modules));
            $policy->validateSelection(PurgePolicy::ANONYMIZE, $modules);
        }

        // Persist the forced mode before unlocking; do not restore the previous selection.
        $forced = $policy->effectiveMode(PurgePolicy::DELETE, ['invoices']);
        self::assertFalse($policy->isLocked([]));
        self::assertSame(PurgePolicy::ANONYMIZE, $policy->effectiveMode($forced, []));
    }

    public function testRejectsExplicitDeleteWhileLocked(): void
    {
        $this->expectException(\DomainException::class);
        (new PurgePolicy())->validateSelection(PurgePolicy::DELETE, ['invoices']);
    }

    public function testRejectsUnknownMode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PurgePolicy())->effectiveMode('unknown', []);
    }
}
