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

final class InactivityNoticeDispatchTest extends TestCase
{
    public function testDispatchOnlyOnceAndNeverConfirmsAnAmbiguousDelivery(): void
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
            $dispatch = new InactivityNoticeDispatch(new InactivityPolicy(), $cycles, $notices, new GdprConfiguration($settings, $requirements, new PurgePolicy()),
                new TrustedConsentOrigin('https://app.example.test'), $renderer, new ConfiguredMailSender(new MailSender($mailer), $settings));
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $activity = $now->modify('-13 months');
            self::assertSame('wait', $dispatch->dispatch(1, 'person@example.test', 'en', $now->modify('-1 day'), $now));
            self::assertSame('sent', $dispatch->dispatch(1, 'person@example.test', 'en', $activity, $now));
            self::assertSame('wait', $dispatch->dispatch(1, 'person@example.test', 'en', $activity, $now->modify('+1 minute')));
            self::assertSame('pending', $dispatch->dispatch(2, 'other@example.test', 'fr', $activity, $now));
            self::assertSame('pending', $dispatch->dispatch(2, 'other@example.test', 'fr', $activity, $now->modify('+10 days')));
            self::assertSame(2, $sent);
            self::assertNotNull($notices->find(1, $cycles->fingerprint(1, 'person@example.test', $activity, 12, 7, 'anonymize'))['sent_at']);
            self::assertNull($notices->find(2, $cycles->fingerprint(2, 'other@example.test', $activity, 12, 7, 'anonymize'))['sent_at']);
            $settings->set('gdpr.enabled', false);
            $settings->flush();
            self::assertSame('disabled', $dispatch->dispatch(3, 'person@example.test', 'en', $activity, $now));
            self::assertSame(2, $sent);
        } finally {
            $db->close();
        }
    }
}
