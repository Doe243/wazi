<p><img src="docs/brand/wazi-mark.svg" alt="" width="76" height="56"></p>

# Wazi

> *Wazi* : « clair, ouvert, évident » en swahili.

Wazi est un framework PHP pensé pour les développeurs qui veulent **comprendre** ce qu'ils utilisent tout en construisant de vraies applications. Pas de magie cachée : chaque comportement se suit dans l'IDE, chaque erreur explique sa cause et la solution.

> **Statut : en construction (0.2 « Ça s'organise »).** Routes avec paramètres, contrôleurs dont les dépendances sont injectées, middlewares, erreurs pédagogiques. Pas encore de vues ni de base de données. L'API peut encore changer.

## Un premier exemple

```php
<?php

use Psr\Http\Message\ServerRequestInterface;
use Wazi\Http\Response;
use Wazi\Kernel\Kernel;

require __DIR__ . '/../vendor/autoload.php';

$app = new Kernel();

$app->router->get('/articles/{id:int}', function (ServerRequestInterface $request) {
    $id = $request->getAttribute('id');   // un int, garanti par la contrainte

    return new Response(200, ['Content-Type' => 'text/plain; charset=utf-8'], "Article n° $id");
});

$app->run();
```

Deux exemples à lancer, chacun en un seul fichier :

```bash
php -S localhost:8000 examples/bonjour.php   # des routes écrites comme des fonctions
php -S localhost:8000 examples/carnet.php    # un contrôleur, un service injecté, des routes en attributs, un middleware
```

## Principes

1. Transparence avant magie
2. Zéro configuration obligatoire
3. Complexité progressive : un fichier au départ, une application structurée ensuite
4. Erreurs pédagogiques
5. Code source lisible
6. PHP moderne uniquement (8.5+)
7. Standards PSR respectés (PSR-7, PSR-11, PSR-15, PSR-17)
8. Sécurité stricte, jamais pénible : défaut sûr, sortie explicite et locale

## Architecture

Les composants sont rangés en quatre couches. Une couche peut utiliser celles du dessous, jamais celles du dessus (règle vérifiée par Deptrac).

| Couche | Composants |
| --- | --- |
| Assemblage | `Kernel` |
| Fonctionnalités | `Routing`, `Middleware`, `Errors`, `View`, `Console` |
| Fondations | `Http`, `Container`, `Config` |
| Contrats | `Contracts` |

Les décisions d'architecture sont consignées dans [`docs/decisions/`](docs/decisions/).

## Kioo

<img src="docs/brand/kioo-mark.svg" alt="" width="36" height="36" align="left">

Kioo (« vitre » en swahili) est le langage de templates de Wazi : une page HTML ordinaire, avec quelques attributs en plus. Il est en cours d'écriture.

## Sécurité

Wazi applique une sécurité stricte par défaut. Pour signaler une faille, voir [`SECURITY.md`](SECURITY.md) : jamais dans une issue publique.

## Contribuer

```bash
composer install
composer check   # style, analyse statique, dépendances entre couches, tests
composer cs:fix  # corrige automatiquement le style
```

Une contribution n'est fusionnée que si `composer check` passe.

## Licence

MIT
