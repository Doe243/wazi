# ADR-015 : contrôleurs en classes, arguments par nom et middlewares de route

**Statut :** acceptée — complète l'ADR-009 (point 7) et l'ADR-013

## Contexte
La version 0.2 doit permettre un contrôleur dont les dépendances sont injectées, protégé par un middleware. Jusqu'ici une route n'exécutait qu'une fonction, qui ne recevait que la requête (ADR-009, point 7), et les middlewares étaient globaux (ADR-013). Pour exécuter les middlewares d'une route, le routeur a besoin du `Pipeline`, qui vivait dans `Middleware` ; or deux composants de la couche Fonctionnalités ne peuvent pas dépendre l'un de l'autre.

## Options envisagées

**Où exécuter les middlewares d'une route**
- Dans le `Kernel`, le routeur se contentant de trouver la route : une route gardée appelée par le routeur seul s'exécuterait sans son garde.
- Dans le routeur, en autorisant `Routing` à dépendre de `Middleware` : casse la règle des couches.
- Dans le routeur, en descendant `Pipeline` dans la couche Fondations.

**Arguments du code d'une route**
- La requête seule, les paramètres se lisant par `getAttribute()` : explicite, mais non typé.
- Remplir les paramètres de la méthode par leur nom, comme Symfony et Laravel.

**Désigner un contrôleur ou un middleware**
- Des objets déjà créés : simple, mais tous sont construits à chaque requête, avec leurs dépendances.
- Des noms de classes, fabriqués par le conteneur au moment voulu.

## Décision
1. `Pipeline` et `InvalidMiddlewareException` passent dans `Wazi\Http`. `Middleware` garde les middlewares concrets (`SecurityHeaders`, plus tard sessions et CSRF).
2. Le code d'une route est une fonction ou un tableau `[Classe::class, 'methode']`. Le contrôleur est fabriqué par le conteneur, seulement quand sa route est demandée.
3. Une route peut porter ses middlewares : `$router->get($chemin, $code, [RequireLogin::class])`. Ils s'exécutent dans le routeur, autour du code de la route.
4. Un middleware se déclare par un objet ou par le nom de sa classe, pour une route comme pour le `Kernel`. La forme est vérifiée à la déclaration ; la classe n'est chargée et fabriquée qu'à l'exécution.
5. Arguments du code d'une route, dans cet ordre : la requête pour un paramètre de ce type ; la valeur du paramètre de route de même nom ; sinon la valeur par défaut ; sinon une erreur. Aucun service n'est injecté dans une méthode : les services se demandent dans le constructeur.
6. Un écart de type entre la route et la méthode (`{id}` pour un `int $id`) donne une erreur qui montre la contrainte à écrire.
7. Les paramètres de route restent aussi des attributs de la requête.
8. Le `Kernel` expose son conteneur (`$app->container`) et le partage avec son routeur.
9. **Sécurité :** la classe et la méthode viennent de la déclaration de la route, jamais de la requête. Seule une méthode publique réellement écrite dans la classe est appelée (pas de `__call`). Un argument n'est jamais rempli depuis les attributs, l'adresse ou le formulaire.

## Pourquoi
- Un garde posé sur une route doit s'exécuter quel que soit l'appelant : seul le routeur peut le garantir.
- Descendre `Pipeline` est la réponse prévue par la règle des couches : une classe dont deux voisins ont besoin appartient à la couche du dessous.
- `show(ServerRequestInterface $request, int $id)` est typé, se lit seul, et prépare à ce que le débutant retrouvera ailleurs. La règle tient en quatre lignes, écrites dans la docblock de `RouteRunner`.
- Fabriquer à la demande évite de construire tous les contrôleurs et tous les gardes à chaque requête.

## Conséquences
- La réflexion entre dans le routeur (lecture des paramètres de la méthode). Elle est confinée à `RouteRunner`.
- Une erreur dans le nom d'un contrôleur n'apparaît qu'à la première visite de sa route, pas au démarrage. PHPStan la signale dès l'écriture, grâce au type `class-string` de `get()`, `post()`, etc.
- Le conteneur reçoit des noms de classes écrits dans le code de l'application. La règle « jamais de nom de classe issu d'une requête » reste tenue par construction : aucune valeur de la requête n'atteint `Container::get()`.
- Un middleware global désigné par son nom est fabriqué à la première requête, ce qui permet de régler le conteneur après avoir créé le `Kernel`.
