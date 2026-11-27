<?php

declare(strict_types=1);

namespace SymSvel\Module\Gdpr\Retention;

/**
 * Pure scheduling decision. ERASE is a candidate, not permission to delete.
 * The caller supplies a reliable activity timestamp and a successful notice
 * belonging to this inactivity cycle; it must recheck all runtime safeguards.
 */
final class InactivityPolicy
{
    public const WAIT = 'wait';
    public const WARN = 'warn';
    public const ERASE = 'erase';

    public function deadline(\DateTimeImmutable $activity, int $months): \DateTimeImmutable
    {
        if ($months < 1) {
            throw new \InvalidArgumentException('Inactivity duration must be positive.');
        }

        $activity = $activity->setTimezone(new \DateTimeZone('UTC'));
        $first = $activity->modify('first day of this month')->add(new \DateInterval('P'.$months.'M'));

        return $first->setDate(
            (int) $first->format('Y'),
            (int) $first->format('n'),
            min((int) $activity->format('j'), (int) $first->format('t')),
        );
    }

    public function decide(
        \DateTimeImmutable $activity,
        \DateTimeImmutable $now,
        int $months,
        int $warningDays,
        ?\DateTimeImmutable $noticeSentAt = null,
    ): string {
        if ($warningDays < 1) {
            throw new \InvalidArgumentException('Notice duration must be positive.');
        }

        $utc = new \DateTimeZone('UTC');
        $activity = $activity->setTimezone($utc);
        $now = $now->setTimezone($utc);
        $deadline = $this->deadline($activity, $months);
        $noticePeriod = new \DateInterval('P'.$warningDays.'D');
        $warningAt = $deadline->sub($noticePeriod);

        if ($now < $activity || $now < $warningAt) {
            return self::WAIT;
        }

        // Old notices, notices outside the current policy window and future
        // timestamps cannot authorize an irreversible operation.
        if ($noticeSentAt === null || $noticeSentAt <= $activity || $noticeSentAt < $warningAt || $noticeSentAt > $now) {
            return self::WARN;
        }

        $noticeDeadline = $noticeSentAt->setTimezone($utc)->add($noticePeriod);

        return $now >= $deadline && $now >= $noticeDeadline ? self::ERASE : self::WAIT;
    }
}
