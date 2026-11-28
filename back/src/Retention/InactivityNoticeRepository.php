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
    public function find(int $userId, string $cycle, bool $lock = false): ?array
    {
        $this->validate($userId, $cycle);
        $sql = 'SELECT attempt, claimed_at, sent_at FROM symsvel_gdpr_inactivity_notices WHERE user_id = ? AND cycle_hash = ?';
        if ($lock) {
            if (!$this->db->isTransactionActive()) throw new \LogicException('Notice lock requires the owner transaction.');
            if ($this->db->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\SQLitePlatform) {
                $this->db->executeStatement('UPDATE symsvel_gdpr_inactivity_notices SET cycle_hash = cycle_hash WHERE user_id = ? AND cycle_hash = ?', [$userId, $cycle]);
            } else {
                $sql .= ' FOR UPDATE';
            }
        }
        $row = $this->db->fetchAssociative($sql, [$userId, $cycle]);
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

    public function maximumUserId(): int
    {
        return (int) $this->db->fetchOne('SELECT COALESCE(MAX(user_id), 0) FROM symsvel_gdpr_inactivity_notices');
    }

    /** @return list<array{user_id:int,cycle_hash:string,attempt:string}> */
    public function batchKeys(int $afterUser, string $afterCycle, int $maximumUser, int $limit): array
    {
        if ($afterUser < 0 || $maximumUser < 0 || $limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('Invalid notice scan bounds.');
        }
        $rows = $this->db->createQueryBuilder()
            ->select('user_id', 'cycle_hash', 'attempt')
            ->from('symsvel_gdpr_inactivity_notices')
            ->where('(user_id > :after OR (user_id = :after AND cycle_hash > :cycle)) AND user_id <= :maximum')
            ->setParameter('after', $afterUser, \Doctrine\DBAL\ParameterType::INTEGER)
            ->setParameter('cycle', $afterCycle)
            ->setParameter('maximum', $maximumUser, \Doctrine\DBAL\ParameterType::INTEGER)
            ->orderBy('user_id', 'ASC')->addOrderBy('cycle_hash', 'ASC')
            ->setMaxResults($limit)->executeQuery()->fetchAllAssociative();
        return array_map(static fn (array $r): array => [
            'user_id' => (int) $r['user_id'], 'cycle_hash' => $r['cycle_hash'], 'attempt' => $r['attempt'],
        ], $rows);
    }

    /** Only an obsolete, locked snapshot may be removed. */
    public function removeAttempt(int $userId, string $cycle, string $attempt): bool
    {
        $this->validate($userId, $cycle, $attempt);
        if (!$this->db->isTransactionActive()) {
            throw new \LogicException('Obsolete notice removal requires the owner transaction.');
        }
        return $this->db->delete('symsvel_gdpr_inactivity_notices', [
            'user_id' => $userId, 'cycle_hash' => $cycle, 'attempt' => $attempt,
        ]) === 1;
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
