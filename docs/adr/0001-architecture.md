# ADR 0001 : gestion native des données personnelles

Statut : proposé, chantier ouvert (27/11/2026). Ce document décrit la cible, pas des fonctionnalités déjà actives.

## Responsabilités
GDPR est destiné au socle natif, installé y compris en BO seul, désactivable à chaud par Settings, non désinstallable une fois intégré au socle. App et Front restent facultatifs. Le module ne dépend pas obligatoirement de Users. Aucun changement de responsabilité pour Core, Back, CLI ou Tooling. Respecter les contrats de manifeste, de routes Flex, de migrations Doctrine Schema, de traductions et de documentation existants.

Settings : gdpr.enabled (true proposé), gdpr.inactivity_enabled (false tant que le responsable n'a pas validé la configuration et le cron), gdpr.inactivity_months (12), gdpr.inactivity_warning_days (7), gdpr.deletion_mode (delete/anonymize, défaut à valider). Les durées sont une politique de projet, pas des obligations universelles. Le texte multilingue de la page de confidentialité doit être configurable, sans HTML arbitraire non filtré. Réutiliser les providers Settings, Languages, ACLs et Mails. Désactiver GDPR masque les écrans et bloque les opérations/API concernées, conserve les données et ne désactive pas Auth ni les ACLs ; aucun traceur facultatif n'est autorisé en l'absence de consentement valide.

## Inactivité
Le traitement automatique vise Users/App dans le premier périmètre, jamais Admins ni ROLE_DEV. Une nouvelle migration Users devra enregistrer la dernière connexion réussie, incluant toutes les méthodes Auth. Pour un compte créé après activation du suivi et jamais connecté, utiliser created_at. Pour les comptes historiques sans historique fiable, ne pas interpréter NULL comme preuve d'inactivité : définir une reprise explicite et un délai de grâce. Ne pas effacer de compte sur la seule absence d'un last_login historique.

Une commande Symfony de production détenue par GDPR (nom proposé symsvel:gdpr:process) sera appelée quotidiennement par cron. Pas de worker obligatoire. Préavis envoyé par Mails, en langue utilisateur ; enregistrer la date effective de notification, réessayer les échecs, ne jamais supprimer sans préavis complet. Connexion ultérieure annule le cycle ; une réduction de durée ou un changement de politique impose une nouvelle évaluation sûre. L'envoi SMTP et la BDD ne sont pas atomiques : prévoir idempotence, reprises et signalement des envois ambigus, sans promettre une livraison exactement une fois. Verrou d'exécution, relecture du compte et vérification transactionnelle avant effacement, dry-run sans écriture ni envoi. Un compte déjà neutralisé est exclu. Une session active doit être prise en compte avant toute purge : politique à définir.

La brique InactivityPolicy introduite dans cette PR calcule en UTC une échéance calendaire avec jour de fin de mois plafonné et décide WAIT/WARN/ERASE à partir d'une date d'activité fiable et d'une notification effective du cycle courant. Elle ne lit ni BDD ni Settings et ne déclenche aucune opération. ERASE est une candidature, jamais une autorisation suffisante.

## Effacement intermodules
GDPR orchestre les règles des modules propriétaires, via services Symfony tagués (contrat à définir). Les actions envisagées sont suppression, neutralisation, conservation justifiée ou blocage. Chaque module gère ses tables et fichiers ; pas de scan SQL générique ni cascade aveugle. Le développeur doit déclarer les données concernées et leurs règles ; un contributeur obligatoire absent ou une règle inconnue bloque le traitement. Les données partagées ne sont pas supprimées par défaut. Préparer un plan explicite, vérifier les dépendances, exécuter avec les transactions existantes et prévoir une reprise sûre des effets filesystem/externes.

Users reste minimal : aucune colonne prénom/nom/adresse/téléphone ajoutée. Extensions de profil par tables filles appartenant à des modules autonomes, supprimées lorsqu'inutiles. Si une référence métier doit être conservée, garder l'ID Users, neutraliser l'email (domaine réservé .invalid proposé, unicité vérifiée), interdire définitivement la connexion et la réactivation, révoquer sessions et jetons, retirer les liens d'identité externe. Le hash doit rester compatible avec les contraintes Auth sans permettre de connexion. Réutiliser UserAdministration et TokenSessionRevoker::revokeWith après audit ; ne pas imbriquer naïvement les transactions DBAL. Le marqueur anonymized_at est proposé, pas encore implémenté.

Les factures émises sont des documents autonomes archivés (PDF privé, mentions historiques conservées, droits d'accès et échéances de conservation). Ne pas les reconstruire depuis le profil. Les modules comptables déclarent leurs obligations et relations. Tant qu'un document conservé permet de réidentifier un sujet, la neutralisation du profil ne vaut pas anonymisation globale irréversible. Le mode préféré delete peut être remplacé par la neutralisation si une référence doit rester, ou bloqué si nécessaire. Les fichiers personnels devenus inutiles sont réellement effacés.

## Consentements et droits
Consentements versionnés par finalité, fournisseur/configuration et scope selon le besoin, retrait aussi simple que l'octroi. Refus par défaut des traceurs facultatifs. Le module Tracking facultatif ne décide pas lui-même du consentement. Prévoir preuve minimale, conservation limitée, export sécurisé, demandes et suivi, politique multilingue et catalogue dynamique. Pas de promesse de conformité juridique automatique. Les droits d'accès/effacement ne sont pas limités aux comptes inactifs.

## Validation avant intégration au socle
- Examiner ADR Settings/ACLs/Auth et contrats du Tooling, cycle des comptes et extensions d'identité ; choisir stratégie de reprise et transaction.
- Settings et ACLs effectifs côté API ; BO seul et App/Front optionnels.
- Tests d'échéance, changement de politique, échecs/reprise SMTP, concurrence login/cron, suppression/neutralisation et révocation ; aucun compte historique purgé sans décision explicite.
- CI, CT XT sur PostgreSQL/MySQL/MariaDB/SQLite, documentation dev/admin/user et validation du mainteneur.
- Pas de promotion stable ni modification du Setup avant validation.
