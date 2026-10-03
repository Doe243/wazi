<?php

declare(strict_types=1);

namespace Wazi\Middleware\Exception;

use Wazi\Contracts\HttpError;

/**
 * Levée quand une requête qui modifie quelque chose n'apporte pas le bon jeton
 * de protection : c'est un « accès refusé » (403).
 */
final class CsrfException extends \RuntimeException implements HttpError
{
    public static function tokenMismatch(string $method): self
    {
        return new self(sprintf(
            'La requête %s a été refusée : elle ne porte pas le jeton de protection des formulaires, ou il ne'
            . ' correspond pas au cookie du visiteur. Sans ce jeton, un autre site pourrait faire agir vos visiteurs'
            . ' à leur insu (attaque « CSRF »). Dans un template Kioo, le jeton est ajouté de lui-même à chaque'
            . ' <form method="post">. Ailleurs, ajoutez le champ caché « _csrf » avec la valeur de CsrfToken::value(),'
            . ' ou l\'en-tête X-CSRF-Token pour une requête JavaScript. Pour une route qui reçoit des requêtes d\'un'
            . ' autre service (un webhook), ajoutez [WithoutCsrf::class] à ses middlewares.',
            preg_replace('/[^A-Za-z]/', '?', $method) ?? '',
        ));
    }

    public function getStatusCode(): int
    {
        return 403;
    }

    public function getResponseHeaders(): array
    {
        return [];
    }
}
