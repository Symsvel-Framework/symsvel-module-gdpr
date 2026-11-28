<?php

declare(strict_types=1);

namespace SymSvel\Module\Gdpr\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymSvel\Module\Gdpr\Consent\BrowserConsentToken;
use SymSvel\Module\Gdpr\Consent\TrustedConsentOrigin;

final class BrowserConsentSecurityTest extends TestCase
{
    public function testCredentialIsOpaqueUniqueAndScopeBound(): void
    {
        $tokens = new BrowserConsentToken(str_repeat('a', 32));
        $credential = $tokens->issue('front');
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}\.[a-f0-9]{64}$/D', $credential);
        self::assertNotSame($credential, $tokens->issue('front'));
        self::assertSame(explode('.', $credential)[0], $tokens->verify('front', $credential));
        foreach (['app', 'bo'] as $scope) {
            try {
                $tokens->verify($scope, $credential);
                self::fail('Wrong scope must be rejected.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        $tokens->verify('front', str_repeat('b', 64).'.'.explode('.', $credential)[1]);
    }

    public function testSecretRotationInvalidatesOldCredentials(): void
    {
        $credential = (new BrowserConsentToken(str_repeat('a', 32)))->issue('app');
        $this->expectException(\InvalidArgumentException::class);
        (new BrowserConsentToken(str_repeat('b', 32)))->verify('app', $credential);
    }

    public function testOnlyExactMappedOriginsAreAllowed(): void
    {
        $guard = new TrustedConsentOrigin('https://manager.example.test', 'https://www.example.test:8443');
        self::assertTrue($guard->allows('app', 'https://manager.example.test'));
        self::assertTrue($guard->allows('front', 'https://www.example.test:8443'));
        self::assertFalse($guard->allows('app', 'https://www.example.test:8443'));
        self::assertFalse($guard->allows('front', 'https://www.example.test'));
        self::assertFalse($guard->allows('front', 'https://www.example.test:8443/'));
        self::assertFalse($guard->allows('front', null));
        self::assertFalse($guard->allows('bo', 'https://manager.example.test'));
        self::assertFalse((new TrustedConsentOrigin('', ''))->allows('front', 'https://www.example.test'));
    }

    public function testHttpIsOnlyAllowedForReservedLocalHostsInDev(): void
    {
        foreach (['http://app.xt.local:5173', 'http://127.0.0.1:5173', 'http://[::1]:5173'] as $origin) {
            self::assertTrue((new TrustedConsentOrigin($origin, '', 'dev'))->allows('app', $origin));
            self::assertFalse((new TrustedConsentOrigin($origin, '', 'prod'))->allows('app', $origin));
        }
        self::assertFalse((new TrustedConsentOrigin('http://example.com', '', 'dev'))->allows('app', 'http://example.com'));
        self::assertFalse((new TrustedConsentOrigin('https://example.com/path', '', 'prod'))->allows('app', 'https://example.com/path'));
    }
}
