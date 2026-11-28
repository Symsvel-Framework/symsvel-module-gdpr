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
