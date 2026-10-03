<?php

declare(strict_types=1);

namespace Wazi\Routing\Exception;

use Wazi\Contracts\HttpError;
use Wazi\Http\Exception\ValuePreview;

/**
 * Levée quand aucune route ne correspond à l'adresse demandée : c'est la « page introuvable » (404).
 *
 * Ce n'est pas une panne : un visiteur a simplement demandé une adresse qui n'existe pas.
 */
final class RouteNotFoundException extends \RuntimeException implements HttpError
{
    /**
     * Sécurité (ADR-006) : le chemin vient du visiteur, il est nettoyé avant d'entrer dans le message.
     */
    public static function forPath(string $path): self
    {
        return new self(sprintf(
            'Aucune route ne correspond à l\'adresse « %s ». Vérifiez l\'orthographe de l\'adresse et celle'
            . ' du chemin de vos routes : « /articles » et « /articles/ » sont deux adresses différentes,'
            . ' et un paramètre comme {id:int} n\'accepte que ce que sa contrainte autorise.',
            ValuePreview::of($path),
        ));
    }

    public function getStatusCode(): int
    {
        return 404;
    }

    public function getResponseHeaders(): array
    {
        return [];
    }
}
