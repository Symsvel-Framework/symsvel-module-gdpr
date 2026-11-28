<?php

declare(strict_types=1);

namespace SymSvel\Module\Gdpr\Retention;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Module-owned declaration: its presence means that physical deletion
 * of native User accounts must be disabled while the module is active.
 * Register only when the requiring feature is active in the runtime.
 */
#[AutoconfigureTag('symsvel.gdpr.anonymization_requirement')]
interface AnonymizationRequirement
{
    public function moduleId(): string;
}
