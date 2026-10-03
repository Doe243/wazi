# ADR-013 : middlewares PSR-15 et en-têtes de sécurité par défaut

**Statut :** acceptée — point 4 (contenu de la politique CSP) remplacé par l'ADR-014

## Contexte
La version 0.2 introduit les middlewares. Deux décisions : comment une application déclare les siens, et quels en-têtes de sécurité portent les réponses ordinaires (jusqu'ici, seules les pages d'erreur en avaient). L'ADR-006 demande que toute protection soit active par défaut et que la retirer demande un geste explicite, nommé et local.

## Options envisagées

**Déclaration des middlewares**
- Une méthode `$app->add($middleware)` : familière, mais le `Kernel` devient modifiable après sa création, et l'ordre dépend de l'ordre des appels dispersés dans le fichier.
- Une liste passée au constructeur du `Kernel`.

**En-têtes de sécurité**
- Un middleware fourni, à ajouter soi-même : un débutant ou une IA ne l'ajoutera pas.
- Un middleware dans la liste par défaut : dès que l'utilisateur passe sa propre liste, il disparaît sans que personne s'en aperçoive.
- Un argument séparé du `Kernel`, actif par défaut, indépendant de la liste de l'utilisateur.

**Politique de sécurité de contenu (CSP) par défaut**
- Aucune : rien ne casse, mais aucune protection contre l'exécution d'un script injecté.
- Stricte (`default-src 'self'`) : protège, mais bloque les scripts écrits dans la page et ceux d'un CDN, sans que Wazi puisse l'expliquer (c'est le navigateur qui refuse).

## Décision
1. `Wazi\Middleware\Pipeline` fait traverser une requête par une liste de middlewares PSR-15, du plus extérieur au plus intérieur, puis par un gestionnaire final. Il n'accepte que des objets `MiddlewareInterface`.
2. Les middlewares de l'application se donnent au constructeur : `new Kernel(middlewares: [...])`.
3. `SecurityHeaders` est un argument à part du `Kernel`, actif par défaut et placé avant tous les autres. On le règle en passant son propre objet ; on le retire par `new Kernel(securityHeaders: null)`.
4. `SecurityHeaders` ajoute `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin` et une CSP : tout depuis le site lui-même, plus les styles écrits dans la page et les images `data:`.
5. Un en-tête déjà posé par le contrôleur n'est jamais remplacé : c'est l'exception locale, page par page.
6. Les pages d'erreur restent hors du pipeline et gardent leur propre politique, plus stricte.

## Pourquoi
- Une liste au constructeur se lit d'un coup d'œil, et l'ordre est celui de la liste.
- Séparer `SecurityHeaders` de la liste empêche de perdre la protection par accident ; `securityHeaders: null` est un geste nommé, qui se cherche dans un dépôt.
- La CSP est la seule défense qui tient encore quand une faille XSS existe déjà dans l'application, ce qui arrive vite avec du code généré.

## Conséquences
- **Friction assumée :** un `<script>` écrit dans la page, un `onclick="..."` ou un script chargé depuis un CDN ne s'exécute pas avec la politique par défaut. Le navigateur l'explique dans sa console, pas Wazi. La docblock de `SecurityHeaders` et la documentation devront le dire clairement ; c'est le point de cet ADR le plus susceptible d'être revu après les premiers retours.
- `'unsafe-inline'` est accepté pour les styles : le risque est faible et l'usage très répandu.
- Pas de `Strict-Transport-Security` par défaut : mal réglé, il rend un site inaccessible pendant des mois. À proposer plus tard comme option explicite.
- Les middlewares sont globaux. Un middleware propre à une route arrivera avec les contrôleurs, quand le `Kernel` pourra séparer « trouver la route » et « l'exécuter ».
- Un middleware ne voit pas les pages d'erreur fabriquées par `ErrorHandler`.
