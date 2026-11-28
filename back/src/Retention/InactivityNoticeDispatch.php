<?php

declare(strict_types=1);

namespace SymSvel\Module\Gdpr\Retention;

use SymSvel\Module\Gdpr\Consent\TrustedConsentOrigin;
use SymSvel\Module\Gdpr\Mail\InactivityNoticeRenderer;
use SymSvel\Module\Gdpr\Settings\GdprConfiguration;
use SymSvel\Module\Mails\Mail\ConfiguredMailSender;

/** Called only by the account-retention coordinator with a current owner-provided candidate. */
final readonly class InactivityNoticeDispatch
{
    public function __construct(
        private InactivityPolicy $policy,
        private InactivityCycle $cycles,
        private InactivityNoticeRepository $notices,
        private GdprConfiguration $configuration,
        private TrustedConsentOrigin $origins,
        private InactivityNoticeRenderer $renderer,
        private ConfiguredMailSender $sender,
    ) {
    }

    public function dispatch(int $id, string $email, ?string $language, \DateTimeImmutable $activity, \DateTimeImmutable $now): string
    {
        $rules = $this->configuration->inactivityRules();
        if ($rules === null) return 'disabled';
        $months = $rules['months'];
        $days = $rules['warningDays'];
        $mode = $rules['mode'];
        $cycle = $this->cycles->fingerprint($id, $email, $activity, $months, $days, $mode);
        $notice = $this->notices->find($id, $cycle);
        if ($notice !== null && $notice['sent_at'] === null) {
            return 'pending';
        }
        $decision = $this->policy->decide($activity, $now, $months, $days, $notice['sent_at'] ?? null);
        if ($decision !== InactivityPolicy::WARN) {
            return $decision;
        }
        // Render and validate all parameters before acquiring the durable claim.
        $origin = $this->origins->configuredOrigin('app');
        if ($origin === null) {
            return 'unconfigured';
        }
        $mail = $this->renderer->render($language, $days, $mode, $origin);
        $attempt = $this->notices->claim($id, $cycle, $now);
        if ($attempt === null) {
            return 'pending';
        }
        try {
            // The native Back routes Symfony SendEmailMessage synchronously.
            $this->sender->send($mail, $email);
        } catch (\Throwable) {
            // Transport outcome may be ambiguous. Never authorize erasure or
            // retry automatically when acceptance cannot be proved.
            return 'pending';
        }
        $confirmedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $this->notices->confirm($id, $cycle, $attempt, $confirmedAt) ? 'sent' : 'pending';
    }
}
