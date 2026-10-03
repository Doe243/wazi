<?php

declare(strict_types=1);

namespace Wazi\Routing\Exception;

/**
 * Levée quand une route a bien été trouvée, mais que son exécution révèle
 * une erreur dans votre code.
 */
final class RoutingException extends \LogicException
{
    public static function handlerMustReturnResponse(string $route, string $givenType): self
    {
        return new self(sprintf(
            'Le code de la route « %s » doit retourner une réponse (ResponseInterface) ; il a retourné « %s ».'
            . ' Dans Wazi, on n\'affiche rien avec echo : on retourne une réponse, par exemple'
            . ' return new Response(200, [\'Content-Type\' => \'text/html\'], \'<h1>Bonjour</h1>\');',
            $route,
            $givenType,
        ));
    }

    /**
     * Sécurité (ADR-006) : un paramètre de route est écrit par le visiteur.
     * S'il pouvait remplacer un attribut posé plus tôt sur la requête (par
     * exemple l'utilisateur connecté), le visiteur choisirait cette valeur.
     */
    public static function parameterWouldOverwriteAttribute(string $route, string $name): self
    {
        return new self(sprintf(
            'Le paramètre {%s} de la route « %s » porte le même nom qu\'un attribut déjà présent sur la requête.'
            . ' Wazi refuse de l\'écraser : la valeur d\'un paramètre de route est choisie par le visiteur.'
            . ' Renommez le paramètre dans le chemin de la route.',
            $name,
            $route,
        ));
    }

    public static function controllerCannotBeBuilt(string $route, string $class, \Throwable $reason): self
    {
        return new self(sprintf(
            'La route « %s » désigne le contrôleur « %s », mais il n\'a pas pu être fabriqué. %s',
            $route,
            $class,
            $reason->getMessage(),
        ), 0, $reason);
    }

    public static function controllerMethodNotFound(string $route, string $class, string $method): self
    {
        return new self(sprintf(
            'La route « %s » désigne la méthode « %s » du contrôleur « %s », mais cette méthode n\'existe pas'
            . ' ou n\'est pas publique. Vérifiez son nom dans la déclaration de la route, et qu\'elle est bien'
            . ' déclarée « public function %s(...) » dans la classe.',
            $route,
            $method,
            $class,
            $method,
        ));
    }

    public static function unresolvableArgument(string $route, string $name): self
    {
        return new self(sprintf(
            'Le code de la route « %s » attend un argument $%s que Wazi ne sait pas fournir. Il ne fournit que'
            . ' la requête (un paramètre de type ServerRequestInterface) et les paramètres de la route, par leur'
            . ' nom : pour recevoir $%s, la route doit contenir {%s}. Un service (base de données, messagerie...)'
            . ' se demande dans le constructeur du contrôleur, pas ici.',
            $route,
            $name,
            $name,
            $name,
        ));
    }

    public static function argumentTypeMismatch(string $route, string $name, string $expected, string $given): self
    {
        return new self(sprintf(
            'Le code de la route « %s » attend $%s de type %s, mais la route fournit un %s.'
            . ' Faites correspondre les deux : {%s:int} dans le chemin donne un int, {%s} donne un string.',
            $route,
            $name,
            $expected,
            $given,
            $name,
            $name,
        ));
    }
}
