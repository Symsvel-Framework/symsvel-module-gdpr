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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use SymSvel\Module\Gdpr\Consent\BrowserConsentToken;
use SymSvel\Module\Gdpr\Consent\ConsentCatalogue;
use SymSvel\Module\Gdpr\Consent\ConsentCreationLimiter;
use SymSvel\Module\Gdpr\Consent\ConsentPolicy;
use SymSvel\Module\Gdpr\Consent\ConsentPurpose;
use SymSvel\Module\Gdpr\Consent\ConsentPurposeProvider;
use SymSvel\Module\Gdpr\Consent\ConsentRepository;
use SymSvel\Module\Gdpr\Consent\ConsentSelection;
use SymSvel\Module\Gdpr\Consent\TrustedConsentOrigin;
use SymSvel\Module\Gdpr\Controller\ConsentController;
use SymSvel\Module\Gdpr\Retention\AnonymizationRequirementRegistry;
use SymSvel\Module\Gdpr\Retention\PurgePolicy;
use SymSvel\Module\Gdpr\Settings\GdprConfiguration;
use SymSvel\Module\Gdpr\Settings\GdprSettingDefinitionProvider;
use SymSvel\Module\Settings\Entity\Setting;
use SymSvel\Module\Settings\Repository\SettingRepository;
use SymSvel\Module\Settings\Service\SettingManager;
use SymSvel\Module\Settings\Service\SettingRegistry;
use SymSvel\Module\Settings\Service\SettingSecretCipher;

final class ConsentControllerTest extends TestCase
{
    private EntityManager $em;
    private ConsentController $controller;
    private SettingManager $settings;
    private ConsentCreationLimiter $limiter;

    protected function setUp(): void
    {
        self::assertTrue(extension_loaded('pdo_sqlite'));
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $config = ORMSetup::createAttributeMetadataConfiguration(
            [dirname((new \ReflectionClass(Setting::class))->getFileName())], true,
        );
        $config->enableNativeLazyObjects(true);
        $this->em = new EntityManager($db, $config);
        (new SchemaTool($this->em))->createSchema([$this->em->getClassMetadata(Setting::class)]);
        $schema = new Schema();
        foreach (['Version20261127143001', 'Version20261127173001'] as $version) {
            require_once dirname(__DIR__, 3).'/Migration/'.$version.'.php';
            $migration = (new \ReflectionClass('DoctrineMigrations\\'.$version))->newInstanceWithoutConstructor();
            $migration->up($schema);
        }
        foreach ($schema->toSql($db->getDatabasePlatform()) as $sql) {
            $db->executeStatement($sql);
        }
        $managerRegistry = $this->createMock(ManagerRegistry::class);
        $managerRegistry->method('getManagerForClass')->with(Setting::class)->willReturn($this->em);
        $requirements = new AnonymizationRequirementRegistry([]);
        $this->settings = new SettingManager(
            new SettingRegistry([new GdprSettingDefinitionProvider($requirements)]),
            new SettingRepository($managerRegistry), $this->em, new SettingSecretCipher('gdpr-settings-test-secret'),
        );
        $provider = new class implements ConsentPurposeProvider {
            public function purposes(string $scope): iterable
            {
                if ($scope === 'front') {
                    yield new ConsentPurpose('tracker-example', 2, 'Example', 'Audience', 'Description', 'https://example.invalid/privacy');
                }
            }
        };
        $secret = str_repeat('a', 32);
        $this->limiter = new ConsentCreationLimiter($db, $secret);
        $this->controller = new ConsentController(
            new ConsentSelection(new ConsentCatalogue([$provider]), new ConsentRepository($db), new ConsentPolicy()),
            new BrowserConsentToken($secret), new TrustedConsentOrigin('https://app.example.test', 'https://www.example.test'),
            $this->limiter, new GdprConfiguration($this->settings, $requirements, new PurgePolicy()),
        );
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->close();
    }

    private function request(string $method, ?array $body = null, ?string $credential = null, string $origin = 'https://www.example.test', string $url = 'https://api.example.test'): Request
    {
        $headers = ['HTTP_ORIGIN' => $origin, 'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '192.0.2.10'];
        if ($credential !== null) {
            $headers['HTTP_AUTHORIZATION'] = 'Bearer '.$credential;
        }

        return Request::create($url.'/apiopen/gdpr/front/consent', $method, [], [], [], $headers,
            json_encode($body ?? new \stdClass(), JSON_THROW_ON_ERROR));
    }

    private function data(JsonResponse $response): array
    {
        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        return json_decode((string) $response->getContent(), true, 8, JSON_THROW_ON_ERROR)['data'];
    }

    public function testReadDoesNotTrackAndExplicitGrantAndWithdrawalArePersisted(): void
    {
        $state = $this->data($this->controller->state($this->request('POST'), 'front'));
        self::assertFalse($state['complete']);
        self::assertFalse($state['purposes'][0]['granted']);
        self::assertArrayNotHasKey('credential', $state);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM symsvel_gdpr_consents'));
        $input = ['version' => null, 'choices' => ['tracker-example' => ['revision' => 2, 'granted' => true]]];
        $grant = $this->data($this->controller->save($this->request('PUT', $input), 'front'));
        self::assertTrue($grant['purposes'][0]['granted']);
        self::assertNotEmpty($grant['credential']);
        $credential = $grant['credential'];
        self::assertTrue($this->data($this->controller->state($this->request('POST', credential: $credential), 'front'))['complete']);
        $withdrawal = ['version' => $grant['version'], 'choices' => ['tracker-example' => ['revision' => 2, 'granted' => false]]];
        $refused = $this->data($this->controller->save($this->request('PUT', $withdrawal, $credential), 'front'));
        self::assertFalse($refused['purposes'][0]['granted']);
        self::assertArrayNotHasKey('credential', $refused);
        self::assertSame(409, $this->controller->save($this->request('PUT', $withdrawal, $credential), 'front')->getStatusCode());
        $this->settings->set('gdpr.enabled', false);
        $this->settings->flush();
        self::assertFalse($this->data($this->controller->state($this->request('POST', credential: $credential), 'front'))['enabled']);
        self::assertSame(409, $this->controller->save($this->request('PUT', $withdrawal, $credential), 'front')->getStatusCode());
    }

    public function testForeignOriginsWrongScopesAndForgedCredentialsAreRejected(): void
    {
        self::assertSame(403, $this->controller->state($this->request('POST', origin: 'https://evil.example.test'), 'front')->getStatusCode());
        self::assertSame(403, $this->controller->state($this->request('POST'), 'app')->getStatusCode());
        self::assertSame(403, $this->controller->state($this->request('POST', url: 'http://api.example.test'), 'front')->getStatusCode());
        self::assertSame(401, $this->controller->state($this->request('POST', credential: str_repeat('a', 64).'.'.str_repeat('a', 64)), 'front')->getStatusCode());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM symsvel_gdpr_consents'));
    }

    public function testCreationQuotaIsSharedAcrossScopesAndDoesNotRetainRawIp(): void
    {
        $now = new \DateTimeImmutable('2026-11-27T17:30:00Z');
        self::assertTrue($this->limiter->allow('192.0.2.10', $now));
        self::assertFalse($this->limiter->allow('::ffff:192.0.2.10', $now));
        self::assertTrue($this->limiter->allow('192.0.2.11', $now));
        self::assertFalse($this->limiter->allow('invalid', $now));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', (string) $this->em->getConnection()->fetchOne('SELECT key_hash FROM symsvel_gdpr_creation_limits'));
        self::assertSame(2, $this->limiter->removeExpired($now->modify('+1 second')));
    }
}
