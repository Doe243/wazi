<?php

declare(strict_types=1);

namespace Wazi\Routing\Exception;

use Wazi\Routing\Constraint;

/**
 * Levée quand une route est mal déclarée. C'est une erreur dans votre code,
 * signalée dès le démarrage plutôt qu'à la première visite.
 *
 * Les chemins cités dans ces messages sont ceux que VOUS avez écrits dans
 * votre code, pas des valeurs venues d'une requête.
 */
final class InvalidRouteException extends \InvalidArgumentException
{
    public static function pathMustStartWithSlash(string $path): self
    {
        return new self(sprintf(
            'Le chemin de route « %s » doit commencer par « / ». Exemple : $router->get(\'/articles\', ...).',
            $path,
        ));
    }

    public static function emptySegment(string $path): self
    {
        return new self(sprintf(
            'Le chemin de route « %s » contient un segment vide : deux « / » qui se suivent, ou un « / » final.'
            . ' Écrivez par exemple « /articles » plutôt que « /articles/ ».',
            $path,
        ));
    }

    public static function invalidSegment(string $path, string $segment): self
    {
        return new self(sprintf(
            'Dans la route « %s », le segment « %s » est invalide. Un segment est soit un texte fixe'
            . ' sans accolade, soit un paramètre qui l\'occupe en entier : {nom} ou {nom:contrainte}.'
            . ' Le nom ne contient que des lettres, des chiffres et « _ ». Exemple : /articles/{id:int}.'
            . ' Un paramètre ne peut pas être collé à du texte (« article-{id} ») ; un segment fixe ne peut'
            . ' être ni « . » ni « .. », et ne contient ni « ? », ni « # », ni « %% » : écrivez-le en clair.',
            $path,
            $segment,
        ));
    }

    public static function unknownConstraint(string $path, string $constraint): self
    {
        return new self(sprintf(
            'Dans la route « %s », la contrainte « %s » n\'existe pas. Contraintes disponibles : %s.'
            . ' Wazi n\'accepte pas d\'expression régulière libre dans une route : une expression mal écrite'
            . ' peut ralentir le serveur sur une adresse piégée.',
            $path,
            $constraint,
            Constraint::names(),
        ));
    }

    public static function duplicateParameter(string $path, string $name): self
    {
        return new self(sprintf(
            'Dans la route « %s », le paramètre {%s} apparaît deux fois : le second écraserait le premier.'
            . ' Donnez-leur deux noms différents.',
            $path,
            $name,
        ));
    }

    public static function invalidMethod(string $method): self
    {
        return new self(sprintf(
            '« %s » n\'est pas une méthode HTTP utilisable pour une route. Écrivez-la en majuscules :'
            . ' GET, POST, PUT, PATCH, DELETE... ou utilisez $router->get(), $router->post(), etc.',
            $method,
        ));
    }

    public static function noMethod(string $path): self
    {
        return new self(sprintf(
            'La route « %s » n\'accepte aucune méthode HTTP : donnez-en au moins une, par exemple [\'GET\'].',
            $path,
        ));
    }

    public static function duplicateRoute(string $method, string $path): self
    {
        return new self(sprintf(
            'La route « %s %s » est déclarée deux fois : la seconde ne serait jamais atteinte.'
            . ' Supprimez l\'une des deux déclarations.',
            $method,
            $path,
        ));
    }

    public static function invalidHandler(string $path): self
    {
        return new self(sprintf(
            'Le code de la route « %s » est mal déclaré. Donnez soit une fonction, soit un tableau de deux textes :'
            . ' la classe du contrôleur et le nom de sa méthode. Exemple : [ArticleController::class, \'show\'].',
            $path,
        ));
    }
}
