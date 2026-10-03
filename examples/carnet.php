<?php

/**
 * Un carnet de notes : la même idée que bonjour.php, mais organisée.
 *
 *   - un SERVICE (Carnet) qui connaît les notes ;
 *   - un CONTRÔLEUR (NoteController) qui reçoit ce service sans rien demander :
 *     le conteneur le lui fournit ;
 *   - des ROUTES écrites à côté des méthodes, avec des attributs ;
 *   - un MIDDLEWARE (CleRequise) qui garde une seule route.
 *
 * Dans un vrai projet, chaque classe aurait son fichier. Elles sont réunies
 * ici pour tout lire d'un coup.
 *
 * Pour l'essayer, depuis le dossier du framework :
 *
 *     php -S localhost:8000 examples/carnet.php
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

require __DIR__ . '/../vendor/autoload.php';

/**
 * Le service : il ne sait rien du web, seulement des notes.
 */
final class Carnet
{
    /** @var array<int, string> */
    private array $notes = [
        1 => 'Acheter du pain',
        2 => 'Lire le code du routeur',
        3 => 'Écrire mon premier contrôleur',
    ];

    /**
     * @return array<int, string>
     */
    public function toutes(): array
    {
        return $this->notes;
    }

    public function trouver(int $id): ?string
    {
        return $this->notes[$id] ?? null;
    }
}

/**
 * Le contrôleur : il traduit une requête en réponse.
 *
 * Son constructeur attend un Carnet. Personne n'écrit « new NoteController(new Carnet()) » :
 * le conteneur lit ce constructeur et fournit le Carnet tout seul.
 */
final class NoteController
{
    public function __construct(private readonly Carnet $carnet) {}

    #[Get('/')]
    public function liste(): ResponseInterface
    {
        $html = '<ul>';

        foreach ($this->carnet->toutes() as $id => $texte) {
            $html .= '<li><a href="/notes/' . $id . '">' . htmlspecialchars($texte) . '</a></li>';
        }

        $html .= '</ul>'
            . '<p><a href="/prive">La page privée, sans la clé (403)</a></p>'
            . '<p><a href="/prive?cle=wazi">La page privée, avec la clé</a></p>'
            . '<p><a href="/notes/99">Une note qui n\'existe pas (404)</a></p>';

        return self::page('Mon carnet', $html);
    }

    // {id:int} dans le chemin, « int $id » dans la méthode : le même nom, donc la valeur arrive ici.
    #[Get('/notes/{id:int}')]
    public function voir(int $id): ResponseInterface
    {
        $texte = $this->carnet->trouver($id);

        if ($texte === null) {
            return self::page('Note introuvable', '<p>Aucune note ne porte le numéro ' . $id . '.</p>', 404);
        }

        return self::page('Note n° ' . $id, '<p>' . htmlspecialchars($texte) . '</p>');
    }

    // Le second argument de l'attribut : les middlewares propres à cette route.
    #[Get('/prive', [CleRequise::class])]
    public function prive(ServerRequestInterface $request): ResponseInterface
    {
        return self::page('Page privée', '<p>Vous avez la clé : ' . count($this->carnet->toutes()) . ' notes à lire.</p>');
    }

    private static function page(string $titre, string $html, int $statut = 200): ResponseInterface
    {
        return new Response(
            $statut,
            ['Content-Type' => 'text/html; charset=utf-8'],
            '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>' . htmlspecialchars($titre) . '</title></head>'
            . '<body><h1>' . htmlspecialchars($titre) . '</h1>' . $html . '<p><a href="/">Retour au carnet</a></p></body></html>',
        );
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

// Le mode développement affiche le message des erreurs dans le navigateur.
// Ne l'activez jamais sur un site en ligne.
$app = new Kernel(development: true);

// Une seule ligne par contrôleur : ses routes sont lues dans ses attributs.
$app->router->addController(NoteController::class);

$app->run();
