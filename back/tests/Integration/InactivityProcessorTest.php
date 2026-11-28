<?php

declare(strict_types=1);

namespace SymSvel\Module\Gdpr\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;
use SymSvel\Module\Gdpr\Consent\TrustedConsentOrigin;
use SymSvel\Module\Gdpr\Mail\GdprMailTemplateProvider;
use SymSvel\Module\Gdpr\Mail\InactivityNoticeRenderer;
use SymSvel\Module\Gdpr\Retention\AnonymizationRequirementRegistry;
use SymSvel\Module\Gdpr\Retention\InactivityCycle;
use SymSvel\Module\Gdpr\Retention\InactivityNoticeDispatch;
use SymSvel\Module\Gdpr\Retention\InactivityNoticeRepository;
use SymSvel\Module\Gdpr\Retention\InactivityPolicy;
use SymSvel\Module\Gdpr\Retention\PurgePolicy;
use SymSvel\Module\Gdpr\Settings\GdprConfiguration;
use SymSvel\Module\Gdpr\Settings\GdprSettingDefinitionProvider;
use SymSvel\Module\Mails\Mail\ConfiguredMailSender;
use SymSvel\Module\Mails\Mail\MailSender;
use SymSvel\Module\Mails\Mail\MailTemplateRegistry;
use SymSvel\Module\Mails\Mail\RegisteredMailRenderer;
use SymSvel\Module\Mails\Service\MailsSettingDefinitionProvider;
use SymSvel\Module\Settings\Entity\Setting;
use SymSvel\Module\Settings\Repository\SettingRepository;
use SymSvel\Module\Settings\Service\SettingManager;
use SymSvel\Module\Settings\Service\SettingRegistry;
use SymSvel\Module\Settings\Service\SettingSecretCipher;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

use SymSvel\Module\Gdpr\Retention\AccountRetentionOwner;
use SymSvel\Module\Gdpr\Retention\AccountRetentionOwnerRegistry;
use SymSvel\Module\Gdpr\Retention\AccountErasureContributor;
use SymSvel\Module\Gdpr\Retention\AccountErasureContributorRegistry;
use SymSvel\Module\Gdpr\Retention\InactivityCandidate;
use SymSvel\Module\Gdpr\Retention\InactivityProcessor;
use Doctrine\DBAL\Connection;

