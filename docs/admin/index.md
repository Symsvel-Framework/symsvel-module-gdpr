# Gdpr

Documentation admin.

## Paramètres GDPR (chantier en cours)

La gestion GDPR et le traitement d'inactivité sont activés par défaut ; les durées initiales sont 12 mois d'inactivité, 7 jours de préavis et 6 mois de validité des choix. Le traitement nécessite le futur cron serveur. Le mode initial est anonymisation. La suppression physique doit être sélectionnée explicitement. Lorsque le verrou est actif, le mode est imposé et l'API refuse sa modification. Une fois le dernier module exigeant l'anonymisation retiré, le choix précédent n'est pas restauré. Les durées doivent être des entiers positifs dans les limites indiquées ; une configuration invalide bloque le traitement. Aucun effacement opérationnel n'est encore disponible.
