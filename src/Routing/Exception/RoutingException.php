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
            'La fonction de la route « %s » doit retourner une réponse (ResponseInterface) ; elle a retourné « %s ».'
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
}
