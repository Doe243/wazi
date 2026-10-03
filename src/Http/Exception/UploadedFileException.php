<?php

declare(strict_types=1);

namespace Wazi\Http\Exception;

/**
 * Levée quand un fichier envoyé ne peut pas être lu ou déplacé.
 *
 * Chaque message suit la règle des erreurs pédagogiques de Wazi :
 * ce qui s'est passé, pourquoi, et comment corriger.
 *
 * Elle étend \RuntimeException, comme l'exige PSR-7.
 *
 * Sécurité (ADR-006) : aucun message ne cite le nom du fichier donné par le
 * navigateur ni un chemin du serveur.
 */
final class UploadedFileException extends \RuntimeException
{
    public static function uploadFailed(string $explanation): self
    {
        return new self(sprintf(
            'Ce fichier ne peut être ni lu ni déplacé, car son envoi a échoué : %s'
            . ' Vérifiez getError() avant d\'utiliser un fichier envoyé :'
            . ' if ($fichier->getError() === UPLOAD_ERR_OK) { ... }',
            $explanation,
        ));
    }

    public static function alreadyMoved(): self
    {
        return new self(
            'Ce fichier a déjà été déplacé avec moveTo() : il n\'est plus à son emplacement temporaire'
            . ' et ne peut être ni relu ni déplacé une seconde fois.'
            . ' Pour le relire, ouvrez-le à l\'endroit où vous l\'avez déplacé.',
        );
    }

    public static function sourceUnreadable(): self
    {
        return new self(
            'Le fichier envoyé est introuvable ou illisible à son emplacement temporaire.'
            . ' PHP supprime les fichiers temporaires à la fin de la requête : un fichier envoyé'
            . ' doit être déplacé avec moveTo() pendant la requête qui l\'a reçu.',
        );
    }

    public static function targetDirectoryUnusable(): self
    {
        return new self(
            'Impossible de déplacer le fichier : le dossier de destination n\'existe pas'
            . ' ou PHP n\'a pas le droit d\'y écrire. Créez le dossier (mkdir) et vérifiez ses droits.',
        );
    }

    public static function moveFailed(): self
    {
        return new self(
            'Le déplacement du fichier a échoué. Vérifiez que la destination est un chemin de fichier'
            . ' (pas un dossier), que le disque n\'est pas plein, et que le fichier vient bien'
            . ' d\'un envoi reçu par PHP pendant cette requête.',
        );
    }
}
