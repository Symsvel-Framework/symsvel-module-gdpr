<?php

declare(strict_types=1);

namespace SymSvel\Module\Gdpr\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\TestCase;
use SymSvel\Module\Gdpr\Consent\ConsentPolicy;
use SymSvel\Module\Gdpr\Consent\ConsentRepository;
use SymSvel\Module\Gdpr\Consent\ConsentSnapshot;

final class ConsentRepositoryTest extends TestCase
{
    private Connection $db;
    private ConsentRepository $repository;

    protected function setUp(): void
    {
        self::assertTrue(extension_loaded('pdo_sqlite'));
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        require_once dirname(__DIR__, 3).'/Migration/Version20261127143001.php';
        $schema = new Schema();
        $migration = (new \ReflectionClass(\DoctrineMigrations\Version20261127143001::class))->newInstanceWithoutConstructor();
        $migration->up($schema);
        foreach ($schema->toSql($this->db->getDatabasePlatform()) as $sql) {
            $this->db->executeStatement($sql);
        }
        $this->repository = new ConsentRepository($this->db);
    }

    protected function tearDown(): void
    {
        $this->db->close();
    }

    private function snapshot(string $scope, bool $granted, string $version): ConsentSnapshot
    {
        return new ConsentSnapshot($scope, str_repeat($version, 32),
            ['tracker-example' => ['revision' => 2, 'granted' => $granted]],
            new \DateTimeImmutable('2026-11-27T14:00:00Z'),
            new \DateTimeImmutable('2027-05-27T14:00:00Z'));
    }

    public function testStoresOnlyHashedVisitorAndSeparatesScopes(): void
    {
        $token = str_repeat('a', 64);
        self::assertNull($this->repository->find($token, 'front'));
        self::assertTrue($this->repository->replace($token, $this->snapshot('front', true, '1'), null));
        self::assertSame(hash('sha256', $token), $this->db->fetchOne('SELECT visitor_hash FROM symsvel_gdpr_consents'));
        self::assertNull($this->repository->find($token, 'app'));
        self::assertNull($this->repository->find(str_repeat('b', 64), 'front'));
        self::assertTrue($this->repository->replace($token, $this->snapshot('app', false, '2'), null));
        $snapshot = $this->repository->find($token, 'front');
        self::assertTrue($snapshot->decision('tracker-example')->granted);
        self::assertFalse($this->repository->find($token, 'app')->decision('tracker-example')->granted);
        self::assertNull($snapshot->decision('unknown'));
    }

    public function testWithdrawalCannotBeOverwrittenByAnObsoleteSelection(): void
    {
        $token = str_repeat('a', 64);
        $initial = $this->snapshot('front', true, '1');
        $withdrawal = $this->snapshot('front', false, '2');
        self::assertTrue($this->repository->replace($token, $initial, null));
        self::assertFalse($this->repository->replace($token, $initial, null));
        self::assertTrue($this->repository->replace($token, $withdrawal, $initial->version));
        self::assertFalse($this->repository->replace($token, $this->snapshot('front', true, '3'), $initial->version));
        $current = $this->repository->find($token, 'front');
        self::assertSame($withdrawal->version, $current->version);
        self::assertFalse((new ConsentPolicy())->permits(true, 'front', 'tracker-example', 2,
            $current->decidedAt, $current->decision('tracker-example')));
    }

    public function testExpirationAndCleanup(): void
    {
        $token = str_repeat('a', 64);
        $snapshot = $this->snapshot('front', true, '1');
        self::assertTrue($this->repository->replace($token, $snapshot, null));
        self::assertSame(0, $this->repository->removeExpired($snapshot->expiresAt->modify('-1 second')));
        self::assertSame(1, $this->repository->removeExpired($snapshot->expiresAt));
        self::assertNull($this->repository->find($token, 'front'));
        self::assertFalse($this->repository->replace($token, $this->snapshot('front', true, '2'), $snapshot->version));
    }

    public function testRejectsBoScope(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->repository->find(str_repeat('a', 64), 'bo');
    }

    public function testRejectsMalformedVisitorToken(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->repository->find('arbitrary', 'front');
    }
}
