# ADR-027 : `app.php`, l'application construite une fois pour le web et pour la console

**Statut :** acceptée — complète l'ADR-011 et l'ADR-026

## Contexte
Les commandes à venir (`wazi routes`, `wazi explain`, la traduction des templates) doivent connaître l'application du projet : ses routes, son conteneur, ses vues. Jusqu'ici, `public/index.php` construisait l'application et l'exécutait d'un seul geste : la console ne pouvait pas l'obtenir sans répondre à une requête.

## Options envisagées
- Faire construire l'application par la console, en devinant les contrôleurs et les réglages : c'est de la magie, et la console et le site pourraient diverger.
- Une classe `App\Kernel` à écrire par le projet, comme Symfony : une classe de plus à comprendre avant d'afficher une page.
- **Un fichier `app.php`, à la racine du projet, qui construit l'application et la retourne.**

## Décision
1. **`app.php`**, à la racine du projet (hors du dossier public), lit les réglages, crée le `Kernel`, déclare les services et les routes, et se termine par `return $app;`. Il ne répond à aucune requête.
2. **`Kernel::load($fichier)`** charge `app.php` et vérifie qu'il retourne bien un `Kernel`. Un `return` oublié donne une erreur qui dit quoi écrire, au lieu d'un « appel de méthode sur un entier ». Le fichier est chargé dans une fonction à part : ses variables ne se mêlent à aucune autre. Seul un fichier local ordinaire est accepté, jamais une adresse à protocole.
3. **`public/index.php`** se réduit à charger les classes, puis `Kernel::load(__DIR__ . '/../app.php')->run();`. **Le fichier `wazi`** du projet fait `$app = Kernel::load(__DIR__ . '/app.php');` et donne aux commandes ce dont elles ont besoin : `new RoutesCommand($app->router)`.
4. **Les commandes qui connaissent l'application vivent dans `Wazi\Kernel\Command`** : la couche d'assemblage est la seule à pouvoir dépendre à la fois de `Console` et de `Routing` ou `View` (règle des couches).
5. **`Router::routes()`** rend la liste des routes déclarées, en lecture seule.
6. **Première commande : `wazi routes`**, qui liste les routes : méthode, adresse, code exécuté, middlewares.

## Pourquoi
- Le site et la console partagent exactement la même application : une route déclarée est vue des deux côtés, sans rien répéter.
- `app.php` est un fichier ordinaire, lu de haut en bas : pas de classe à hériter, pas de méthode à surcharger (principes 1 et 3).
- `public/index.php` devient trop court pour contenir une erreur, et reste le seul fichier PHP du dossier public.

## Conséquences
- **Construire l'application ne doit rien faire d'irréversible** : `app.php` est exécuté aussi par la console, à chaque commande. On y déclare ; on n'y écrit pas dans un fichier, on n'y envoie pas de courriel.
- En ligne de commande, il n'y a ni requête ni session : une valeur qui en dépend se donne à Kioo par une fonction (`share('utilisateur', fn () => …)`), appelée seulement quand une page s'affiche.
- Une erreur dans `app.php` (un réglage manquant, une route mal écrite) empêche aussi la console de démarrer. Le fichier `wazi` l'attrape et l'affiche comme une erreur de la console, sans trace.
- Le projet de départ et l'application de démonstration sont réorganisés ainsi.
- Les commandes sont toujours déclarées une par une dans le fichier `wazi` (ADR-026).