final class InactivityProcessorTest extends TestCase
{
    public function testDryRunWarningsTransactionalPolicyAndContributorRollback(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        try {
            $orm = ORMSetup::createAttributeMetadataConfiguration([dirname((new \ReflectionClass(Setting::class))->getFileName())], true);
            $orm->enableNativeLazyObjects(true);
            $em = new EntityManager($db, $orm);
            (new SchemaTool($em))->createSchema([$em->getClassMetadata(Setting::class)]);
            $registry = $this->createMock(ManagerRegistry::class);
            $registry->method('getManagerForClass')->with(Setting::class)->willReturn($em);
            $requirements = new AnonymizationRequirementRegistry([]);
            $definitions = new SettingRegistry([
                GdprSettingDefinitionProvider::class => new GdprSettingDefinitionProvider($requirements),
                MailsSettingDefinitionProvider::class => new MailsSettingDefinitionProvider(),
            ], [GdprSettingDefinitionProvider::class => 'gdpr', MailsSettingDefinitionProvider::class => 'mails']);
            self::assertNotNull($definitions->get('mails.sender_email'));
            self::assertNull($definitions->get('sender_email'));
            $settings = new SettingManager($definitions,
                new SettingRepository($registry), $em, new SettingSecretCipher('gdpr-dispatch-test-secret'));
            $settings->set('mails.sender_email', 'sender@example.test');
            $settings->set('mails.sender_name', 'Example');
            $settings->set('mails.transport', 'smtp');
            foreach (['gdpr.enabled' => true, 'gdpr.inactivity_enabled' => true,
                'gdpr.inactivity_months' => '12', 'gdpr.inactivity_warning_days' => '7',
                'gdpr.deletion_mode' => 'anonymize'] as $key => $value) $settings->set($key, $value);
            $settings->flush();
            require_once dirname(__DIR__, 3).'/Migration/Version20261128084501.php';
            $schema = new Schema();
            (new \ReflectionClass(\DoctrineMigrations\Version20261128084501::class))->newInstanceWithoutConstructor()->up($schema);
            foreach ($schema->toSql($db->getDatabasePlatform()) as $sql) $db->executeStatement($sql);
            $cycles = new InactivityCycle(str_repeat('a', 32));
            $notices = new InactivityNoticeRepository($db);
            $loader = new FilesystemLoader();
            $loader->addPath(dirname(__DIR__, 2).'/templates', 'Gdpr');
            $renderer = new InactivityNoticeRenderer(new RegisteredMailRenderer(new MailTemplateRegistry([new GdprMailTemplateProvider()]), new Environment($loader)));
            $sent = 0;
            $mailer = $this->createMock(MailerInterface::class);
            $mailer->method('send')->willReturnCallback(static function (RawMessage $message) use (&$sent): void {
                ++$sent;
                if ($sent === 2) throw new \RuntimeException('Ambiguous transport outcome.');
                self::assertInstanceOf(\Symfony\Component\Mime\Email::class, $message);
                self::assertStringContainsString('https://app.example.test/login', (string) $message->getTextBody());
            });
            $configuration = new GdprConfiguration($settings, $requirements, new PurgePolicy());
            $dispatch = new InactivityNoticeDispatch(new InactivityPolicy(), $cycles, $notices, $configuration,
                new TrustedConsentOrigin('https://app.example.test'), $renderer, new ConfiguredMailSender(new MailSender($mailer), $settings));
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $activity = $now->modify('-13 months');
            $db->executeStatement('CREATE TABLE fixture_users (id INT PRIMARY KEY, treated INT NOT NULL)');
            $db->executeStatement('CREATE TABLE fixture_business (user_id INT PRIMARY KEY)');
            foreach ([1, 2] as $id) {
                $db->insert('fixture_users', ['id' => $id, 'treated' => 0]);
                $db->insert('fixture_business', ['user_id' => $id]);
            }
            $owner = new class($db, $activity) implements AccountRetentionOwner {
                public bool $changePolicy = false;
                public function __construct(private Connection $db, private \DateTimeImmutable $activity) {}
                public function maximumId(): int { return 2; }
                public function batch(int $afterId, int $maximumId, \DateTimeImmutable $before, \DateTimeImmutable $now, int $limit): array
                {
                    $ids = $this->db->fetchFirstColumn('SELECT id FROM fixture_users WHERE treated = 0 AND id > ? AND id <= ? ORDER BY id', [$afterId, $maximumId]);
                    return array_map(fn ($id) => new InactivityCandidate((int) $id, $id == 1 ? 'person@example.test' : 'other@example.test', 'en', $this->activity), array_slice($ids, 0, $limit));
                }
                public function treat(InactivityCandidate $candidate, string $mode, \DateTimeImmutable $now, callable $authorize): bool
                {
                    return $this->db->transactional(function () use ($candidate, $authorize): bool {
                        if ($this->changePolicy) $this->db->update('symsvel_settings', ['value' => 'delete'], ['setting_key' => 'gdpr.deletion_mode']);
                        if (!$authorize()) throw new \DomainException('Policy changed.');
                        $this->db->update('fixture_users', ['treated' => 1], ['id' => $candidate->id]);
                        return true;
                    });
                }
            };
            $contributor = new class($db) implements AccountErasureContributor {
                public bool $fail = false;
                public function __construct(private Connection $db) {}
                public function moduleId(): string { return 'fixture-business'; }
                public function treat(int $userId, string $mode, \DateTimeImmutable $now): void
                {
                    self::verify($mode);
                    $this->db->delete('fixture_business', ['user_id' => $userId]);
                    if ($this->fail) throw new \RuntimeException('Contributor veto.');
                }
                private static function verify(string $mode): void {
                    if ($mode !== 'anonymize') throw new \LogicException('Unexpected mode.');
                }
            };
            $registry = new AccountErasureContributorRegistry($db, [$contributor]);
            $make = fn (array $owners) => new InactivityProcessor(new AccountRetentionOwnerRegistry($owners), $registry, $configuration, new InactivityPolicy(), $cycles, $notices, $dispatch);
            self::assertSame('no-owner', $make([])->run($now, true)['status']);
            $processor = $make([$owner]);
            $dry = $processor->run($now, false, 1);
            self::assertSame(2, $dry['counts']['warn']);
            self::assertSame(0, $sent);
            self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM symsvel_gdpr_inactivity_notices'));
            $execution = $processor->run($now, true, 1);
            self::assertSame(1, $execution['counts']['sent']);
            self::assertSame(1, $execution['counts']['pending']);
            self::assertSame(2, $sent);
            $later = $now->modify('+8 days');
            self::assertSame(1, $processor->run($later, false)['counts']['erase']);
            $owner->changePolicy = true;
            self::assertSame(1, $processor->run($later, true)['counts']['errors']);
            self::assertSame(2, (int) $db->fetchOne('SELECT COUNT(*) FROM fixture_business'));
            $owner->changePolicy = false;
            $contributor->fail = true;
            self::assertSame(1, $processor->run($later, true)['counts']['errors']);
            self::assertSame(2, (int) $db->fetchOne('SELECT COUNT(*) FROM fixture_business'));
            self::assertNotNull($notices->find(1, $cycles->fingerprint(1, 'person@example.test', $activity, 12, 7, 'anonymize')));
            $contributor->fail = false;
            $report = $processor->run($later, true);
            self::assertSame(1, $report['counts']['treated']);
            self::assertSame(1, $report['counts']['pending']);
            self::assertSame(0, $report['counts']['errors']);
            self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM fixture_business'));
            self::assertSame(1, (int) $db->fetchOne('SELECT treated FROM fixture_users WHERE id = 1'));
            self::assertSame(0, (int) $db->fetchOne('SELECT treated FROM fixture_users WHERE id = 2'));
            self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM symsvel_gdpr_inactivity_notices'));
            self::assertSame(2, $sent);
            $tester = new \Symfony\Component\Console\Tester\CommandTester(new \SymSvel\Module\Gdpr\Command\ProcessInactivityCommand($processor, sys_get_temp_dir()));
            self::assertSame(0, $tester->execute([]));
            self::assertStringContainsString('Dry run', $tester->getDisplay());
            self::assertSame(2, $tester->execute(['--execute' => true, '--dry-run' => true]));
            $settings->set('gdpr.enabled', false);
            $settings->flush();
            self::assertSame('disabled', $processor->run($later, true)['status']);
        } finally {
            $db->close();
        }
    }
}
