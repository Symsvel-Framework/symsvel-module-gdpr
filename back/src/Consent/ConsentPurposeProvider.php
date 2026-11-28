<?php

declare(strict_types=1);

namespace SymSvel\Module\Gdpr\Consent;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('symsvel.gdpr.consent_purpose_provider')]
interface ConsentPurposeProvider
{
    /** @return iterable<ConsentPurpose> Active optional purposes for app or front. */
    public function purposes(string $scope): iterable;
}
