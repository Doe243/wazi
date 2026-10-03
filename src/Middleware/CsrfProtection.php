<?php

declare(strict_types=1);

namespace Wazi\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Wazi\Http\Session;
use Wazi\Middleware\Exception\CsrfException;

/**
 * Protège vos visiteurs contre la falsification de requête (attaque « CSRF »).
 *
 * L'attaque : un visiteur est connecté à votre site. Il ouvre, dans un autre
 * onglet, une page piégée qui contient un formulaire caché visant votre site
 * (« supprimer mon compte »). Son navigateur envoie le formulaire avec son
 * cookie de session : pour votre site, c'est lui qui a demandé la suppression.
 *
 * La parade : chaque formulaire de VOTRE site contient un jeton secret, lié à
 * la session du visiteur. La page piégée ne le connaît pas. Toute requête qui
 * modifie quelque chose (POST, PUT, PATCH, DELETE) sans le bon jeton est refusée.
 *
 * Le jeton se lit dans le champ de formulaire « _csrf », ou dans l'en-tête
 * X-CSRF-Token pour une requête envoyée par JavaScript. Dans un template
 * Kioo, le champ est ajouté de lui-même à chaque <form method="post">.
 *
 * Sécurité (ADR-006 et ADR-021) : la protection est active pour toutes les
 * routes. Pour en dispenser UNE route (un webhook appelé par un autre
 * service, qui n'a ni session ni jeton), ajoutez [WithoutCsrf::class] à ses
 * middlewares. Il n'existe aucun interrupteur global.
 */
final readonly class CsrfProtection implements MiddlewareInterface
{
    /** Les méthodes qui ne font que lire : elles ne demandent pas de jeton. */
    private const array SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(private Session $session) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (in_array($request->getMethod(), self::SAFE_METHODS, true)
            || $request->getAttribute(WithoutCsrf::class) === true
        ) {
            return $handler->handle($request);
        }

        // Sans session démarrée, il n'y a pas de jeton à comparer : id() le
        // dit clairement plutôt que de laisser croire à un mauvais jeton.
        $this->session->id();

        $body = $request->getParsedBody();
        $received = is_array($body) ? $body[Session::CSRF_FIELD] ?? null : null;
        $received = is_string($received) ? $received : $request->getHeaderLine('X-CSRF-Token');

        // hash_equals() compare en un temps constant : la durée de la
        // comparaison ne révèle rien sur le jeton attendu.
        if ($received === '' || !hash_equals($this->session->csrfToken(), $received)) {
            throw CsrfException::tokenMismatch($request->getMethod());
        }

        return $handler->handle($request);
    }
}
