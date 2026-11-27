<?php

declare(strict_types=1);

namespace SymSvel\Module\Gdpr\Consent;

/** Uses Setup's exact origin mapping, not the request Host or a derived domain. */
final readonly class TrustedConsentOrigin
{
    public function __construct(
        private ?string $appOrigin = null,
        private ?string $frontOrigin = null,
        private string $environment = 'prod',
    ) {
    }

    public function allows(string $scope, ?string $origin): bool
    {
        $expected = match ($scope) {
            'app' => $this->appOrigin ?? self::environmentValue('SYMSVEL_APP_ORIGIN'),
            'front' => $this->frontOrigin ?? self::environmentValue('SYMSVEL_FRONT_ORIGIN'),
            default => null,
        };
        if ($expected === null || $origin === null || !hash_equals($expected, $origin)
            || !preg_match('~^https?://(?:[a-zA-Z0-9.-]+|\[::1\])(?::[0-9]{1,5})?$~D', $expected)) {
            return false;
        }
        $parts = parse_url($expected);
        if ($parts === false || !isset($parts['host']) || (isset($parts['port']) && $parts['port'] < 1)) {
            return false;
        }
        if ($parts['scheme'] === 'https') {
            return true;
        }
        $host = strtolower($parts['host']);
        return $this->environment === 'dev'
            && (in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)
                || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')
                || str_ends_with($host, '.test'));
    }

    private static function environmentValue(string $key): ?string
    {
        $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
