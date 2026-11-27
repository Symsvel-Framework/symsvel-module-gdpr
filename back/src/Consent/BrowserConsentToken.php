<?php

declare(strict_types=1);

namespace SymSvel\Module\Gdpr\Consent;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Dedicated, scope-bound credential; never an account authentication token. */
final readonly class BrowserConsentToken
{
    public function __construct(#[Autowire('%kernel.secret%')] private string $secret)
    {
        if (strlen($secret) < 16) {
            throw new \InvalidArgumentException('A strong application secret is required.');
        }
    }

    public function issue(string $scope): string
    {
        $this->assertScope($scope);
        $token = bin2hex(random_bytes(32));

        return $token.'.'.$this->signature($scope, $token);
    }

    /** Return the opaque identifier used by ConsentRepository, or reject. */
    public function verify(string $scope, string $credential): string
    {
        $this->assertScope($scope);
        if (!preg_match('/^([a-f0-9]{64})\.([a-f0-9]{64})$/D', $credential, $parts)
            || !hash_equals($this->signature($scope, $parts[1]), $parts[2])) {
            throw new \InvalidArgumentException('Invalid consent credential.');
        }

        return $parts[1];
    }

    private function signature(string $scope, string $token): string
    {
        return hash_hmac('sha256', "symsvel.gdpr.browser.v1\0".$scope."\0".$token, $this->secret);
    }

    private function assertScope(string $scope): void
    {
        if (!in_array($scope, ['app', 'front'], true)) {
            throw new \InvalidArgumentException('Invalid consent scope.');
        }
    }
}
