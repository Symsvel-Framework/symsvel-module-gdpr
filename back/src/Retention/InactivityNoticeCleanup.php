<?php

declare(strict_types=1);

namespace SymSvel\Module\Gdpr\Retention;

use SymSvel\Module\Gdpr\Settings\GdprConfiguration;

/** Maintenance only: never sends mail and never authorizes account treatment. */
final readonly class InactivityNoticeCleanup
{
    public function __construct(
        private AccountRetentionOwnerRegistry $owners,
        private GdprConfiguration $configuration,
        private InactivityCycle $cycles,
        private InactivityNoticeRepository $notices,
    ) {}

    /** @return array{status:string,counts:array<string,int>} */
    public function run(\DateTimeImmutable $now, bool $execute = false, int $batchSize = 100, ?callable $onError = null): array
    {
        if ($batchSize < 1 || $batchSize > 1000) throw new \InvalidArgumentException('Invalid batch size.');
        $counts = array_fill_keys(['scanned', 'obsolete', 'removed', 'current', 'ambiguous', 'recent_pending', 'errors'], 0);
        $owner = $this->owners->owner();
        if (!$owner instanceof AccountRetentionInspector) return ['status' => 'no-inspector', 'counts' => $counts];
        if ($this->configuration->inactivityRules() === null) return ['status' => 'disabled', 'counts' => $counts];
        $max = $this->notices->maximumUserId();
        $after = 0;
        $cycle = '';
        $grace = $now->modify('-24 hours');
        while ($batch = $this->notices->batchKeys($after, $cycle, $max, $batchSize)) {
            foreach ($batch as $key) {
                $after = $key['user_id'];
                $cycle = $key['cycle_hash'];
                ++$counts['scanned'];
                try {
                    $owner->inspect($after, $execute, function (?InactivityCandidate $candidate) use ($key, $execute, $grace, &$counts): void {
                        $rules = $this->configuration->inactivityRules($execute);
                        if ($rules === null) throw new \DomainException('Retention policy disabled during cleanup.');
                        $notice = $this->notices->find($key['user_id'], $key['cycle_hash'], $execute);
                        if ($notice === null || $notice['attempt'] !== $key['attempt']) return;
                        if ($candidate !== null && $candidate->id !== $key['user_id']) {
                            throw new \LogicException('Unexpected inspected identity.');
                        }
                        $current = $candidate === null ? null : $this->cycles->fingerprint(
                            $candidate->id, $candidate->email, $candidate->activity,
                            $rules['months'], $rules['warningDays'], $rules['mode'],
                        );
                        $obsolete = $current === null || !hash_equals($current, $key['cycle_hash']);
                        if (!$obsolete) {
                            ++$counts[$notice['sent_at'] === null ? 'ambiguous' : 'current'];
                            return;
                        }
                        // Do not remove recently claimed rows, which may still
                        // be in an independent SMTP attempt on another host.
                        if ($notice['sent_at'] === null && $notice['claimed_at'] > $grace) {
                            ++$counts['recent_pending'];
                            return;
                        }
                        ++$counts['obsolete'];
                        if ($execute && $this->notices->removeAttempt($key['user_id'], $key['cycle_hash'], $key['attempt'])) {
                            ++$counts['removed'];
                        }
                    });
                } catch (\Throwable $failure) {
                    ++$counts['errors'];
                    if ($onError !== null) $onError($after, $failure::class);
                }
            }
        }
        return ['status' => 'complete', 'counts' => $counts];
    }
}
