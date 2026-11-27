<?php

declare(strict_types=1);

namespace SymSvel\Module\Gdpr\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymSvel\Module\Gdpr\Retention\InactivityPolicy;

final class InactivityPolicyTest extends TestCase
{
    private function at(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }

    public function testCalendarDeadlineClampsEndOfMonthAndLeapDay(): void
    {
        $policy = new InactivityPolicy();
        self::assertSame('2027-02-28T15:03:52+00:00', $policy->deadline($this->at('2024-02-29 15:03:52'), 36)->format(DATE_ATOM));
        self::assertSame('2026-02-28T12:00:00+00:00', $policy->deadline($this->at('2026-01-31 13:00:00+01:00'), 1)->format(DATE_ATOM));
    }

    public function testNormalWarningAndExpiry(): void
    {
        $policy = new InactivityPolicy();
        $activity = $this->at('2026-11-23 15:03:52');
        $notice = $this->at('2027-11-16 15:03:52');
        self::assertSame(InactivityPolicy::WAIT, $policy->decide($activity, $this->at('2027-11-16 15:03:51'), 12, 7));
        self::assertSame(InactivityPolicy::WARN, $policy->decide($activity, $notice, 12, 7));
        self::assertSame(InactivityPolicy::WAIT, $policy->decide($activity, $this->at('2027-11-23 15:03:51'), 12, 7, $notice));
        self::assertSame(InactivityPolicy::ERASE, $policy->decide($activity, $this->at('2027-11-23 15:03:52'), 12, 7, $notice));
    }

    public function testMissingOrLateNoticeNeverShortensNoticePeriod(): void
    {
        $policy = new InactivityPolicy();
        $activity = $this->at('2026-11-23');
        $late = $this->at('2027-11-24');
        self::assertSame(InactivityPolicy::WARN, $policy->decide($activity, $late, 12, 7));
        self::assertSame(InactivityPolicy::WAIT, $policy->decide($activity, $this->at('2027-11-30 23:59:59'), 12, 7, $late));
        self::assertSame(InactivityPolicy::ERASE, $policy->decide($activity, $this->at('2027-12-01'), 12, 7, $late));
    }

    public function testPriorCycleAndFutureNoticesDoNotAuthorizeErasure(): void
    {
        $policy = new InactivityPolicy();
        $activity = $this->at('2027-01-01');
        $now = $this->at('2028-01-02');
        foreach (['2026-12-20', '2027-01-01', '2027-12-01', '2028-01-03'] as $invalid) {
            self::assertSame(InactivityPolicy::WARN, $policy->decide($activity, $now, 12, 7, $this->at($invalid)));
        }
        self::assertSame(InactivityPolicy::WAIT, $policy->decide($activity, $this->at('2027-02-01'), 12, 7, $this->at('2026-12-20')));
    }

    public function testRejectsInvalidDuration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new InactivityPolicy())->deadline($this->at('2026-01-01'), 0);
    }

    public function testRejectsInvalidNoticePeriod(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new InactivityPolicy())->decide($this->at('2026-01-01'), $this->at('2027-01-01'), 12, 0);
    }
}
