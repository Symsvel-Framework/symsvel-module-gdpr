<?php

declare(strict_types=1);

namespace SymSvel\Module\Gdpr\Consent;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Bounds new records, not withdrawals or updates to an existing choice. */
final readonly class ConsentCreationLimiter
{
    public function __construct(
        private Connection $db,
        #[Autowire('%kernel.secret%')] private string $secret,
    ) {
        if (strlen($secret) < 16) {
            throw new \InvalidArgumentException('A strong application secret is required.');
        }
    }

    public function allow(string $ip, \DateTimeImmutable $now): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        // Use a different HMAC namespace from the browser credential.
        $second = $now->getTimestamp();
        $packed = inet_pton($ip);
        // Normalize IPv4-mapped IPv6 addresses as well.
        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10)."\xff\xff") {
            $packed = substr($packed, 12);
        }
        $hash = hash_hmac('sha256', "symsvel.gdpr.creation.v1\0".$packed, $this->secret);
        try {
            $this->db->insert('symsvel_gdpr_creation_limits', ['key_hash' => $hash, 'window_start' => $second]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    public function removeExpired(\DateTimeImmutable $now): int
    {
        return $this->db->executeStatement('DELETE FROM symsvel_gdpr_creation_limits WHERE window_start < ?', [
            $now->getTimestamp(),
        ]);
    }
}
