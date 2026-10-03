# ADR-009 : le routeur parcourt une liste de routes, segment par segment

**Statut :** acceptée

## Contexte
Le routeur doit trouver, pour une requête, la fonction à exécuter. La question était ouverte : une liste parcourue simplement (lisible) ou un arbre compilé (rapide). S'y ajoute une contrainte de sécurité de l'ADR-006 : pas d'expression régulière libre dans les routes, à cause des attaques par expression coûteuse (ReDoS).

## Options envisagées
- **Arbre compilé ou grosse expression régulière combinée** (FastRoute, Symfony) : très rapide avec des milliers de routes, mais le code généré ne se lit pas, et il repose sur des expressions régulières.
- **Liste parcourue, une expression régulière par route** : simple, mais chaque route devient une expression à construire sans faille.
- **Liste parcourue, comparaison segment par segment** : le chemin est découpé sur les « / » ; un segment est soit un texte fixe, soit un paramètre qui l'occupe en entier.

## Décision
1. Le routeur garde ses routes dans une liste et prend la **première** qui correspond, dans l'ordre de déclaration.
2. Une route se compare à une adresse **segment par segment**, sans expression régulière construite à partir de la route.
3. Un paramètre s'écrit `{nom}` ou `{nom:contrainte}` et occupe un segment entier. Les contraintes forment une liste fermée (`any`, `int`, `slug`, `uuid`), chacune bornée en longueur.
4. Le chemin demandé est découpé puis décodé, segment par segment. Un segment qui, décodé, vaut `.` ou `..`, contient `/`, `\`, un caractère de contrôle ou des octets non UTF-8, ne correspond à aucune route (404).
5. `/articles` et `/articles/` sont deux adresses différentes. Une route GET répond aussi à HEAD.
6. Les paramètres sont déposés sur la requête comme attributs. Un paramètre ne remplace jamais un attribut déjà présent.
7. La fonction d'une route reçoit la requête et retourne une réponse ; rien n'est injecté par réflexion.

## Pourquoi
- L'algorithme entier tient en une boucle que l'on peut lire et suivre au débogueur (principes 1 et 5).
- Sans expression régulière issue des routes, le risque de ReDoS disparaît par construction, pas par vigilance.
- « La première route déclarée gagne » s'explique en une phrase.
- Le point 4 garantit qu'un contrôleur ne reçoit jamais de quoi remonter dans les dossiers, même s'il utilise un paramètre dans un chemin de fichier.

## Conséquences
- Un paramètre ne peut pas être collé à du texte (`/article-{id}.html` est refusé à la déclaration).
- L'ordre de déclaration compte : `/articles/nouveau` doit précéder `/articles/{slug}`. Le routeur ne détecte pas encore une route masquée par une autre.
- Le temps de recherche croît avec le nombre de routes. C'est sans effet pour les applications visées ; si un jour cela compte, un cache ou un index pourra s'ajouter par un nouvel ADR sans changer l'API.
- Pas de redirection automatique entre `/articles` et `/articles/`.
- L'injection des paramètres comme arguments du contrôleur viendra avec le conteneur (0.2).
