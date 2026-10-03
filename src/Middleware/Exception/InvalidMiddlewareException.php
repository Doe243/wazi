<?php

declare(strict_types=1);

namespace Wazi\Middleware\Exception;

/**
 * Levée quand la liste donnée à un pipeline contient autre chose qu'un middleware.
 */
final class InvalidMiddlewareException extends \InvalidArgumentException
{
    public static function notAMiddleware(int|string $position, string $givenType): self
    {
        return new self(sprintf(
            'L\'élément n° %s de la liste des middlewares est de type « %s », pas un middleware.'
            . ' Un middleware est un objet qui implémente Psr\Http\Server\MiddlewareInterface.'
            . ' Passez un objet déjà créé, par exemple new SecurityHeaders(), et non le nom de sa classe.',
            $position,
            $givenType,
        ));
    }
}
