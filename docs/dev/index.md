# Gdpr

Documentation développeur.

## Traductions Svelte

Pour chaque scope Svelte du module (bo, app, front), placer les dictionnaires dans `<scope>/src/lib/translations/<lang>.ts` uniquement si des libellés sont nécessaires. Format obligatoire : `export default {"title":"Texte"} as const;` (objet JSON strict, pas de code TypeScript exécuté par Tooling). Clé publique : `<scope>.gdpr.<key>`. Tooling déploie les fichiers sous `<scope>/src/lib/translations/gdpr/<lang>.ts`, puis Languages persiste les clés après migrations. Le dossier initial est vide ; ne pas inventer de clés.

## État du chantier (27/11/2026)

Protection des données personnelles, gestion des consentements et cycle de vie des comptes.

Voir [ADR 0001](../adr/0001-architecture.md) pour le périmètre cible et les décisions à valider.

`Retention/InactivityPolicy` calcule une échéance calendaire UTC et retourne `wait`, `warn` ou `erase`. Le résultat erase désigne un candidat à vérifier et ne déclenche rien. Les tests couvrent les échéances, le préavis effectif et les dates incohérentes. Le raccordement Settings est décrit ci-dessous. Aucun cron, mail, migration ou effacement opérationnel n'est encore raccordé.

Validation : composer qa puis CI, intégration et crash-tests XT avant publication fonctionnelle.

## Arbitrages mainteneur (27/11/2026)

`InactivityPolicy::activityReference()` utilise `last_login ?? created_at`, y compris pour les comptes historiques ; le préavis complet reste obligatoire. `PurgePolicy` choisit anonymize par défaut et refuse delete si un module actif exige la neutralisation. La clé Settings `gdpr.purge_locked` est calculée et non éditable ; le passage forcé vers anonymize devra être persisté afin de ne pas réactiver delete au déverrouillage. Ces classes pures ne déclenchent ni API ni cron. Voir ADR 0001 pour les garanties et limites.

## Déclaration d'anonymisation obligatoire

Un module actif dont les relations imposent de conserver l'identifiant User implémente `SymSvel\\Module\\Gdpr\\Retention\\AnonymizationRequirement` et enregistre son service Symfony avec le tag `symsvel.gdpr.anonymization_requirement` (autoconfiguration ou tag explicite). Le service doit retourner son identifiant canonique via `moduleId()` et ne doit pas lire les paramètres GDPR pendant la construction du registre. Le registre `AnonymizationRequirementRegistry` collecte les identifiants triés ; aucun contributeur = verrou inactif, un ou plusieurs = verrou actif. Toute déclaration invalide ou en double échoue explicitement. Le contrat de suppression des données métier par sujet reste distinct.

Le nom de clé est `gdpr.purge_locked`, équivalent de purge.locked dans la convention `module.parametre` (les clés à points imbriqués ne passent pas le qualificateur Settings). Le registre est câblé dans GdprBundle ; le provider projette le verrou dans Settings, dont la synchronisation persiste la valeur imposée et dont l'API refuse les écritures BO. Le registre représente les services du conteneur actif ; un réglage métier dynamique ne doit pas désarmer une contrainte de conservation encore applicable.

## Décision de consentement (27/11/2026)

`ConsentDecision` représente un choix explicite, positif ou négatif, pour un sujet de consentement identifié, sa révision et le scope App ou Front. Les dates sont normalisées en UTC ; une durée invalide est refusée. `ConsentPolicy::permits()` exige GDPR actif, une décision positive correspondant exactement au scope, au sujet et à la révision courante, et une date comprise dans l'intervalle [décision, expiration[. Une décision absente, périmée, future, négative ou d'une autre révision refuse tout chargement. Le retrait doit remplacer la décision courante par un refus ; le service pur ne sélectionne pas lui-même la dernière décision. `expiry()` calcule une échéance en mois calendaires, fin de mois plafonnée. Aucun cookie, stockage, identifiant visiteur ni endpoint n'est encore implémenté. Le futur service de persistance devra fournir une décision authentique et courante, sans accepter un consentement arbitraire transmis par le navigateur. Tests unitaires dédiés ; raccordement Tracking, Settings, preuve et retrait à suivre.

## Raccordement Settings (27/11/2026)

`GdprSettingDefinitionProvider` fournit enabled, inactivity_enabled, inactivity_months (12), inactivity_warning_days (7), consent_validity_months (6), purge_locked et deletion_mode (anonymize). Les deux premiers paramètres booléens sont actifs par défaut ; aucun traitement automatique ne démarre sans commande cron raccordée. Le verrou est une valeur imposée, toujours en lecture seule. Dès qu'un module actif exige l'anonymisation, deletion_mode est imposé à anonymize et le choix delete disparaît. Le cycle de synchronisation Settings persiste le choix imposé ; après déverrouillage, le choix delete doit être refait explicitement. `GdprConfiguration` lit les valeurs effectives et réapplique la politique de verrouillage au runtime. Les durées sont actuellement des paramètres texte ; la lecture métier refuse les valeurs non entières ou hors limites (1–120 mois et 1–30 jours), ce qui bloque le traitement concerné. Une validation de saisie plus spécifique reste à réaliser. Le texte multilingue de confidentialité reste à raccorder séparément.

Dépendance de chantier : Composer pointe sur `dev-dev` de Settings, qui contient désormais le merge de la PR #5 et fournit les métadonnées defaultValue/forcedValue. Cette contrainte doit être remplacée par une version distribuée compatible avant intégration au socle/release. Aucun changement du format des clés Settings. Tests unitaires du provider et tests de cycle de vie dans la PR Settings ; CT XT intermodules indispensable.

## Persistance des choix de consentement (27/11/2026)

Migration Schema Version20261127143001, table symsvel_gdpr_consents. ConsentRepository conserve la dernière sélection complète par jeton navigateur et scope App/Front : empreinte SHA-256 d'un jeton aléatoire de 256 bits, version aléatoire de 128 bits, choix par sujet/révision, dates UTC à la seconde. Aucun jeton brut, identifiant de compte, IP ou user-agent. Les écritures sont atomiques : création unique, remplacement conditionné à la version précédente, conflit refusé ; le retrait remplace les choix par un refus ou une sélection vide. Les dates d'expiration sont contrôlées par ConsentPolicy, et removeExpired supprime les lignes expirées (à raccorder au cron). Tests SQLite réels : séparation des scopes, hachage, concurrence logique, retrait, expiration. Le dépôt seul ne constitue pas un mécanisme de preuve complet : génération et transport sécurisé du jeton, validation du catalogue courant, API, UI, politique de conservation et CT quatre SGBD restent nécessaires. Aucune nouvelle requête tierce n'est possible à ce stade.
