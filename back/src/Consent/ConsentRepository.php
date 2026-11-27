<?php

declare(strict_types=1);

namespace SymSvel\Module\Gdpr\Consent;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/** Latest browser selection per scope; raw visitor tokens are never persisted. */
final readonly class ConsentRepository
{
    public function __construct(private Connection $db)
    {
    }

    public function find(string $visitorToken, string $scope): ?ConsentSnapshot
    {
        $hash = self::visitorHash($visitorToken);
        self::assertScope($scope);
        $row = $this->db->fetchAssociative(
            'SELECT version, choices_json, decided_at, expires_at FROM symsvel_gdpr_consents WHERE visitor_hash = ? AND scope = ?',
            [$hash, $scope],
        );
        if ($row === false) {
            return null;
        }
        $choices = json_decode((string) $row['choices_json'], true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($choices)) {
            throw new \UnexpectedValueException('Invalid stored consent choices.');
        }
        $utc = new \DateTimeZone('UTC');
        return new ConsentSnapshot($scope, (string) $row['version'], $choices,
            new \DateTimeImmutable((string) $row['decided_at'], $utc),
            new \DateTimeImmutable((string) $row['expires_at'], $utc));
    }

    /**
     * The caller must validate the current catalogue, GDPR state and browser
     * identity before calling. An obsolete revision cannot overwrite a newer choice.
     * Call outside an existing transaction: the insert conflict is handled here.
     */
    public function replace(string $visitorToken, ConsentSnapshot $snapshot, ?string $expectedVersion): bool
    {
        $hash = self::visitorHash($visitorToken);
        if ($expectedVersion !== null && (!preg_match('/^[a-f0-9]{32}$/D', $expectedVersion)
            || $expectedVersion === $snapshot->version)) {
            throw new \InvalidArgumentException('Invalid expected consent version.');
        }
        if ($this->db->isTransactionActive()) {
            throw new \LogicException('Consent replacement requires an independent DBAL operation.');
        }
        $values = [
            'version' => $snapshot->version,
            'choices_json' => json_encode((object) $snapshot->choices, JSON_THROW_ON_ERROR),
            'decided_at' => $snapshot->decidedAt->format('Y-m-d H:i:s'),
            'expires_at' => $snapshot->expiresAt->format('Y-m-d H:i:s'),
        ];
        if ($expectedVersion === null) {
            try {
                $this->db->insert('symsvel_gdpr_consents', [
                    'visitor_hash' => $hash, 'scope' => $snapshot->scope, ...$values,
                ]);
                return true;
            } catch (UniqueConstraintViolationException) {
                return false;
            }
        }
        return $this->db->update('symsvel_gdpr_consents', $values, [
            'visitor_hash' => $hash, 'scope' => $snapshot->scope, 'version' => $expectedVersion,
        ]) === 1;
    }

    public function removeExpired(\DateTimeImmutable $now): int
    {
        return $this->db->executeStatement('DELETE FROM symsvel_gdpr_consents WHERE expires_at <= ?', [
            $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ]);
    }

    private static function visitorHash(string $token): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
            throw new \InvalidArgumentException('Invalid visitor token.');
        }
        return hash('sha256', $token);
    }

    private static function assertScope(string $scope): void
    {
        if (!in_array($scope, ['app', 'front'], true)) {
            throw new \InvalidArgumentException('Invalid consent scope.');
        }
    }
}
