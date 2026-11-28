<?php

declare(strict_types=1);

namespace SymSvel\Module\Gdpr\Mail;

use SymSvel\Module\Gdpr\Retention\PurgePolicy;
use SymSvel\Module\Mails\Mail\RegisteredMailRenderer;
use SymSvel\Module\Mails\Mail\RenderedMail;

final readonly class InactivityNoticeRenderer
{
    public function __construct(private RegisteredMailRenderer $renderer)
    {
    }

    public function render(?string $language, int $warningDays, string $mode, string $appOrigin): RenderedMail
    {
        $parts = parse_url($appOrigin);
        if ($warningDays < 1 || $warningDays > 30 || !in_array($mode, [PurgePolicy::DELETE, PurgePolicy::ANONYMIZE], true)
            || !is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || !filter_var($appOrigin, FILTER_VALIDATE_URL)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || !in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new \InvalidArgumentException('Invalid inactivity notice parameters.');
        }
        $host = strtolower($parts['host']);
        if (strtolower($parts['scheme']) === 'http' && !in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)
            && !str_ends_with($host, '.local') && !str_ends_with($host, '.test') && !str_ends_with($host, '.localhost')) {
            throw new \InvalidArgumentException('Inactivity notice login requires HTTPS.');
        }
        $locale = strtolower(substr(trim((string) $language), 0, 2));
        return $this->renderer->render('gdpr.app.inactivity_notice', $locale, [
            'warningDays' => $warningDays, 'mode' => $mode, 'loginUrl' => rtrim($appOrigin, '/').'/login',
        ]);
    }
}
