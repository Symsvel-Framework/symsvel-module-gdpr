<?php

declare(strict_types=1);

namespace SymSvel\Module\Gdpr\Settings;

use SymSvel\Module\Gdpr\Retention\AnonymizationRequirementRegistry;
use SymSvel\Module\Gdpr\Retention\PurgePolicy;
use SymSvel\Module\Settings\Service\SettingDefinition;
use SymSvel\Module\Settings\Service\SettingDefinitionProviderInterface;

final readonly class GdprSettingDefinitionProvider implements SettingDefinitionProviderInterface
{
    public function __construct(private AnonymizationRequirementRegistry $requirements)
    {
    }

    public function definitions(): iterable
    {
        $locked = $this->requirements->isLocked();

        yield new SettingDefinition('gdpr.enabled', 'Activer la gestion RGPD', group: 'gdpr',
            type: 'boolean', required: true, defaultValue: '1');
        yield new SettingDefinition('gdpr.inactivity_enabled', 'Traitement automatique des comptes inactifs', group: 'gdpr',
            type: 'boolean', required: true, defaultValue: '1',
            description: 'Le traitement nécessite également la configuration du cron serveur.');
        yield new SettingDefinition('gdpr.inactivity_months', 'Durée d’inactivité (mois)', group: 'gdpr',
            type: 'string', required: true, defaultValue: '12',
            description: 'Nombre entier de 1 à 120 mois.');
        yield new SettingDefinition('gdpr.inactivity_warning_days', 'Préavis avant traitement (jours)', group: 'gdpr',
            type: 'string', required: true, defaultValue: '7',
            description: 'Nombre entier de 1 à 30 jours ; le préavis effectif reste obligatoire.');
        yield new SettingDefinition('gdpr.consent_validity_months', 'Validité des choix de consentement (mois)', group: 'gdpr',
            type: 'string', required: true, defaultValue: '6',
            description: 'Nombre entier de 1 à 120 mois ; à adapter à la politique de confidentialité.');

        yield new SettingDefinition('gdpr.purge_locked', 'Suppression physique verrouillée', group: 'gdpr',
            type: 'boolean', required: true, editable: false, forcedValue: $locked ? '1' : '0',
            description: $locked
                ? 'Anonymisation obligatoire : '.implode(', ', $this->requirements->requiringAnonymization()).'.'
                : 'Aucun module actif n’impose actuellement la conservation de l’identifiant.');

        $choices = [PurgePolicy::ANONYMIZE => 'Anonymisation'];
        if (!$locked) {
            $choices[PurgePolicy::DELETE] = 'Suppression physique';
        }
        yield new SettingDefinition('gdpr.deletion_mode', 'Mode de suppression du compte', group: 'gdpr',
            type: 'choice', required: true, choices: $choices, editable: !$locked,
            defaultValue: PurgePolicy::ANONYMIZE, forcedValue: $locked ? PurgePolicy::ANONYMIZE : null);
    }
}
