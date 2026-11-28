<?php

declare(strict_types=1);

namespace SymSvel\Module\Gdpr\Retention;

use SymSvel\Module\Gdpr\Settings\GdprConfiguration;

/** Explicit bounded pass. No account mutation without a fresh transactional decision. */
final readonly class InactivityProcessor
{
    public function __construct(
        private AccountRetentionOwnerRegistry $owners,
        private AccountErasureContributorRegistry $contributors,
        private GdprConfiguration $configuration,
        private InactivityPolicy $policy,
        private InactivityCycle $cycles,
        private InactivityNoticeRepository $notices,
        private InactivityNoticeDispatch $dispatch,
    ) {}

    /**
     * @param (callable(int, string): void)|null $onError Receives only ID and exception type, no personal data.
     * @return array{status: string, counts: array<string, int>}
     */
    public function run(\DateTimeImmutable $now, bool $execute = false, int $batchSize = 100, ?callable $onError = null): array
    {
        if ($batchSize < 1 || $batchSize > 1000) throw new \InvalidArgumentException('Invalid retention batch size.');
        $counts = array_fill_keys(['scanned', 'wait', 'warn', 'erase', 'pending', 'sent', 'treated', 'unconfigured', 'errors'], 0);
        $rules = $this->configuration->inactivityRules();
        if ($rules === null) return ['status' => 'disabled', 'counts' => $counts];
        $owner = $this->owners->owner();
        if ($owner === null) return ['status' => 'no-owner', 'counts' => $counts];
        $now = $now->setTimezone(new \DateTimeZone('UTC'));
        // Scan a conservative whole-month upper bound; policy decides precisely.
        $before = $now->add(new \DateInterval('P'.$rules['warningDays'].'D'))
            ->modify('first day of this month')->setTime(0, 0)
            ->sub(new \DateInterval('P'.$rules['months'].'M'))->modify('first day of next month');
        $maximum = $owner->maximumId();
        if ($maximum < 0) throw new \LogicException('Invalid account scan maximum.');
        $after = 0;
        while ($after < $maximum) {
            $batch = $owner->batch($after, $maximum, $before, $now, $batchSize);
            if ($batch === []) break;
            if (count($batch) > $batchSize) throw new \LogicException('Account owner exceeded the batch bound.');
            foreach ($batch as $candidate) {
                if (!$candidate instanceof InactivityCandidate || $candidate->id <= $after || $candidate->id > $maximum) {
                    throw new \LogicException('Account owner returned an invalid candidate order.');
                }
                $after = $candidate->id;
                ++$counts['scanned'];
                try {
                    $rules = $this->configuration->inactivityRules();
                    if ($rules === null) return ['status' => 'disabled', 'counts' => $counts];
                    $cycle = $this->cycles->fingerprint($candidate->id, $candidate->email, $candidate->activity,
                        $rules['months'], $rules['warningDays'], $rules['mode']);
                    $notice = $this->notices->find($candidate->id, $cycle);
                    $decision = $notice !== null && $notice['sent_at'] === null ? 'pending'
                        : $this->policy->decide($candidate->activity, $now, $rules['months'], $rules['warningDays'], $notice['sent_at'] ?? null);
                    ++$counts[$decision];
                    if (!$execute) continue;
                    if ($decision === InactivityPolicy::WARN) {
                        $result = $this->dispatch->dispatch($candidate->id, $candidate->email, $candidate->language, $candidate->activity, $now);
                        if ($result === 'disabled') return ['status' => 'disabled', 'counts' => $counts];
                        if (in_array($result, ['sent', 'pending', 'unconfigured'], true)) ++$counts[$result];
                    } elseif ($decision === InactivityPolicy::ERASE) {
                        $authorized = false;
                        $treated = $owner->treat($candidate, $rules['mode'], $now, function () use ($candidate, $now, $rules, $cycle, &$authorized): bool {
                            if ($authorized) throw new \LogicException('Account authorization may only be consumed once.');
                            $current = $this->configuration->inactivityRules(true);
                            if ($current === null || $current !== $rules) return false;
                            $freshCycle = $this->cycles->fingerprint($candidate->id, $candidate->email, $candidate->activity,
                                $current['months'], $current['warningDays'], $current['mode']);
                            if (!hash_equals($cycle, $freshCycle)) return false;
                            $fresh = $this->notices->find($candidate->id, $cycle, true);
                            if ($fresh === null || $fresh['sent_at'] === null ||
                                $this->policy->decide($candidate->activity, $now, $current['months'], $current['warningDays'], $fresh['sent_at']) !== InactivityPolicy::ERASE) {
                                return false;
                            }
                            $this->contributors->treat($candidate->id, $current['mode'], $now);
                            $this->notices->removeForUser($candidate->id);
                            $authorized = true;
                            return true;
                        });
                        if (!$treated || !$authorized) throw new \DomainException('Account treatment was not authorized or completed.');
                        ++$counts['treated'];
                    }
                } catch (\Throwable $failure) {
                    ++$counts['errors'];
                    if ($onError !== null) $onError($candidate->id, $failure::class);
                }
            }
        }
        return ['status' => 'complete', 'counts' => $counts];
    }
}
