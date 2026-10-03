<?php

declare(strict_types=1);

namespace Wazi\Http\Exception;

/**
 * Levée quand une liste de middlewares contient autre chose qu'un middleware.
 */
final class InvalidMiddlewareException extends \InvalidArgumentException
{
    public static function notAMiddleware(int|string $position, string $givenType): self
    {
        return new self(sprintf(
            'L\'élément n° %s de la liste des middlewares est de type « %s », pas un middleware.'
            . ' Un middleware est un objet qui implémente Psr\Http\Server\MiddlewareInterface.'
            . ' Donnez l\'objet, par exemple new SecurityHeaders(), ou le nom de sa classe,'
            . ' par exemple RequireLogin::class : le conteneur le fabriquera au moment voulu.',
            $position,
            $givenType,
        ));
    }

    /**
     * Sécurité (ADR-006) : le texte refusé n'est pas recopié dans le message.
     */
    public static function notAClassName(int|string $position): self
    {
        return new self(sprintf(
            'L\'élément n° %s de la liste des middlewares est un texte qui n\'a pas la forme d\'un nom de classe.'
            . ' Écrivez le nom de la classe avec ::class, par exemple RequireLogin::class.',
            $position,
        ));
    }
}
