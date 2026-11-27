# Gdpr

Documentation développeur.

## Traductions Svelte

Pour chaque scope Svelte du module (bo, app, front), placer les dictionnaires dans `<scope>/src/lib/translations/<lang>.ts` uniquement si des libellés sont nécessaires. Format obligatoire : `export default {"title":"Texte"} as const;` (objet JSON strict, pas de code TypeScript exécuté par Tooling). Clé publique : `<scope>.gdpr.<key>`. Tooling déploie les fichiers sous `<scope>/src/lib/translations/gdpr/<lang>.ts`, puis Languages persiste les clés après migrations. Le dossier initial est vide ; ne pas inventer de clés.

## État du chantier (27/11/2026)

Protection des données personnelles, gestion des consentements et cycle de vie des comptes.

Voir [ADR 0001](../adr/0001-architecture.md) pour le périmètre cible et les décisions à valider.

`Retention/InactivityPolicy` calcule une échéance calendaire UTC et retourne `wait`, `warn` ou `erase`. Le résultat erase désigne un candidat à vérifier et ne déclenche rien. Les tests couvrent les échéances, le préavis effectif et les dates incohérentes. Aucun Settings, cron, mail, migration ou effacement opérationnel n'est encore raccordé.

Validation : composer qa puis CI, intégration et crash-tests XT avant publication fonctionnelle.

## Arbitrages mainteneur (27/11/2026)

`InactivityPolicy::activityReference()` utilise `last_login ?? created_at`, y compris pour les comptes historiques ; le préavis complet reste obligatoire. `PurgePolicy` choisit anonymize par défaut et refuse delete si un module actif exige la neutralisation. La future clé Settings `gdpr.purge.locked` est calculée et non éditable ; le passage forcé vers anonymize devra être persisté afin de ne pas réactiver delete au déverrouillage. Ces classes pures ne raccordent pas encore le registre des modules, Settings, les API ou le cron. Voir ADR 0001 pour les garanties et limites.
