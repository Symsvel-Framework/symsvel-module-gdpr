<?php

declare(strict_types=1);

namespace SymSvel\Module\Gdpr\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use SymSvel\Module\Gdpr\Consent\BrowserConsentToken;
use SymSvel\Module\Gdpr\Consent\ConsentCreationLimiter;
use SymSvel\Module\Gdpr\Consent\ConsentSelection;
use SymSvel\Module\Gdpr\Consent\TrustedConsentOrigin;
use SymSvel\Module\Gdpr\Settings\GdprConfiguration;

/** Public, non-account consent API. Credentials are not cookies or account tokens. */
final readonly class ConsentController
{
    public function __construct(
        private ConsentSelection $selection,
        private BrowserConsentToken $tokens,
        private TrustedConsentOrigin $origins,
        private ConsentCreationLimiter $limiter,
        private GdprConfiguration $configuration,
    ) {
    }

    #[Route('/apiopen/gdpr/{scope<app|front>}/consent/state', name: 'symsvel_gdpr_consent_state', methods: ['POST'], format: 'json')]
    public function state(Request $request, string $scope): JsonResponse
    {
        if (!$this->trusted($request, $scope)) {
            return self::error('consent_origin_denied', 403);
        }
        try {
            $visitor = $this->visitor($request, $scope);
        } catch (\InvalidArgumentException) {
            return self::error('invalid_consent_credential', 401);
        }
        try {
            return self::reply(['data' => $this->selection->state($scope, $visitor, $this->configuration->enabled(), self::now())]);
        } catch (\Exception) {
            return self::error('consent_unavailable', 503);
        }
    }

    #[Route('/apiopen/gdpr/{scope<app|front>}/consent', name: 'symsvel_gdpr_consent_save', methods: ['PUT'], format: 'json')]
    public function save(Request $request, string $scope): JsonResponse
    {
        if (!$this->trusted($request, $scope)) {
            return self::error('consent_origin_denied', 403);
        }
        try {
            $visitor = $this->visitor($request, $scope);
        } catch (\InvalidArgumentException) {
            return self::error('invalid_consent_credential', 401);
        }
        if (!preg_match('~^application/json(?:\s*;|$)~i', $request->headers->get('Content-Type', ''))) {
            return self::error('unsupported_media_type', 415);
        }
        if (strlen($request->getContent()) > 65536) {
            return self::error('invalid_consent_selection', 400);
        }
        try {
            $body = json_decode($request->getContent(), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return self::error('invalid_consent_selection', 400);
        }
        if (!is_array($body) || count($body) !== 2 || !array_key_exists('version', $body)
            || !is_array($body['choices'] ?? null)
            || ($body['version'] !== null && (!is_string($body['version']) || !preg_match('/^[a-f0-9]{32}$/D', $body['version'])))
            || ($visitor === null && $body['version'] !== null)) {
            return self::error('invalid_consent_selection', 400);
        }
        $now = self::now();
        try {
            if (!$this->configuration->enabled()) {
                return self::error('consent_disabled', 409);
            }
            $months = $this->configuration->consentValidityMonths();
            // No record or browser credential is created by a simple state read.
            if ($visitor === null && $body['choices'] === []) {
                return self::error('invalid_consent_selection', 400);
            }
            if ($body['version'] === null && !$this->limiter->allow((string) $request->getClientIp(), $now)) {
                $response = self::error('consent_rate_limited', 429);
                $response->headers->set('Retry-After', '1');

                return $response;
            }
            $credential = null;
            if ($visitor === null) {
                $credential = $this->tokens->issue($scope);
                $visitor = $this->tokens->verify($scope, $credential);
            }
            $this->selection->save($scope, $visitor, $body['version'], $body['choices'], true, $months, $now);
            $data = $this->selection->state($scope, $visitor, $this->configuration->enabled(), $now);
            if ($credential !== null) {
                $data['credential'] = $credential;
            }

            return self::reply(['data' => $data]);
        } catch (\DomainException) {
            return self::error('consent_conflict', 409);
        } catch (\InvalidArgumentException) {
            return self::error('invalid_consent_selection', 400);
        } catch (\Exception) {
            return self::error('consent_unavailable', 503);
        }
    }

    private function trusted(Request $request, string $scope): bool
    {
        $origin = $request->headers->get('Origin');
        return $this->origins->allows($scope, $origin)
            && ($request->isSecure() || str_starts_with((string) $origin, 'http://'));
    }

    private function visitor(Request $request, string $scope): ?string
    {
        $authorization = $request->headers->get('Authorization');
        if ($authorization === null) {
            return null;
        }
        if (!preg_match('/^Bearer ([a-f0-9]{64}\.[a-f0-9]{64})$/D', $authorization, $parts)) {
            throw new \InvalidArgumentException('Invalid credential.');
        }

        return $this->tokens->verify($scope, $parts[1]);
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    private static function error(string $code, int $status): JsonResponse
    {
        return self::reply(['error' => ['code' => $code]], $status);
    }

    private static function reply(array $data, int $status = 200): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
