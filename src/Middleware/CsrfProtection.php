<?php

declare(strict_types=1);

namespace Wazi\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Wazi\Http\CsrfToken;
use Wazi\Middleware\Exception\CsrfException;

/**
 * Refuse toute requête qui modifie quelque chose sans apporter le bon jeton
 * (voir CsrfToken pour l'attaque et la parade).
 *
 * Toute méthode autre que GET, HEAD et OPTIONS doit apporter le jeton, égal à
 * celui du cookie : dans le champ de formulaire « _csrf », ou dans l'en-tête
 * X-CSRF-Token pour une requête envoyée par JavaScript. Dans un template
 * Kioo, le champ est ajouté de lui-même à chaque <form method="post">.
 *
 * Sécurité (ADR-006 et ADR-023) : la protection est active pour toutes les
 * routes, avec ou sans sessions. Pour en dispenser UNE route (un webhook
 * appelé par un autre service, qui n'a ni cookie ni jeton), ajoutez
 * [WithoutCsrf::class] à ses middlewares. Il n'existe aucun interrupteur global.
 */
final readonly class CsrfProtection implements MiddlewareInterface
{
    /** Les méthodes qui ne font que lire : elles ne demandent pas de jeton. */
    private const array SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(private CsrfToken $token) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (in_array($request->getMethod(), self::SAFE_METHODS, true)
            || $request->getAttribute(WithoutCsrf::class) === true
        ) {
            return $handler->handle($request);
        }

        // Le jeton ne se lit pas dans l'adresse : il y serait visible dans
        // l'historique du navigateur et dans les journaux.
        $body = $request->getParsedBody();
        $candidate = is_array($body) ? $body[CsrfToken::FIELD] ?? null : null;
        $candidate = is_string($candidate) ? $candidate : $request->getHeaderLine('X-CSRF-Token');

        if (!$this->token->matches($candidate)) {
            throw CsrfException::tokenMismatch($request->getMethod());
        }

        return $handler->handle($request);
    }
}
