<?php

declare(strict_types=1);

namespace Wazi\Http\Exception;

/**
 * Levée quand on essaie de créer un flux à partir d'autre chose qu'une
 * ressource de flux PHP.
 *
 * C'est une erreur dans le code appelant (un mauvais argument), d'où
 * \InvalidArgumentException. Les erreurs qui surviennent pendant
 * l'utilisation d'un flux sont, elles, des StreamException.
 */
final class InvalidStreamException extends \InvalidArgumentException
{
    /**
     * Sécurité (ADR-006) : le message ne reçoit que le TYPE de la valeur
     * refusée (« string », « int »...), jamais la valeur elle-même. Elle
     * pourrait contenir une donnée sensible ou de quoi falsifier un journal.
     */
    public static function notAStream(string $givenType): self
    {
        return new self(sprintf(
            'Un flux se construit à partir d\'une ressource de flux ouverte, pas à partir de « %s ».'
            . ' Wazi n\'ouvre jamais un fichier ou une adresse à votre place ici : ouvrez la ressource'
            . ' vous-même, par exemple new Stream(fopen($fichier, \'r\')), ou utilisez'
            . ' Stream::fromString($texte) pour un flux qui contient un texte.',
            $givenType,
        ));
    }

    public static function invalidMode(string $mode): self
    {
        return new self(sprintf(
            '« %s » n\'est pas un mode d\'ouverture de fichier. Les plus courants : « r » pour lire,'
            . ' « w » pour écrire en remplaçant le contenu, « a » pour écrire à la suite.'
            . ' Ajoutez « + » pour lire et écrire à la fois, par exemple « r+ ».',
            ValuePreview::of($mode),
        ));
    }
}
