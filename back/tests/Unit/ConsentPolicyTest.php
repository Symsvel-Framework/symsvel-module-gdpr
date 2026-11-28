<?php

declare(strict_types=1);

namespace SymSvel\Module\Gdpr\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymSvel\Module\Gdpr\Consent\ConsentDecision;
use SymSvel\Module\Gdpr\Consent\ConsentPolicy;

final class ConsentPolicyTest extends TestCase
{
    public function testAnExplicitCurrentScopedGrantIsRequired(): void
    {
        $policy = new ConsentPolicy();
        $now = new \DateTimeImmutable('2026-11-27T12:00:00Z');
        $decision = new ConsentDecision('front', 'tracker-example', 2, true,
            $now, $policy->expiry($now, 6));
        self::assertTrue($policy->permits(true, 'front', 'tracker-example', 2, $now, $decision));
        self::assertFalse($policy->permits(false, 'front', 'tracker-example', 2, $now, $decision));
        self::assertFalse($policy->permits(true, 'app', 'tracker-example', 2, $now, $decision));
        self::assertFalse($policy->permits(true, 'bo', 'tracker-example', 2, $now, $decision));
        self::assertFalse($policy->permits(true, 'front', 'tracker-other', 2, $now, $decision));
        self::assertFalse($policy->permits(true, 'front', 'tracker-example', 3, $now, $decision));
        self::assertFalse($policy->permits(true, 'front', 'tracker-example', 2, $now, null));
        self::assertFalse($policy->permits(true, 'front', 'tracker-example', 2, $now->modify('-1 second'), $decision));
        self::assertFalse($policy->permits(true, 'front', 'tracker-example', 2, $decision->expiresAt, $decision));

        $withdrawn = new ConsentDecision('front', 'tracker-example', 2, false, $now, $decision->expiresAt);
        self::assertFalse($policy->permits(true, 'front', 'tracker-example', 2, $now, $withdrawn));
    }

    public function testExpiryClampsCalendarMonthsAndPreservesUtcTime(): void
    {
        $policy = new ConsentPolicy();
        self::assertSame('2027-02-28T12:34:56+00:00',
            $policy->expiry(new \DateTimeImmutable('2026-08-31T14:34:56+02:00'), 6)->format(DATE_ATOM));
        self::assertSame('2028-02-29T12:34:56+00:00',
            $policy->expiry(new \DateTimeImmutable('2027-08-31T12:34:56Z'), 6)->format(DATE_ATOM));
    }

    public function testBoCannotHaveAnOptionalTrackingConsent(): void
    {
        $now = new \DateTimeImmutable('2026-11-27T12:00:00Z');
        $this->expectException(\InvalidArgumentException::class);
        new ConsentDecision('bo', 'tracker-example', 1, true, $now, $now->modify('+6 months'));
    }

    public function testInvalidExpiryIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ConsentPolicy())->expiry(new \DateTimeImmutable(), 0);
    }
}
