<?php

declare(strict_types=1);

namespace SymSvel\Module\Gdpr\Retention;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** A module treats only its own data and respects applicable retention obligations. */
#[AutoconfigureTag('symsvel.gdpr.account_erasure_contributor')]
interface AccountErasureContributor
{
    public function moduleId(): string;

    /** Shared transaction, no external effects; throw to veto or roll back. */
    public function treat(int $userId, string $mode, \DateTimeImmutable $now): void;
}
