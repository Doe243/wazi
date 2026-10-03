<?php

declare(strict_types=1);

namespace Wazi\Http\Exception;

/**
 * Levée quand une URI, ou l'une de ses parties, est invalide.
 *
 * Chaque message suit la règle des erreurs pédagogiques de Wazi :
 * ce qui s'est passé, pourquoi, et comment corriger.
 *
 * Elle étend \InvalidArgumentException, comme l'exige PSR-7.
 */
final class InvalidUriException extends \InvalidArgumentException
{
    public static function unparsable(string $uri): self
    {
        return new self(sprintf(
            "« %s » n'est pas une URI valide. Une URI ne peut contenir ni espace ni certains"
            . ' caractères spéciaux : ils doivent être encodés (un espace devient %%20).'
            . ' Exemple valide : https://exemple.com/articles?page=2',
            ValuePreview::of($uri),
        ));
    }

    public static function invalidComponent(string $component, string $value): self
    {
        return new self(sprintf(
            "La valeur « %s » ne peut pas servir de %s : l'URI obtenue serait invalide."
            . ' Vérifiez les caractères utilisés (lettres, chiffres, « - », « . »).',
            ValuePreview::of($value),
            $component,
        ));
    }

    public static function portOutOfRange(int $port): self
    {
        return new self(sprintf(
            'Le port %d est hors limites : un port est un nombre compris entre 0 et 65535.'
            . ' Pour revenir au port par défaut du schéma, passez null.',
            $port,
        ));
    }

    public static function notAnUri(string $givenType): self
    {
        return new self(sprintf(
            'Une URI se donne sous forme de texte ou d\'objet UriInterface ; ici, c\'est « %s ».'
            . ' Exemple : \'https://exemple.com/articles\'.',
            $givenType,
        ));
    }
}
