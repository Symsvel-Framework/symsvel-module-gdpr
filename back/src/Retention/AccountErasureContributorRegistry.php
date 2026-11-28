<?php

declare(strict_types=1);

namespace SymSvel\Module\Gdpr\Retention;

use Doctrine\DBAL\Connection;

final readonly class AccountErasureContributorRegistry
{
    /** @var array<string, AccountErasureContributor> */
    private array $contributors;

    /** @param iterable<AccountErasureContributor> $contributors */
    public function __construct(private Connection $db, iterable $contributors)
    {
        $registered = [];
        foreach ($contributors as $contributor) {
            if (!$contributor instanceof AccountErasureContributor) {
                throw new \InvalidArgumentException('Invalid account erasure contributor.');
            }
            $id = $contributor->moduleId();
            if (!preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D', $id) || isset($registered[$id])) {
                throw new \LogicException('Invalid or duplicate erasure contributor.');
            }
            $registered[$id] = $contributor;
        }
        ksort($registered, SORT_STRING);
        $this->contributors = $registered;
    }

    public function treat(int $userId, string $mode, \DateTimeImmutable $now): void
    {
        if ($userId < 1 || !in_array($mode, [PurgePolicy::ANONYMIZE, PurgePolicy::DELETE], true) || !$this->db->isTransactionActive()) {
            throw new \LogicException('Contributor requires an authorized account transaction.');
        }
        foreach ($this->contributors as $contributor) {
            $contributor->treat($userId, $mode, $now);
        }
    }
}
