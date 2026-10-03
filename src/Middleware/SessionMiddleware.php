<?php

declare(strict_types=1);

namespace Wazi\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Wazi\Http\Session;

/**
 * Fait vivre la session d'une requête à l'autre.
 *
 *   À l'aller : lit l'identifiant dans le cookie, retrouve ce qui avait été
 *               noté, et remplit la Session.
 *   Au retour : enregistre ce que votre code a noté, et envoie le cookie.
 *
 * Un visiteur qui ne fait que lire le site ne reçoit aucun cookie et ne crée
 * aucun fichier : la session ne commence à exister que lorsque votre code y
 * note quelque chose.
 *
 * Sécurité (ADR-006 et ADR-021) — le cookie est envoyé avec :
 *   - HttpOnly  : le JavaScript de la page ne peut pas le lire. Un script
 *                 injecté ne peut donc pas voler la session ;
 *   - SameSite=Lax : le navigateur ne le joint pas aux requêtes déclenchées par
 *                 un autre site (formulaire caché, image), sauf un simple lien ;
 *   - Secure    : quand le site est en HTTPS, il ne voyage jamais en clair ;
 *   - Path=/    : il vaut pour tout le site.
 * Un identifiant reçu qui ne correspond à aucune session connue n'est jamais
 * adopté : le visiteur reçoit un identifiant neuf, choisi par le serveur.
 */
final readonly class SessionMiddleware implements MiddlewareInterface
{
    /**
     * @param Session      $session    la session de la requête : la même que reçoivent vos contrôleurs
     * @param string       $cookieName le nom du cookie ; il ne dit volontairement rien du framework utilisé
     */
    public function __construct(
        private Session $session,
        private SessionStore $store,
        private string $cookieName = 'session',
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $receivedId = $this->receivedId($request);
        $data = $receivedId !== null ? $this->store->read($receivedId) : null;

        // Identifiant inconnu ou expiré : on repart d'une session neuve, avec
        // un identifiant que le SERVEUR choisit.
        $this->session->start($data !== null ? $receivedId : null, $data ?? []);

        $response = $handler->handle($request->withAttribute(Session::class, $this->session));

        return $this->save($request, $response);
    }

    // ------------------------------------------------------------------
    // Outils internes
    // ------------------------------------------------------------------

    private function receivedId(ServerRequestInterface $request): ?string
    {
        $id = $request->getCookieParams()[$this->cookieName] ?? null;

        return is_string($id) && Session::isValidId($id) ? $id : null;
    }

    private function save(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $session = $this->session;

        // regenerate() a changé l'identifiant : l'ancien ne doit plus rien valoir.
        if ($session->discardedId() !== null) {
            $this->store->delete($session->discardedId());
        }

        $isEmpty = $session->all() === [];

        // Rien n'a été noté pour ce visiteur : ni fichier, ni cookie.
        if ($isEmpty && $session->isNew()) {
            return $response;
        }

        // Tout a été oublié (déconnexion) : on efface le cookie du navigateur.
        if ($isEmpty) {
            $this->store->delete($session->id());

            return $response->withAddedHeader('Set-Cookie', $this->cookie($request, '', true));
        }

        // Réécrire le fichier à chaque requête repousse aussi son expiration.
        $this->store->write($session->id(), $session->all());

        // Le cookie n'est renvoyé que si l'identifiant est nouveau pour le navigateur.
        if ($session->isNew() || $session->discardedId() !== null) {
            return $response->withAddedHeader('Set-Cookie', $this->cookie($request, $session->id(), false));
        }

        return $response;
    }

    private function cookie(ServerRequestInterface $request, string $value, bool $expired): string
    {
        $cookie = $this->cookieName . '=' . $value . '; Path=/; HttpOnly; SameSite=Lax';

        if ($request->getUri()->getScheme() === 'https') {
            $cookie .= '; Secure';
        }

        // Une date passée demande au navigateur de supprimer le cookie.
        return $expired ? $cookie . '; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:00 GMT' : $cookie;
    }
}
