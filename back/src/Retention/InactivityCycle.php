<?php

declare(strict_types=1);

namespace SymSvel\Module\Gdpr\Retention;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Bind a notice to the specific mailbox, activity and effective policy. */
final readonly class InactivityCycle
{
    public function __construct(#[Autowire('%kernel.secret%')] private string $secret)
    {
        if (strlen($secret) < 16) {
            throw new \InvalidArgumentException('A strong application secret is required.');
        }
    }

    public function fingerprint(int $userId, string $email, \DateTimeImmutable $activity, int $months, int $warningDays, string $mode): string
    {
        $email = strtolower(trim($email));
        if ($userId < 1 || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || $months < 1 || $months > 120 || $warningDays < 1 || $warningDays > 30
            || !in_array($mode, [PurgePolicy::ANONYMIZE, PurgePolicy::DELETE], true)) {
            throw new \InvalidArgumentException('Invalid inactivity cycle.');
        }

        $data = json_encode([$userId, $email, $activity->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
            $months, $warningDays, $mode], JSON_THROW_ON_ERROR);

        return hash_hmac('sha256', "symsvel.gdpr.inactivity.v1\0".$data, $this->secret);
    }
}
