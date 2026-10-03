<?php

/**
 * Le point d'entrée de la démonstration : toutes les requêtes passent par ici.
 *
 * C'est le SEUL fichier PHP du dossier public. Tout le reste (le code, les
 * vues, les réglages, les sessions) est rangé au-dessus, hors de portée d'un
 * navigateur.
 *
 * Ce fichier fait trois choses, dans l'ordre :
 *   1. lire les réglages ;
 *   2. assembler l'application (le noyau, les services, les routes) ;
 *   3. répondre à la requête.
 */

declare(strict_types=1);

use Demo\Carnet;
use Demo\ConnexionController;
use Demo\Filtres;
use Demo\NoteController;
use Demo\PageController;
use Demo\WebhookController;
use Wazi\Config\Config;
use Wazi\Container\Container;
use Wazi\Http\CspNonce;
use Wazi\Http\CsrfToken;
use Wazi\Http\ServerRequestCreator;
use Wazi\Kernel\Kernel;
use Wazi\View\Kioo;

require __DIR__ . '/../../../vendor/autoload.php';

// Dans un vrai projet, Composer charge vos classes tout seul (« autoload »).
// Ici, on les nomme une à une : vous voyez exactement ce qui est chargé.
foreach (['Carnet', 'Comptes', 'Filtres', 'Pages', 'ConnexionRequise', 'PageController', 'ConnexionController', 'NoteController', 'WebhookController'] as $classe) {
    require __DIR__ . '/../src/' . $classe . '.php';
}

$racine = dirname(__DIR__);

// 1. Les réglages : une variable d'environnement du serveur, sinon le fichier
//    .env, sinon la valeur par défaut écrite ici. Sans fichier .env, la démo
//    fonctionne quand même, en mode production.
$config = Config::fromEnvFile($racine . '/.env');

// 2. L'application.
$app = new Kernel(
    // Le mode développement affiche le message des erreurs dans le navigateur.
    // Il n'est jamais deviné : il faut l'écrire dans .env.
    development: $config->bool('APP_DEBUG', false),
    requestCreator: new ServerRequestCreator(
        // Les noms sous lesquels votre site répond. Vide : tout nom est accepté.
        trustedHosts: $config->list('APP_HOSTS', []),
        // Les adresses de VOS proxies (répartiteur de charge, Cloudflare...).
        trustedProxies: $config->list('APP_TRUSTED_PROXIES', []),
    ),
    views: $racine . '/views',
    sessions: $racine . '/var/sessions',
);

// Le conteneur sait fabriquer seul un objet dont le constructeur ne demande
// que d'autres objets. Le Carnet et le WebhookController demandent aussi un
// texte (un chemin, un secret) : on explique donc comment les fabriquer.
$app->container->set(Carnet::class, static fn(): Carnet => new Carnet($racine . '/var/notes.json'));
$app->container->set(
    WebhookController::class,
    static fn(): WebhookController => new WebhookController($config->string('DEMO_WEBHOOK_SECRET', '')),
);

// Kioo, avec les filtres de l'application en plus des siens (voir src/Filtres.php).
// La recette reçoit le conteneur : elle y prend les deux jetons que le noyau a
// préparés, celui des scripts et celui des formulaires.
$app->container->set(Kioo::class, static fn(Container $conteneur): Kioo => new Kioo(
    $racine . '/views',
    Filtres::tous(),
    $conteneur->get(CspNonce::class),
    $conteneur->get(CsrfToken::class),
));

// Une ligne par contrôleur : ses routes sont écrites à côté de ses méthodes.
$app->router->addController(PageController::class);
$app->router->addController(ConnexionController::class);
$app->router->addController(NoteController::class);
$app->router->addController(WebhookController::class);

// 3. La réponse.
$app->run();
