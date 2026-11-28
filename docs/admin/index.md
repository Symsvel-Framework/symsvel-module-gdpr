# Gdpr

Documentation admin.

## Paramètres GDPR (chantier en cours)

La gestion GDPR et le traitement d'inactivité sont activés par défaut ; les durées initiales sont 12 mois d'inactivité, 7 jours de préavis et 6 mois de validité des choix. Le traitement nécessite le futur cron serveur. Le mode initial est anonymisation. La suppression physique doit être sélectionnée explicitement. Lorsque le verrou est actif, le mode est imposé et l'API refuse sa modification. Une fois le dernier module exigeant l'anonymisation retiré, le choix précédent n'est pas restauré. Les durées doivent être des entiers positifs dans les limites indiquées ; une configuration invalide bloque le traitement. Aucun effacement opérationnel n'est encore disponible.

## Nettoyage des consentements

La commande serveur `php bin/console symsvel:gdpr:cleanup-consents --env=prod` retire les choix expirés et les empreintes temporaires de limitation des créations. Programmer son exécution quotidienne via cron. Ce nettoyage ne traite pas les comptes Users. Il fonctionne aussi lorsque GDPR est désactivé. Les interfaces de choix et le chargement des traceurs restent à raccorder et valider.

## Comptes inactifs

La commande `php bin/console symsvel:gdpr:process --env=prod` est une simulation par défaut. Elle affiche les candidats au préavis ou au traitement, les tentatives ambiguës et les erreurs sans afficher les adresses. Pour autoriser une exécution manuelle, ajouter `--execute` ; `--batch-size=100` borne les lots. Ne planifier aucune exécution automatique avant validation du projet, du transport mail synchrone, des obligations de conservation et des CT. Une tentative de préavis ambiguë bloque le traitement ; ne jamais l'assimiler à une livraison confirmée. BO seul sans propriétaire Users : aucune action. Le verrou de commande est local à l'hôte ; les contrôles de cycle et de transaction restent obligatoires. Le raccordement du propriétaire natif est encore en chantier.

### Maintenance des préavis

`php bin/console symsvel:gdpr:process --cleanup-notices --env=prod` est une simulation ; ajouter `--execute` pour supprimer seulement les tentatives devenues obsolètes. Les préavis courants ambigus restent bloquants, même anciens : examiner les journaux du transport avant toute réconciliation. Une réservation obsolète récente est protégée pendant 24 h. Cette opération ne traite aucun compte et n'envoie aucun mail.

## Informations légales et droits

Avant publication, compléter les clés `app.gdpr.dataText`, `app.gdpr.cookiesText`, `app.gdpr.rightsText`, `app.gdpr.rightsEmail` et leurs équivalents Front pour chaque langue utilisée, dans Languages. Publier les traductions et reconstruire les Sveltes. La page `/privacy` reste accessible sans compte et sans traceur ; l'adresse doit être une boîte de contact réellement surveillée. Le lien de contact ouvre la messagerie du visiteur, sans confirmation de réception. Le responsable organise les demandes, la vérification de l'identité, les délais, les justificatifs de conservation et la réponse. Le module ne traite pas automatiquement une demande de droit.

En local, le Runtime résout l'origine API via le helper de l'interface, comme le branding. Pour un domaine personnalisé, configurer `PUBLIC_API_URL` au build. Ne pas désactiver le contrôle des origines pour corriger une erreur de préférences. Sur une installation dev existante, actualiser Front avant GDPR, puis reconstruire via la mise à jour du module.

## Préférences intégrées (28/11/2026)

Le layout hôte découvre `LayoutRuntime.svelte` et lui transmet son contenu ; le runtime GDPR reste unique et fournit le rendu des préférences via contexte Svelte et `ConsentOutlet.svelte`. L’App présente les choix dans Profil > Consentements (`?tab=consents`), la page publique `/privacy` offre le même formulaire ; le Front et les pages publiques App affichent les liens et préférences en pied de page, non fixes. Le bandeau de premier choix reste visible hors des écrans dédiés. Aucune double instance, aucun changement du stockage navigateur/scope, du retrait ou des contrôles Tracking. Ancien layout : point d’entrée Runtime conservé pour compatibilité ; mettre à jour les hôtes avant GDPR. Les utilisateurs existants conservent leurs choix.
