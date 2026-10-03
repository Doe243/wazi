<?php

declare(strict_types=1);

namespace Wazi\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Wazi\Http\CsrfToken;

/**
 * Transporte le jeton de protection des formulaires dans un cookie.
 *
 *   À l'aller : lit le cookie et prépare le CsrfToken de la requête.
 *   Au retour : si une page a eu besoin d'un jeton neuf, envoie le cookie.
 *
 * La vérification elle-même est faite par CsrfProtection, plus près de la
 * route. Un visiteur dont aucune page ne contient de formulaire ne reçoit
 * aucun cookie.
 *
 * Sécurité (ADR-023) — le cookie est envoyé avec :
 *   - HttpOnly     : le JavaScript de la page ne peut pas le lire ;
 *   - SameSite=Lax : le navigateur ne le joint pas à un formulaire envoyé
 *                    depuis un autre site ;
 *   - en HTTPS, Secure et le nom « __Host-csrf ». Ce préfixe demande au
 *     navigateur de refuser tout cookie de ce nom posé par un sous-domaine ou
 *     par une page non chiffrée : personne d'autre que votre site ne peut le fixer.
 */
final readonly class CsrfCookie implements MiddlewareInterface
{
    private const string NAME = 'csrf';

    private const string SECURE_NAME = '__Host-csrf';

    public function __construct(private CsrfToken $token) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $isSecure = $request->getUri()->getScheme() === 'https';
        $name = $isSecure ? self::SECURE_NAME : self::NAME;
        $received = $request->getCookieParams()[$name] ?? null;

        $this->token->start(is_string($received) ? $received : null);

        $response = $handler->handle($request);
        $toSend = $this->token->toSend();

        if ($toSend === null) {
            return $response;
        }

        return $response->withAddedHeader(
            'Set-Cookie',
            $name . '=' . $toSend . '; Path=/; HttpOnly; SameSite=Lax' . ($isSecure ? '; Secure' : ''),
        );
    }
}
