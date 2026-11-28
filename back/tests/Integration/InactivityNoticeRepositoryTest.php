<?php

declare(strict_types=1);

namespace SymSvel\Module\Gdpr\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\TestCase;
use SymSvel\Module\Gdpr\Retention\InactivityCycle;
use SymSvel\Module\Gdpr\Retention\InactivityNoticeRepository;
use SymSvel\Module\Gdpr\Retention\InactivityPolicy;

final class InactivityNoticeRepositoryTest extends TestCase
{
    public function testOnlyConfirmedNoticeOfCurrentCycleCanAuthorizeCandidate(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        try {
            require_once dirname(__DIR__, 3).'/Migration/Version20261128084501.php';
            $schema = new Schema();
            (new \ReflectionClass(\DoctrineMigrations\Version20261128084501::class))->newInstanceWithoutConstructor()->up($schema);
            foreach ($schema->toSql($db->getDatabasePlatform()) as $sql) {
                $db->executeStatement($sql);
            }
            $cycles = new InactivityCycle(str_repeat('a', 32));
            $activity = new \DateTimeImmutable('2025-11-20T10:00:00Z');
            $now = new \DateTimeImmutable('2026-11-28T10:00:00Z');
            $cycle = $cycles->fingerprint(42, 'user@example.test', $activity, 12, 7, 'anonymize');
            $repo = new InactivityNoticeRepository($db);
            $policy = new InactivityPolicy();
            self::assertNull($repo->find(42, $cycle));
            $attempt = $repo->claim(42, $cycle, $now);
            self::assertNotNull($attempt);
            self::assertNull($repo->claim(42, $cycle, $now));
            self::assertNull($repo->find(42, $cycle)['sent_at']);
            self::assertSame(InactivityPolicy::WARN, $policy->decide($activity, $now, 12, 7, $repo->find(42, $cycle)['sent_at']));
            self::assertFalse($repo->confirm(42, $cycle, str_repeat('b', 32), $now));
            self::assertFalse($repo->confirm(42, $cycle, $attempt, $now->modify('-1 second')));
            self::assertTrue($repo->releaseFailed(42, $cycle, $attempt));
            $retry = $repo->claim(42, $cycle, $now);
            self::assertNotNull($retry);
            self::assertNotSame($attempt, $retry);
            self::assertFalse($repo->confirm(42, $cycle, $attempt, $now));
            self::assertTrue($repo->confirm(42, $cycle, $retry, $now));
            self::assertFalse($repo->releaseFailed(42, $cycle, $retry));
            self::assertFalse($repo->confirm(42, $cycle, $retry, $now));
            $sent = $repo->find(42, $cycle)['sent_at'];
            self::assertSame(InactivityPolicy::WAIT, $policy->decide($activity, $now->modify('+6 days'), 12, 7, $sent));
            self::assertSame(InactivityPolicy::ERASE, $policy->decide($activity, $now->modify('+7 days'), 12, 7, $sent));
            foreach ([
                $cycles->fingerprint(42, 'changed@example.test', $activity, 12, 7, 'anonymize'),
                $cycles->fingerprint(42, 'user@example.test', $activity->modify('+1 day'), 12, 7, 'anonymize'),
                $cycles->fingerprint(42, 'user@example.test', $activity, 6, 7, 'anonymize'),
                $cycles->fingerprint(42, 'user@example.test', $activity, 12, 8, 'anonymize'),
                $cycles->fingerprint(42, 'user@example.test', $activity, 12, 7, 'delete'),
            ] as $changed) {
                self::assertNotSame($cycle, $changed);
                self::assertNull($repo->find(42, $changed));
            }
            self::assertSame(1, $repo->removeForUser(42));
            self::assertNull($repo->find(42, $cycle));
        } finally {
            $db->close();
        }
    }
}
