<?php

declare(strict_types=1);

namespace SymSvel\Module\Gdpr\Retention;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** Optional owner of native App identities; BO-only installations have none. */
#[AutoconfigureTag('symsvel.gdpr.account_retention_owner')]
interface AccountRetentionOwner
{
    public function maximumId(): int;

    /** @return list<InactivityCandidate> Strictly increasing IDs in the requested bounds. */
    public function batch(int $afterId, int $maximumId, \DateTimeImmutable $before, \DateTimeImmutable $now, int $limit): array;

    /**
     * Lock and recheck the identity, email, activity and absence of active sessions.
     * Invoke authorize inside the same transaction, before any irreversible change.
     * A false return or exception from authorize must roll back all mutations.
     *
     * @param callable(): bool $authorize
     */
    public function treat(InactivityCandidate $candidate, string $mode, \DateTimeImmutable $now, callable $authorize): bool;
}
