# 1. Prise en main

## Ce qu'il vous faut

- **PHP 8.5** ou plus récent, avec l'extension `mbstring` (elle est presque toujours déjà là). Pour vérifier : `php -v`.
- **Composer**, l'outil qui installe les bibliothèques PHP. Pour vérifier : `composer -V`.

## Installer

Wazi n'est pas encore publié sur Packagist, l'annuaire de Composer. En attendant, on récupère le dépôt :

```bash
git clone https://github.com/wazi-php/wazi.git
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
├── app.php            Votre application : réglages, services, routes
├── public/            Le SEUL dossier visible depuis un navigateur
│   ├── index.php      Le point d'entrée : charge app.php et répond
│   └── app.css        Les fichiers servis tels quels : styles, scripts, images
├── src/               Votre code : contrôleurs, services
├── views/             Vos templates Kioo
├── var/               Ce que l'application écrit : sessions, fichiers
├── .env               Vos réglages et vos secrets (jamais partagé)
├── wazi               La console du projet : charge app.php et exécute une commande
└── vendor/            Les bibliothèques installées par Composer
```

La règle qui compte : **seul `public/` est visible depuis Internet**. Votre code, vos réglages et les sessions sont au-dessus, hors de portée. Wazi le vérifie : il refuse un fichier `.env` ou un dossier de sessions placé dans le dossier public.

Avec ce rangement, le site se lance par la console de Wazi, qui sert le dossier public :

```bash
wazi serve
```

Voir [La console](13-console.md).

L'application [de démonstration](../../examples/demo/README.md) est rangée exactement ainsi. C'est le meilleur modèle à copier.

### `app.php` : l'application, construite une fois

Dans un projet rangé, l'application ne se construit plus dans `index.php` mais dans un fichier à part, `app.php`, à la racine. Il lit les réglages, crée le noyau, déclare les services et les routes, puis **retourne** l'application :

```php
<?php

declare(strict_types=1);

use Wazi\Config\Config;
use Wazi\Http\ServerRequestCreator;
use Wazi\Kernel\Kernel;

$config = Config::fromEnvFile(__DIR__ . '/.env');

$app = new Kernel(
    development: $config->bool('APP_DEBUG', false),
    requestCreator: new ServerRequestCreator(
        trustedHosts: $config->list('APP_HOSTS', []),
        trustedProxies: $config->list('APP_TRUSTED_PROXIES', []),
    ),
    views: __DIR__ . '/views',
    sessions: __DIR__ . '/var/sessions',
);

$app->router->addController(App\ArticleController::class);

return $app;
```

Chaque argument est expliqué dans la page qui le concerne. Aucun n'est obligatoire : `new Kernel()` fonctionne.

`public/index.php` n'a alors plus que deux lignes utiles : charger les classes, puis charger l'application et lui demander de répondre.

```php
<?php

declare(strict_types=1);

use Wazi\Kernel\Kernel;

require __DIR__ . '/../vendor/autoload.php';

Kernel::load(__DIR__ . '/../app.php')->run();
```

`Kernel::load()` charge `app.php` et vérifie qu'il a bien retourné l'application. Si vous oubliez le `return $app;`, il vous le dit.

Pourquoi deux fichiers ? Parce que le site n'est pas seul à avoir besoin de l'application. La console la charge aussi, par exemple pour lister vos routes (`wazi routes`). En la construisant à un seul endroit, le site et la console voient exactement la même chose.

Une conséquence : `app.php` est exécuté à chaque commande de la console. On y **déclare** ; on n'y écrit pas dans un fichier, on n'y envoie pas de courriel.

## Le chemin d'une requête

Quand un navigateur demande une page, voici ce qui se passe, dans l'ordre :

```text
navigateur
    │
    ▼
public/index.php          charge app.php, qui crée le noyau et déclare les routes
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
