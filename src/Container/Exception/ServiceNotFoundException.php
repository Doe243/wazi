<?php

declare(strict_types=1);

namespace Wazi\Container\Exception;

use Psr\Container\NotFoundExceptionInterface;

/**
 * Levée quand le conteneur ne connaît pas le service demandé et ne sait pas le fabriquer.
 *
 * Elle implémente NotFoundExceptionInterface, comme l'exige PSR-11.
 */
final class ServiceNotFoundException extends \RuntimeException implements NotFoundExceptionInterface
{
    /**
     * Sécurité (ADR-006) : l'identifiant n'est cité que s'il a la forme d'un nom
     * de classe. S'il venait d'une requête, il ne doit pas se retrouver tel quel
     * dans un journal.
     */
    public static function forId(string $id, bool $looksLikeClassName): self
    {
        return new self(sprintf(
            'Le conteneur ne connaît pas le service « %s » et ne peut pas le fabriquer : aucune classe de ce nom'
            . ' n\'existe. Vérifiez l\'orthographe et le namespace, ou enregistrez le service :'
            . ' $container->set(\'nom\', fn () => ...).',
            $looksLikeClassName ? $id : '(identifiant invalide)',
        ));
    }

    public static function forInterface(string $interface): self
    {
        return new self(sprintf(
            '« %s » est une interface : le conteneur ne peut pas deviner quelle classe utiliser à sa place.'
            . ' Dites-le-lui : $container->bind(%s::class, VotreClasse::class).',
            $interface,
            self::shortName($interface),
        ));
    }

    private static function shortName(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}
