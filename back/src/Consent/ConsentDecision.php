<?php

declare(strict_types=1);

namespace SymSvel\Module\Gdpr\Consent;

/** An explicit choice for one version of one optional purpose in one scope. */
final readonly class ConsentDecision
{
    public \DateTimeImmutable $decidedAt;
    public \DateTimeImmutable $expiresAt;

    public function __construct(
        public string $scope,
        public string $subject,
        public int $revision,
        public bool $granted,
        \DateTimeImmutable $decidedAt,
        \DateTimeImmutable $expiresAt,
    ) {
        if (!in_array($scope, ['app', 'front'], true)
            || !preg_match('/^[a-z][a-z0-9_-]{0,149}$/D', $subject)
            || $revision < 1 || $revision > 2147483647
            || $expiresAt <= $decidedAt) {
            throw new \InvalidArgumentException('Invalid consent decision.');
        }
        $utc = new \DateTimeZone('UTC');
        $this->decidedAt = $decidedAt->setTimezone($utc);
        $this->expiresAt = $expiresAt->setTimezone($utc);
    }
}
