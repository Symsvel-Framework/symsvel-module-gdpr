<?php

declare(strict_types=1);

namespace SymSvel\Module\Gdpr\Retention;

final readonly class AccountRetentionOwnerRegistry
{
    private ?AccountRetentionOwner $owner;

    /** @param iterable<AccountRetentionOwner> $owners */
    public function __construct(iterable $owners)
    {
        $selected = null;
        foreach ($owners as $owner) {
            if (!$owner instanceof AccountRetentionOwner || $selected !== null) {
                throw new \LogicException('Invalid or duplicate App account retention owner.');
            }
            $selected = $owner;
        }
        $this->owner = $selected;
    }

    public function owner(): ?AccountRetentionOwner
    {
        return $this->owner;
    }
}
