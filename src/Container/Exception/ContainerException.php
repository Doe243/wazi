<?php

declare(strict_types=1);

namespace Wazi\Container\Exception;

use Psr\Container\ContainerExceptionInterface;

/**
 * Levée quand le conteneur connaît le service demandé, mais n'arrive pas à le fabriquer.
 *
 * Chaque message suit la règle des erreurs pédagogiques de Wazi :
 * ce qui s'est passé, pourquoi, et comment corriger.
 *
 * Elle implémente ContainerExceptionInterface, comme l'exige PSR-11.
 */
final class ContainerException extends \RuntimeException implements ContainerExceptionInterface
{
    /**
     * @param list<string> $chain les services en cours de fabrication, dans l'ordre, le dernier répétant le premier
     */
    public static function circularDependency(array $chain): self
    {
        return new self(sprintf(
            'Dépendance circulaire : %s. Chacun de ces services a besoin du suivant pour être fabriqué,'
            . ' et le dernier a besoin du premier : aucun ne peut être créé avant les autres.'
            . ' Retirez l\'une de ces dépendances, ou sortez ce qu\'elles ont en commun dans une troisième classe.',
            implode(' → ', $chain),
        ));
    }

    public static function unresolvableParameter(string $class, string $parameter, string $type): self
    {
        return new self(sprintf(
            'Impossible de fabriquer « %s » : le conteneur ne peut pas deviner la valeur du paramètre $%s (%s)'
            . ' de son constructeur. Il ne sait fournir que des objets. Donnez une valeur par défaut au paramètre,'
            . ' ou dites au conteneur comment fabriquer cette classe :'
            . ' $container->set(%s::class, fn () => new %s(...)).',
            $class,
            $parameter,
            $type,
            self::shortName($class),
            self::shortName($class),
        ));
    }

    public static function notInstantiable(string $class): self
    {
        return new self(sprintf(
            'Impossible de fabriquer « %s » : c\'est une classe abstraite, une énumération, ou son constructeur'
            . ' n\'est pas public. Le conteneur ne crée que des classes ordinaires. Dites-lui quoi fournir :'
            . ' $container->set(%s::class, fn () => ...) ou $container->bind(%s::class, UneAutreClasse::class).',
            $class,
            self::shortName($class),
            self::shortName($class),
        ));
    }

    /**
     * Sécurité (ADR-006) : voir Container. Les classes internes de PHP ne sont
     * jamais fabriquées automatiquement.
     */
    public static function internalClass(string $class): self
    {
        return new self(sprintf(
            '« %s » est une classe fournie par PHP : le conteneur refuse de la fabriquer tout seul.'
            . ' Ces classes (fichiers, bases de données, réseau...) demandent des réglages qu\'il ne peut pas'
            . ' deviner, et certaines sont dangereuses si leur nom vient d\'une requête. Enregistrez-la vous-même :'
            . ' $container->set(%s::class, fn () => new %s(...)).',
            $class,
            self::shortName($class),
            self::shortName($class),
        ));
    }

    public static function invalidBinding(string $id, string $class): self
    {
        return new self(sprintf(
            'Liaison impossible : « %s » n\'existe pas, ou n\'est pas un « %s ». La classe donnée à bind() doit'
            . ' exister et implémenter l\'interface (ou étendre la classe) qu\'elle remplace.',
            $class,
            $id,
        ));
    }

    public static function alreadyResolved(string $id): self
    {
        return new self(sprintf(
            'Le service « %s » a déjà été fabriqué et distribué : le redéfinir maintenant donnerait deux versions'
            . ' différentes à votre application. Enregistrez tous vos services avant le premier appel à get().',
            $id,
        ));
    }

    private static function shortName(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}
