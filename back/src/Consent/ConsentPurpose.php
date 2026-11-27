<?php

declare(strict_types=1);

namespace SymSvel\Module\Gdpr\Consent;

/** Public information for an optional, explicitly consented purpose. */
final readonly class ConsentPurpose
{
    public function __construct(
        public string $subject,
        public int $revision,
        public string $name,
        public string $purpose,
        public string $description,
        public string $privacyUrl,
    ) {
        if (!preg_match('/^[a-z][a-z0-9_-]{0,149}$/D', $subject)
            || $revision < 1 || $revision > 2147483647
            || trim($name) === '' || trim($purpose) === '' || trim($description) === ''
            || !filter_var($privacyUrl, FILTER_VALIDATE_URL)
            || strtolower((string) parse_url($privacyUrl, PHP_URL_SCHEME)) !== 'https'
            || parse_url($privacyUrl, PHP_URL_USER) !== null
            || parse_url($privacyUrl, PHP_URL_PASS) !== null) {
            throw new \InvalidArgumentException('Invalid consent purpose.');
        }
    }
}
