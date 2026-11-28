<?php

declare(strict_types=1);

namespace SymSvel\Module\Gdpr\Retention;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Claim before sending. Only a confirmed send authorizes a later candidate.
 * An interrupted or ambiguous send remains pending; never silently retry it.
 */
final readonly class InactivityNoticeRepository
{
    public function __construct(private Connection $db)
    {
    }

    /** @return array{attempt: string, claimed_at: \DateTimeImmutable, sent_at: ?\DateTimeImmutable}|null */
    public function find(int $userId, string $cycle): ?array
    {
        $this->validate($userId, $cycle);
        $row = $this->db->fetchAssociative('SELECT attempt, claimed_at, sent_at FROM symsvel_gdpr_inactivity_notices WHERE user_id = ? AND cycle_hash = ?', [$userId, $cycle]);
        if ($row === false) {
            return null;
        }

        return ['attempt' => $row['attempt'], 'claimed_at' => new \DateTimeImmutable($row['claimed_at'], new \DateTimeZone('UTC')),
            'sent_at' => $row['sent_at'] === null ? null : new \DateTimeImmutable($row['sent_at'], new \DateTimeZone('UTC'))];
    }

    public function claim(int $userId, string $cycle, \DateTimeImmutable $now): ?string
    {
        $this->validate($userId, $cycle);
        $this->requireAutocommit();
        $attempt = bin2hex(random_bytes(16));
        try {
            $this->db->insert('symsvel_gdpr_inactivity_notices', ['user_id' => $userId, 'cycle_hash' => $cycle,
                'attempt' => $attempt, 'claimed_at' => self::stamp($now), 'sent_at' => null]);

            return $attempt;
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /** Call only after Mails has confirmed successful acceptance by its transport. */
    public function confirm(int $userId, string $cycle, string $attempt, \DateTimeImmutable $sentAt): bool
    {
        $this->validate($userId, $cycle, $attempt);
        $this->requireAutocommit();

        return $this->db->executeStatement(
            'UPDATE symsvel_gdpr_inactivity_notices SET sent_at = ? WHERE user_id = ? AND cycle_hash = ? AND attempt = ? AND sent_at IS NULL AND claimed_at <= ?',
            [self::stamp($sentAt), $userId, $cycle, $attempt, self::stamp($sentAt)],
        ) === 1;
    }

    /** Release ONLY when the transport definitely did not accept the message. */
    public function releaseFailed(int $userId, string $cycle, string $attempt): bool
    {
        $this->validate($userId, $cycle, $attempt);
        $this->requireAutocommit();

        return $this->db->executeStatement('DELETE FROM symsvel_gdpr_inactivity_notices WHERE user_id = ? AND cycle_hash = ? AND attempt = ? AND sent_at IS NULL',
            [$userId, $cycle, $attempt]) === 1;
    }

    /** Owner calls after completing account erasure in the shared transaction. */
    public function removeForUser(int $userId): int
    {
        if ($userId < 1) {
            throw new \InvalidArgumentException('Invalid user ID.');
        }

        return $this->db->delete('symsvel_gdpr_inactivity_notices', ['user_id' => $userId]);
    }

    private function validate(int $userId, string $cycle, ?string $attempt = null): void
    {
        if ($userId < 1 || !preg_match('/^[a-f0-9]{64}$/D', $cycle)
            || ($attempt !== null && !preg_match('/^[a-f0-9]{32}$/D', $attempt))) {
            throw new \InvalidArgumentException('Invalid inactivity notice key.');
        }
    }

    private function requireAutocommit(): void
    {
        if ($this->db->isTransactionActive()) {
            throw new \LogicException('Notice claim and delivery result require independent commits.');
        }
    }

    private static function stamp(\DateTimeImmutable $date): string
    {
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
