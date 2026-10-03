<?php

declare(strict_types=1);

namespace Wazi\Middleware\Exception;

/**
 * Levée quand les sessions ne peuvent pas être conservées.
 */
final class SessionStoreException extends \RuntimeException
{
    public static function publiclyAccessible(): self
    {
        return new self(
            'Le dossier des sessions se trouve dans le dossier public de votre site : n\'importe qui pourrait y'
            . ' télécharger les sessions de vos visiteurs et prendre leur place. Wazi refuse de l\'utiliser.'
            . ' Choisissez un dossier situé au-dessus du dossier public, par exemple var/sessions à côté de composer.json.',
        );
    }

    public static function notWritable(): self
    {
        return new self(
            'Le dossier des sessions n\'existe pas et n\'a pas pu être créé, ou PHP n\'a pas le droit d\'y écrire.'
            . ' Créez-le et vérifiez ses droits d\'accès.',
        );
    }
}
