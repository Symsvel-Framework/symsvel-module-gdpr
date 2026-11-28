<?php

declare(strict_types=1);

namespace SymSvel\Module\Gdpr\Retention;

/** Optional companion to the owner for cleaning up obsolete notices. */
interface AccountRetentionInspector
{
    /**
     * Call the inspection callback exactly once with the current non-neutralized
     * identity, or null if absent. With lock=true, hold its row and the shared
     * transaction until the callback ends. With lock=false, do not write.
     *
     * @param callable(?InactivityCandidate): void $inspect
     */
    public function inspect(int $userId, bool $lock, callable $inspect): void;
}
