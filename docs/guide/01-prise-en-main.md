# 1. Prise en main

## Ce qu'il vous faut

- **PHP 8.5** ou plus récent, avec l'extension `mbstring` (elle est presque toujours déjà là). Pour vérifier : `php -v`.
- **Composer**, l'outil qui installe les bibliothèques PHP. Pour vérifier : `composer -V`.

## Installer

Wazi n'est pas encore publié sur Packagist, l'annuaire de Composer. En attendant, on récupère le dépôt :

```bash
git clone https://github.com/Doe243/wazi.git
cd wazi
composer install
```

## Une première page

Créez un fichier `bonjour.php` dans le dossier `examples/` :

```php
<?php

declare(strict_types=1);

use Wazi\Http\Response;
use Wazi\Kernel\Kernel;

require __DIR__ . '/../vendor/autoload.php';

$app = new Kernel();

$app->router->get('/', function () {
    return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], '<h1>Bonjour !</h1>');
});

$app->run();
```

Lancez le serveur de développement fourni avec PHP :

```bash
php -S localhost:8000 examples/bonjour.php
```

Ouvrez http://localhost:8000 : la page s'affiche. Pour arrêter le serveur : `Ctrl+C`.

Ce fichier fait trois choses, et toute application Wazi fait les mêmes :

1. **créer le noyau** (`new Kernel()`), qui assemble les pièces ;
2. **déclarer des routes** : « pour cette adresse, exécute ce code » ;
3. **répondre** (`$app->run()`).

Le code d'une route retourne toujours une **réponse** : un code de statut (200 : tout va bien), des en-têtes, un contenu.

## Voir ses erreurs

Par défaut, Wazi est en **mode production** : quand quelque chose échoue, le visiteur voit une page neutre, sans détail. C'est ce qu'il faut en ligne.

Pendant que vous développez, demandez le détail :

```php
$app = new Kernel(development: true);
```

Le navigateur affiche alors ce qui s'est passé, dans quel fichier et à quelle ligne. Ce mode n'est jamais deviné : il faut l'écrire. Ne l'activez jamais sur un site en ligne. La page [Les erreurs](10-erreurs.md) explique ce qui est affiché, et où trouver le reste.

## Ranger un vrai projet

Un seul fichier suffit pour essayer. Dès que le projet grandit, on le range ainsi :

```text
mon-projet/
├── public/            Le SEUL dossier visible depuis un navigateur
│   ├── index.php      Le point d'entrée : toutes les requêtes passent par lui
│   └── app.css        Les fichiers servis tels quels : styles, scripts, images
├── src/               Votre code : contrôleurs, services
├── views/             Vos templates Kioo
├── var/               Ce que l'application écrit : sessions, fichiers
├── .env               Vos réglages et vos secrets (jamais partagé)
└── vendor/            Les bibliothèques installées par Composer
```

La règle qui compte : **seul `public/` est visible depuis Internet**. Votre code, vos réglages et les sessions sont au-dessus, hors de portée. Wazi le vérifie : il refuse un fichier `.env` ou un dossier de sessions placé dans le dossier public.

Avec ce rangement, le serveur de développement se lance en désignant le dossier public :

```bash
php -S localhost:8000 -t public
```

L'application [de démonstration](../../examples/demo/README.md) est rangée exactement ainsi. C'est le meilleur modèle à copier.

Un `public/index.php` complet ressemble à ceci :

```php
<?php

declare(strict_types=1);

use Wazi\Config\Config;
use Wazi\Http\ServerRequestCreator;
use Wazi\Kernel\Kernel;

require __DIR__ . '/../vendor/autoload.php';

$racine = dirname(__DIR__);
$config = Config::fromEnvFile($racine . '/.env');

$app = new Kernel(
    development: $config->bool('APP_DEBUG', false),
    requestCreator: new ServerRequestCreator(
        trustedHosts: $config->list('APP_HOSTS', []),
        trustedProxies: $config->list('APP_TRUSTED_PROXIES', []),
    ),
    views: $racine . '/views',
    sessions: $racine . '/var/sessions',
);

$app->router->addController(App\ArticleController::class);

$app->run();
```

Chaque argument est expliqué dans la page qui le concerne. Aucun n'est obligatoire : `new Kernel()` fonctionne.

## Le chemin d'une requête

Quand un navigateur demande une page, voici ce qui se passe, dans l'ordre :

```text
navigateur
    │
    ▼
public/index.php          crée le noyau, déclare les routes
    │
    ▼
Kernel                    construit la requête à partir de ce que PHP a reçu
    │
    ▼
Middlewares               en-têtes de sécurité, jeton des formulaires, session, puis les vôtres
    │
    ▼
Routeur                   trouve la route qui correspond à l'adresse
    │
    ▼
Middlewares de la route   protection des formulaires, puis ceux que vous avez posés sur la route
    │
    ▼
Votre code                la fonction, ou la méthode du contrôleur ; elle retourne une réponse
    │
    ▼
navigateur                la réponse repasse par les middlewares, puis elle est envoyée
```

Si une exception est levée en chemin, elle devient une page d'erreur.

Rien n'est caché : chaque étape est une classe de `src/`, que vous pouvez ouvrir. Dans votre éditeur, un clic sur `Kernel`, `Router` ou `Response` vous y mène.

Suite : [Les routes](02-routes.md).
