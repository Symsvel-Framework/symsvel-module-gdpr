<?php

declare(strict_types=1);

namespace SymSvel\Module\Gdpr\Consent;

/** The latest authoritative choice wins. Missing or stale choices deny by default. */
final class ConsentPolicy
{
    public function permits(
        bool $gdprEnabled,
        string $scope,
        string $subject,
        int $revision,
        \DateTimeImmutable $now,
        ?ConsentDecision $decision,
    ): bool {
        return $gdprEnabled
            && $decision !== null
            && $decision->scope === $scope
            && $decision->subject === $subject
            && $decision->revision === $revision
            && $decision->granted
            && $decision->decidedAt <= $now
            && $now < $decision->expiresAt;
    }

    /**
     * Expiry is inclusive of the requested calendar day only until the precise
     * UTC time of expiry. Clamp end-of-month rather than overflow into next month.
     */
    public function expiry(\DateTimeImmutable $now, int $months): \DateTimeImmutable
    {
        if ($months < 1 || $months > 120) {
            throw new \InvalidArgumentException('Invalid consent validity period.');
        }
        $utc = $now->setTimezone(new \DateTimeZone('UTC'));
        $target = $utc->modify('first day of this month')->modify('+'.$months.' months');

        return $target->setDate((int) $target->format('Y'), (int) $target->format('n'),
            min((int) $utc->format('j'), (int) $target->format('t')));
    }
}
