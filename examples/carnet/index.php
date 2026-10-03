<?php

/**
 * Un carnet de notes : la même idée que bonjour.php, mais organisée.
 *
 *   - un SERVICE (Carnet) qui connaît les notes ;
 *   - un CONTRÔLEUR (NoteController) qui reçoit ce service et le moteur de
 *     templates sans rien demander : le conteneur les lui fournit ;
 *   - des ROUTES écrites à côté des méthodes, avec des attributs ;
 *   - des VUES en Kioo, dans le dossier views/ ;
 *   - un MIDDLEWARE (CleRequise) qui garde une seule route.
 *
 * Dans un vrai projet, chaque classe aurait son fichier. Elles sont réunies
 * ici pour tout lire d'un coup.
 *
 * Pour l'essayer, depuis le dossier du framework :
 *
 *     php -S localhost:8000 examples/carnet/index.php
 *
 * puis ouvrez http://localhost:8000 dans votre navigateur.
 */

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Wazi\Http\Response;
use Wazi\Kernel\Kernel;
use Wazi\Routing\Attribute\Get;
use Wazi\View\Kioo;

require __DIR__ . '/../../vendor/autoload.php';

/**
 * Le service : il ne sait rien du web, seulement des notes.
 */
final class Carnet
{
    /** @var array<int, array{id: int, texte: string, importante: bool}> */
    private array $notes = [
        1 => ['id' => 1, 'texte' => 'Acheter du pain', 'importante' => false],
        2 => ['id' => 2, 'texte' => 'Lire le code du routeur', 'importante' => true],
        3 => ['id' => 3, 'texte' => 'Écrire mon premier template <Kioo>', 'importante' => false],
    ];

    /**
     * @return list<array{id: int, texte: string, importante: bool}>
     */
    public function toutes(): array
    {
        return array_values($this->notes);
    }

    /**
     * @return array{id: int, texte: string, importante: bool}|null
     */
    public function trouver(int $id): ?array
    {
        return $this->notes[$id] ?? null;
    }
}

/**
 * Le contrôleur : il traduit une requête en réponse.
 *
 * Son constructeur attend un Carnet et un Kioo. Personne n'écrit
 * « new NoteController(new Carnet(), new Kioo(...)) » : le conteneur lit ce
 * constructeur et fournit les deux.
 */
final class NoteController
{
    public function __construct(private readonly Carnet $carnet, private readonly Kioo $kioo) {}

    #[Get('/')]
    public function liste(): ResponseInterface
    {
        return $this->kioo->page('liste', ['notes' => $this->carnet->toutes(), 'annee' => 2026]);
    }

    // {id:int} dans le chemin, « int $id » dans la méthode : le même nom, donc la valeur arrive ici.
    #[Get('/notes/{id:int}')]
    public function voir(int $id): ResponseInterface
    {
        $note = $this->carnet->trouver($id);

        return $note === null
            ? $this->kioo->page('introuvable', ['id' => $id, 'annee' => 2026], 404)
            : $this->kioo->page('note', ['note' => $note, 'annee' => 2026]);
    }

    // Le second argument de l'attribut : les middlewares propres à cette route.
    #[Get('/prive', [CleRequise::class])]
    public function prive(): ResponseInterface
    {
        return $this->kioo->page('prive', ['notes' => $this->carnet->toutes(), 'annee' => 2026]);
    }
}

/**
 * Le middleware : un garde placé devant une route.
 *
 * S'il n'appelle pas $handler->handle(), la suite ne s'exécute jamais : le
 * contrôleur n'est même pas fabriqué.
 *
 * ⚠ Une clé dans l'adresse n'est PAS une vraie protection (elle se lit dans
 * l'historique du navigateur). Elle sert ici à montrer le mécanisme ; une
 * vraie connexion utilisera les sessions.
 */
final class CleRequise implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (($request->getQueryParams()['cle'] ?? null) !== 'wazi') {
            return new Response(403, ['Content-Type' => 'text/plain; charset=utf-8'], 'Accès refusé : il manque la clé.');
        }

        return $handler->handle($request);
    }
}

// --- L'application ---------------------------------------------------------

$app = new Kernel(
    // Le mode développement affiche le message des erreurs dans le navigateur.
    // Ne l'activez jamais sur un site en ligne.
    development: true,
    // Le dossier des templates Kioo.
    views: __DIR__ . '/views',
);

// Une seule ligne par contrôleur : ses routes sont lues dans ses attributs.
$app->router->addController(NoteController::class);

$app->run();
