<?php

declare(strict_types=1);

namespace Wazi\Http\Exception;

/**
 * Levée quand un fichier envoyé est créé ou déplacé avec un mauvais argument.
 *
 * C'est une erreur dans le code appelant, d'où \InvalidArgumentException.
 * Les échecs qui surviennent pendant l'utilisation du fichier sont, eux,
 * des UploadedFileException.
 *
 * Sécurité (ADR-006) : aucun message ne cite le chemin refusé. Il peut venir
 * d'une requête, et n'a rien à faire dans un journal ou sur un écran.
 */
final class InvalidUploadedFileException extends \InvalidArgumentException
{
    public static function unknownErrorCode(int $error): self
    {
        return new self(sprintf(
            '%d n\'est pas un code d\'erreur d\'envoi de fichier. Utilisez une des constantes UPLOAD_ERR_* de PHP,'
            . ' par exemple UPLOAD_ERR_OK quand l\'envoi a réussi. Dans $_FILES, c\'est la valeur de la clé « error ».',
            $error,
        ));
    }

    public static function negativeSize(int $size): self
    {
        return new self(sprintf(
            'La taille d\'un fichier ne peut pas être négative (%d reçu). Passez la taille en octets,'
            . ' ou null si elle est inconnue.',
            $size,
        ));
    }

    public static function emptyPath(): self
    {
        return new self(
            'Le chemin du fichier est vide. Indiquez où se trouve le fichier ou où le déplacer,'
            . ' par exemple __DIR__ . \'/../storage/avatars/42.png\'.',
        );
    }

    public static function notALocalPath(): self
    {
        return new self(
            'Ce chemin n\'est pas un simple chemin de fichier : il commence par un protocole (comme « phar:// »,'
            . ' « php:// » ou « http:// ») ou contient un caractère de contrôle. Wazi le refuse, car ces adresses'
            . ' permettent de lire ou d\'exécuter autre chose qu\'un fichier du disque.'
            . ' Utilisez un chemin de fichier ordinaire.',
        );
    }
}
