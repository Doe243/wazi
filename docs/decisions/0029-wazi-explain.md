# ADR-029 : `wazi explain`, expliquer une adresse sans rien exécuter

**Statut :** acceptée — complète l'ADR-006, l'ADR-009 et l'ADR-027

## Contexte
Quand une page ne répond pas comme prévu, le débutant ne sait pas où regarder : la route est-elle la bonne ? un middleware a-t-il refusé ? d'où vient cet argument ? L'ADR-006 a prévu un outil qui montre le trajet d'une requête, en ligne de commande uniquement.

### Analyse de menaces
- **Exécuter le code du projet pour l'expliquer** déclencherait ses effets : une écriture en base, un courriel, une session créée.
- **Montrer des valeurs** (réglages, session, jetons) les mettrait dans l'historique du terminal.
- **Être atteint par le web** donnerait à un visiteur la carte du site et de ses protections.

## Décision
1. **`wazi explain <adresse> [--method=GET]`** affiche trois choses : la route choisie et ses paramètres ; les étapes traversées, dans l'ordre (middlewares du noyau, routeur, middlewares de la route, puis ceux de toutes les routes, enfin le code) ; le code exécuté, ce que le conteneur fournit à son constructeur, et d'où vient chaque argument.
2. **Rien n'est exécuté.** La commande lit l'application construite par `app.php` et le code des classes (par réflexion). Aucun middleware, aucun contrôleur n'est appelé ni même fabriqué.
3. **`Router::find($méthode, $chemin)`** fait la recherche d'une route sans l'exécuter, et rend un `RouteMatch`. `Router::handle()` s'en sert : la console et le site cherchent une route par le même code.
4. **Les routes masquées sont signalées** : si l'adresse convient aussi à une route déclarée plus loin, la commande le dit, et rappelle que la première déclarée gagne.
5. Pour un middleware de Wazi, la commande dit ce qu'il fera de **cette** requête (« ne demande rien : GET ne fait que lire »). Pour un middleware du projet, elle cite la première phrase du commentaire de sa classe.
6. Quand aucune route ne convient, elle dit quelle réponse sera donnée (404, 405, adresse refusée par protection) et pourquoi.
7. L'adresse s'écrit avec ou sans barre initiale (`notes/42`) : sous Windows, Git Bash transforme en chemin de fichier ce qui commence par « / ».
8. `Kernel::middlewares()` et `Router::middlewares()` rendent leurs listes, en lecture.

## Pourquoi
- Lire sans exécuter rend l'outil sans danger : on peut expliquer `DELETE /compte` sans rien supprimer.
- Une seule recherche de route, partagée, garantit que l'explication dit vrai.
- Citer le commentaire des classes du projet récompense le développeur qui commente son code, et l'outil parle avec ses mots.

## Conséquences
- La commande ne peut pas dire ce qu'un middleware du projet **décidera** (laisser passer ou refuser) : cela dépend de la requête réelle, de la session, de la base. Elle montre le trajet prévu, pas son issue.
- Elle répond en partie à une question ouverte : une route masquée par une autre se voit, au moment où on l'explique. La détection dès la déclaration reste à décider.
- Elle vit dans `Wazi\Kernel\Command` : elle connaît à la fois la console, le routeur et les middlewares (ADR-027).
