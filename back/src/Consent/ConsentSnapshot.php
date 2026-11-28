<?php

declare(strict_types=1);

namespace SymSvel\Module\Gdpr\Consent;

/** Latest complete selection, independent of the account and tracker modules. */
final readonly class ConsentSnapshot
{
    public \DateTimeImmutable $decidedAt;
    public \DateTimeImmutable $expiresAt;

    /** @param array<string, array{revision: int, granted: bool}> $choices */
    public function __construct(
        public string $scope,
        public string $version,
        public array $choices,
        \DateTimeImmutable $decidedAt,
        \DateTimeImmutable $expiresAt,
    ) {
        if (!in_array($scope, ['app', 'front'], true)
            || !preg_match('/^[a-f0-9]{32}$/D', $version)
            || $expiresAt <= $decidedAt || count($choices) > 250) {
            throw new \InvalidArgumentException('Invalid consent snapshot.');
        }
        $utc = new \DateTimeZone('UTC');
        $this->decidedAt = $decidedAt->setTimezone($utc);
        $this->expiresAt = $expiresAt->setTimezone($utc);
        foreach ($choices as $subject => $choice) {
            if (!is_string($subject) || !is_array($choice) || count($choice) !== 2
                || !is_int($choice['revision'] ?? null) || !is_bool($choice['granted'] ?? null)) {
                throw new \InvalidArgumentException('Invalid consent choice.');
            }
            new ConsentDecision($scope, $subject, $choice['revision'], $choice['granted'], $this->decidedAt, $this->expiresAt);
        }
    }

    public function decision(string $subject): ?ConsentDecision
    {
        $choice = $this->choices[$subject] ?? null;
        return $choice === null ? null : new ConsentDecision(
            $this->scope, $subject, $choice['revision'], $choice['granted'], $this->decidedAt, $this->expiresAt,
        );
    }
}
