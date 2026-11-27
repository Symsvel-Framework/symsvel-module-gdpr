<?php

declare(strict_types=1);

namespace SymSvel\Module\Gdpr\Retention;

/** Pure policy; the caller supplies the current authoritative module requirements. */
final class PurgePolicy
{
    public const ANONYMIZE = 'anonymize';
    public const DELETE = 'delete';

    /** @param list<string> $requiringAnonymization Active module identifiers. */
    public function isLocked(array $requiringAnonymization): bool
    {
        return $requiringAnonymization !== [];
    }

    /** @param list<string> $requiringAnonymization */
    public function effectiveMode(?string $configuredMode, array $requiringAnonymization): string
    {
        if ($configuredMode !== null && !in_array($configuredMode, [self::ANONYMIZE, self::DELETE], true)) {
            throw new \InvalidArgumentException('Unknown purge mode.');
        }

        return $this->isLocked($requiringAnonymization) ? self::ANONYMIZE : ($configuredMode ?? self::ANONYMIZE);
    }

    /** @param list<string> $requiringAnonymization */
    public function validateSelection(string $requestedMode, array $requiringAnonymization): void
    {
        if ($this->effectiveMode($requestedMode, $requiringAnonymization) !== $requestedMode) {
            throw new \DomainException('Physical deletion is locked by an active module.');
        }
    }
}
