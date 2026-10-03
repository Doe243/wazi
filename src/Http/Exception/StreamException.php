<?php

declare(strict_types=1);

namespace Wazi\Http\Exception;

/**
 * Levée quand une opération sur un flux échoue : lecture, écriture,
 * déplacement, ou flux qui n'est plus utilisable.
 *
 * Chaque message suit la règle des erreurs pédagogiques de Wazi :
 * ce qui s'est passé, pourquoi, et comment corriger.
 *
 * Elle étend \RuntimeException, comme l'exige PSR-7.
 */
final class StreamException extends \RuntimeException
{
    public static function detached(string $operation): self
    {
        return new self(sprintf(
            'Impossible de %s : ce flux n\'est plus relié à aucune ressource. Il a été fermé'
            . ' (close()), détaché (detach()), ou sa ressource a été fermée ailleurs avec fclose().'
            . ' Créez un nouveau flux, par exemple avec Stream::fromString().',
            $operation,
        ));
    }

    public static function notReadable(string $mode): self
    {
        return new self(sprintf(
            'Ce flux ne peut pas être lu : il a été ouvert en mode « %s », qui n\'autorise pas la lecture.'
            . ' Ouvrez la ressource avec un mode de lecture, par exemple fopen($fichier, \'r\').',
            $mode,
        ));
    }

    public static function notWritable(string $mode): self
    {
        return new self(sprintf(
            'Ce flux ne peut pas être écrit : il a été ouvert en mode « %s », qui n\'autorise pas l\'écriture.'
            . ' Ouvrez la ressource avec un mode d\'écriture, par exemple fopen($fichier, \'r+\').',
            $mode,
        ));
    }

    public static function notSeekable(): self
    {
        return new self(
            'Ce flux ne permet pas de se déplacer dans son contenu : il se lit ou s\'écrit'
            . ' une seule fois, dans l\'ordre (c\'est le cas d\'une sortie ou d\'une connexion réseau).'
            . ' Vérifiez avec isSeekable() avant d\'appeler seek() ou rewind().',
        );
    }

    public static function negativeLength(int $length): self
    {
        return new self(sprintf(
            'Impossible de lire %d octets : la longueur à lire doit être un nombre positif ou nul.',
            $length,
        ));
    }

    public static function operationFailed(string $operation): self
    {
        return new self(sprintf(
            'Impossible de %s : PHP a signalé un échec sur la ressource du flux.'
            . ' Vérifiez que la ressource est toujours ouverte et que la position demandée existe.',
            $operation,
        ));
    }

    public static function temporaryStreamUnavailable(): self
    {
        return new self(
            'Impossible de créer un flux temporaire (php://temp). Vérifiez que PHP peut écrire'
            . ' dans son dossier temporaire (réglage sys_temp_dir du php.ini).',
        );
    }

    /**
     * Sécurité (ADR-006) : le message ne cite pas le chemin refusé, qui peut venir d'une requête.
     */
    public static function notALocalFile(): self
    {
        return new self(
            'Ce chemin n\'est pas un simple chemin de fichier : il est vide, commence par un protocole'
            . ' (comme « php:// », « phar:// » ou « http:// ») ou contient un caractère de contrôle.'
            . ' Wazi le refuse, car ces adresses permettent de lire ou d\'exécuter autre chose qu\'un fichier'
            . ' du disque. Si vous avez vraiment besoin d\'une telle adresse, ouvrez-la vous-même :'
            . ' new Stream(fopen(\'php://temp\', \'r+\')).',
        );
    }

    public static function cannotOpenFile(string $mode): self
    {
        return new self(sprintf(
            'Impossible d\'ouvrir le fichier en mode « %s ». Pour une lecture, vérifiez que le fichier existe'
            . ' et que PHP a le droit de le lire ; pour une écriture, que son dossier existe'
            . ' et que PHP a le droit d\'y écrire.',
            $mode,
        ));
    }
}
