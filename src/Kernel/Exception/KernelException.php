<?php

declare(strict_types=1);

namespace Wazi\Kernel\Exception;

/**
 * Levée quand l'application d'un projet ne peut pas être chargée.
 * C'est une erreur dans l'organisation du projet, à corriger une fois.
 */
final class KernelException extends \RuntimeException
{
    public static function applicationFileNotFound(string $file): self
    {
        return new self(sprintf(
            'Le fichier de l\'application est introuvable : %s. Ce fichier (app.php, à la racine du projet) construit'
            . ' votre application et la retourne. Vérifiez qu\'il existe, et que le chemin donné à Kernel::load() est le bon.',
            $file,
        ));
    }

    public static function applicationNotReturned(string $file, string $givenType): self
    {
        return new self(sprintf(
            'Le fichier %s ne retourne pas l\'application : il a rendu une valeur de type %s. Il doit se terminer'
            . ' par « return $app; », où $app est le Kernel que vous y avez créé.',
            $file,
            $givenType,
        ));
    }
}
