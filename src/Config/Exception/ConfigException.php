<?php

declare(strict_types=1);

namespace Wazi\Config\Exception;

/**
 * Levée quand la configuration est absente, mal écrite ou mal placée.
 *
 * Sécurité (ADR-006 et ADR-017) : aucun de ces messages ne contient une valeur
 * de configuration, ni le contenu d'une ligne du fichier. On y trouve le nom
 * d'une clé, un numéro de ligne, un chemin de fichier : de quoi corriger, sans
 * qu'un mot de passe se retrouve dans un journal ou sur un écran.
 */
final class ConfigException extends \RuntimeException
{
    // ------------------------------------------------------------------
    // Lecture d'une valeur
    // ------------------------------------------------------------------

    public static function missingKey(string $key, string $file, bool $fileExists): self
    {
        return new self(sprintf(
            'La configuration ne contient pas « %s » : aucune variable d\'environnement ne porte ce nom, et %s'
            . ' Ajoutez une ligne « %s=... » au fichier, définissez la variable d\'environnement chez votre'
            . ' hébergeur, ou donnez une valeur par défaut dans le code : $config->string(\'%s\', \'valeur par défaut\').',
            $key,
            $fileExists
                ? sprintf('le fichier %s ne la définit pas.', $file)
                : sprintf('le fichier %s n\'existe pas (on le crée souvent en copiant .env.example).', $file),
            $key,
            $key,
        ));
    }

    public static function invalidKeyName(): self
    {
        return new self(
            'Ce nom de clé de configuration est invalide. Un nom s\'écrit en majuscules, avec des chiffres'
            . ' et des « _ », et commence par une lettre : APP_NAME, DATABASE_URL, MAIL_PORT.',
        );
    }

    public static function reservedKeyName(string $key): self
    {
        return new self(sprintf(
            'La clé de configuration « %s » commence par « HTTP_ », un préfixe réservé. Sur certains serveurs,'
            . ' chaque en-tête envoyé par un visiteur devient une variable de ce nom : il pourrait donc choisir'
            . ' la valeur de ce réglage. Renommez la clé, par exemple avec le préfixe « APP_ ».',
            $key,
        ));
    }

    public static function notAnInteger(string $key): self
    {
        return new self(sprintf(
            'La valeur de « %s » dans la configuration n\'est pas un nombre entier. Écrivez un nombre'
            . ' sans guillemets, sans espace et sans unité : %s=20.',
            $key,
            $key,
        ));
    }

    public static function notABoolean(string $key): self
    {
        return new self(sprintf(
            'La valeur de « %s » dans la configuration n\'est ni « true » ni « false ». Ce sont les deux'
            . ' seules valeurs acceptées pour un réglage oui/non : %s=true ou %s=false.',
            $key,
            $key,
            $key,
        ));
    }

    // ------------------------------------------------------------------
    // Fichier .env
    // ------------------------------------------------------------------

    public static function invalidLine(string $file, int $line, string $reason): self
    {
        return new self(sprintf(
            'Le fichier %s est mal écrit à la ligne %d : %s Une ligne s\'écrit NOM=valeur, le nom en majuscules ;'
            . ' une ligne qui commence par « # » est un commentaire.',
            $file,
            $line,
            $reason,
        ));
    }

    public static function duplicateKey(string $file, string $key, int $firstLine, int $secondLine): self
    {
        return new self(sprintf(
            'Dans le fichier %s, la clé « %s » est définie deux fois, aux lignes %d et %d : on ne saurait pas'
            . ' laquelle est la bonne. Supprimez l\'une des deux.',
            $file,
            $key,
            $firstLine,
            $secondLine,
        ));
    }

    public static function publiclyAccessible(string $file): self
    {
        return new self(sprintf(
            'Le fichier %s se trouve dans le dossier public de votre site : n\'importe qui pourrait le télécharger'
            . ' en tapant son adresse, et lire vos mots de passe. Wazi refuse de l\'utiliser. Déplacez-le un cran'
            . ' au-dessus du dossier public (à côté de composer.json), et indiquez ce nouveau chemin.',
            $file,
        ));
    }

    public static function unreadable(string $file): self
    {
        return new self(sprintf(
            'Le fichier de configuration %s existe, mais ne peut pas être lu : c\'est un dossier, il dépasse'
            . ' la taille maximale (%d Ko), ou PHP n\'a pas le droit de le lire. Vérifiez ses droits d\'accès.',
            $file,
            intdiv(\Wazi\Config\EnvFile::MAX_SIZE, 1024),
        ));
    }

    public static function notALocalPath(): self
    {
        return new self(
            'Le chemin du fichier de configuration n\'est pas un simple chemin de fichier : il est vide, commence'
            . ' par un protocole (comme « php:// » ou « http:// ») ou contient un caractère de contrôle.'
            . ' Donnez un chemin ordinaire, par exemple __DIR__ . \'/../.env\'.',
        );
    }
}
