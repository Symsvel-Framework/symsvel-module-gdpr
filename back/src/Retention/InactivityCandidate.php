<?php

declare(strict_types=1);

namespace SymSvel\Module\Gdpr\Retention;

final readonly class InactivityCandidate
{
    public function __construct(
        public int $id,
        public string $email,
        public ?string $language,
        public \DateTimeImmutable $activity,
    ) {
        if ($id < 1 || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Invalid retention candidate.');
        }
    }
}
