# ADR-016 : routes par attributs, contrôleurs déclarés un par un

**Statut :** acceptée

## Contexte
Avec les contrôleurs en classes (ADR-015), une route se déclare loin de la méthode qu'elle exécute. Symfony et Laravel permettent de l'écrire à côté de la méthode, par un attribut. Reste à savoir comment le routeur apprend quels contrôleurs existent.

## Options envisagées

**Trouver les contrôleurs**
- Parcourir un dossier (`src/Controller/`) et charger chaque fichier : rien à déclarer, mais c'est de la magie (une classe posée dans un dossier devient accessible depuis le web), et charger des fichiers trouvés sur le disque est une surface d'attaque.
- Déclarer chaque contrôleur par une ligne.

**Forme de l'attribut**
- Un attribut unique `#[Route('/chemin', methods: ['GET'])]`, comme Symfony : familier, mais son nom se confond avec la classe `Wazi\Routing\Route` dans l'import automatique de l'IDE.
- Un attribut par méthode HTTP : `#[Get]`, `#[Post]`, `#[Put]`, `#[Patch]`, `#[Delete]`.

## Décision
1. Cinq attributs dans `Wazi\Routing\Attribute`, un par méthode HTTP, qui portent un chemin et une liste de middlewares. Ils se posent sur des méthodes et sont répétables.
2. `$router->addController(Classe::class)` lit les attributs des méthodes de la classe et déclare les routes correspondantes. Aucun dossier n'est parcouru.
3. Sont refusés à la déclaration : une classe introuvable, un contrôleur sans aucune route, un attribut sur une méthode non publique, un chemin invalide, une route en double.
4. Une méthode publique sans attribut n'est pas une route.
5. Les routes par attributs, par contrôleur et par fonction coexistent dans le même routeur.

## Pourquoi
- `#[Get('/articles')]` est le pendant exact de `$router->get('/articles', ...)` : une seule chose à retenir.
- Une ligne par contrôleur garde la trace de l'origine de chaque route (principe 1), et rien n'est exposé au web par le seul fait d'exister dans un dossier (principe 8).
- Refuser tôt transforme une faute d'import (`use` oublié, donc attribut non reconnu) en message clair au démarrage.

## Conséquences
- Il faut ajouter une ligne à chaque nouveau contrôleur. L'assistant `wazi make:controller` (0.4) pourra l'écrire.
- Chaque contrôleur déclaré est chargé et lu par réflexion à chaque requête, même si sa route n'est pas demandée ; il n'est en revanche fabriqué qu'à la demande (ADR-015). Un cache des routes en PHP pur (`var_export`, jamais `unserialize`) pourra s'ajouter par un nouvel ADR.
- Pas de préfixe ni de middleware au niveau de la classe pour l'instant : chaque méthode porte son chemin complet.
- Pas d'attribut pour une méthode HTTP hors des cinq prévues ; `$router->add()` reste disponible.
