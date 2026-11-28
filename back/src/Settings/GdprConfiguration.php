<?php

declare(strict_types=1);

namespace SymSvel\Module\Gdpr\Settings;

use SymSvel\Module\Gdpr\Retention\AnonymizationRequirementRegistry;
use SymSvel\Module\Gdpr\Retention\PurgePolicy;
use SymSvel\Module\Settings\Service\SettingManager;

/** Read the current module-owned policy, never trust a BO or client-supplied mode. */
final readonly class GdprConfiguration
{
    public function __construct(
        private SettingManager $settings,
        private AnonymizationRequirementRegistry $requirements,
        private PurgePolicy $purgePolicy,
    ) {
    }

    public function enabled(): bool
    {
        return $this->settings->get('gdpr.enabled') === '1';
    }

    public function inactivityEnabled(): bool
    {
        return $this->enabled() && $this->settings->get('gdpr.inactivity_enabled') === '1';
    }

    public function inactivityMonths(): int
    {
        return self::positiveInteger($this->settings->get('gdpr.inactivity_months'), 120);
    }

    public function warningDays(): int
    {
        return self::positiveInteger($this->settings->get('gdpr.inactivity_warning_days'), 30);
    }

    public function consentValidityMonths(): int
    {
        return self::positiveInteger($this->settings->get('gdpr.consent_validity_months'), 120);
    }

    public function deletionMode(): string
    {
        return $this->purgePolicy->effectiveMode(
            $this->settings->get('gdpr.deletion_mode'),
            $this->requirements->requiringAnonymization(),
        );
    }

    /** @return array{months:int,warningDays:int,mode:string}|null */
    public function inactivityRules(bool $lock = false): ?array
    {
        $values = $this->settings->currentValues([
            'gdpr.enabled', 'gdpr.inactivity_enabled', 'gdpr.inactivity_months',
            'gdpr.inactivity_warning_days', 'gdpr.deletion_mode',
        ], $lock);
        if ($values['gdpr.enabled'] !== '1' || $values['gdpr.inactivity_enabled'] !== '1') return null;
        return [
            'months' => self::positiveInteger($values['gdpr.inactivity_months'], 120),
            'warningDays' => self::positiveInteger($values['gdpr.inactivity_warning_days'], 30),
            'mode' => $this->purgePolicy->effectiveMode($values['gdpr.deletion_mode'], $this->requirements->requiringAnonymization()),
        ];
    }

    private static function positiveInteger(?string $value, int $max): int
    {
        if ($value === null || !preg_match('/^[1-9][0-9]{0,2}$/D', $value) || (int) $value > $max) {
            throw new \DomainException('Invalid GDPR duration: configure a valid positive integer.');
        }

        return (int) $value;
    }
}
