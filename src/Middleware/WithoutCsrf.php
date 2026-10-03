<?php

declare(strict_types=1);

namespace Wazi\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Dispense UNE route de la protection CSRF.
 *
 *     #[Post('/webhooks/paiement', [WithoutCsrf::class])]
 *
 * À réserver aux routes appelées par un autre service (un prestataire de
 * paiement, par exemple), qui n'a ni session ni jeton. Une telle route doit
 * vérifier autrement d'où vient la requête : une signature, une clé secrète.
 *
 * ⚠ Ne l'utilisez jamais pour une route que vos visiteurs connectés peuvent
 * déclencher : elle redeviendrait attaquable.
 *
 * Sécurité (ADR-006) : c'est la « sortie explicite, nommée et locale » de la
 * protection. Elle se voit dans la déclaration de la route, et se cherche
 * dans un dépôt.
 */
final readonly class WithoutCsrf implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Une simple marque sur la requête, que CsrfProtection lit juste après.
        return $handler->handle($request->withAttribute(self::class, true));
    }
}
