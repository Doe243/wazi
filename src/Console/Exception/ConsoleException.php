<?php

declare(strict_types=1);

namespace Wazi\Console\Exception;

use Wazi\Http\Exception\ValuePreview;

/**
 * Levée quand une commande est mal écrite ou mal utilisée.
 *
 * Chaque message dit ce qui ne va pas et ce qu'il faut taper à la place.
 *
 * Sécurité (ADR-026) : ce que l'utilisateur a tapé est nettoyé avant d'entrer
 * dans un message (ValuePreview), pour qu'une valeur piégée ne puisse rien
 * glisser dans le terminal ni dans un journal.
 */
final class ConsoleException extends \RuntimeException
{
    // ------------------------------------------------------------------
    // Erreurs de celui qui tape la commande
    // ------------------------------------------------------------------

    /**
     * @param list<string> $available
     * @param string       $console   ce qu'on tape pour lancer la console : « wazi », « php wazi »
     */
    public static function unknownCommand(string $name, array $available, string $console): self
    {
        return new self(sprintf(
            'La commande « %s » n\'existe pas.%s Tapez « %s » sans rien d\'autre pour voir la liste des commandes.',
            ValuePreview::of($name),
            self::suggestion($name, $available),
            $console,
        ));
    }

    /**
     * @param list<string> $available
     */
    public static function unknownOption(string $name, string $command, array $available, string $console): self
    {
        return new self(sprintf(
            'La commande « %s » n\'a pas d\'option « --%s ».%s Tapez « %s %s --help » pour voir ses options.',
            $command,
            ValuePreview::of($name),
            self::suggestion($name, $available, '--'),
            $console,
            $command,
        ));
    }

    public static function shortOption(string $token, string $command, string $console): self
    {
        return new self(sprintf(
            '« %s » n\'est pas une écriture reconnue. Une option s\'écrit avec deux tirets et son nom entier :'
            . ' --port=8080. Tapez « %s %s --help » pour voir les options de cette commande.',
            ValuePreview::of($token),
            $console,
            $command,
        ));
    }

    public static function flagWithValue(string $name): self
    {
        return new self(sprintf(
            'L\'option « --%s » ne prend pas de valeur : elle est présente ou absente. Écrivez seulement « --%s ».',
            $name,
            $name,
        ));
    }

    public static function optionWithoutValue(string $name, string $example): self
    {
        return new self(sprintf(
            'L\'option « --%s » attend une valeur, collée par un signe égal : --%s=%s.',
            $name,
            $name,
            $example !== '' ? $example : 'valeur',
        ));
    }

    public static function missingArgument(string $name, string $command, string $console): self
    {
        return new self(sprintf(
            'Il manque l\'argument « %s » à la commande « %s ». Tapez « %s %s --help » pour voir comment l\'écrire.',
            $name,
            $command,
            $console,
            $command,
        ));
    }

    public static function tooManyArguments(string $command, int $expected, int $given, string $console): self
    {
        return new self(sprintf(
            'La commande « %s » attend %d argument(s), et en a reçu %d. Une valeur qui contient des espaces'
            . ' s\'écrit entre guillemets. Tapez « %s %s --help » pour voir comment l\'écrire.',
            $command,
            $expected,
            $given,
            $console,
            $command,
        ));
    }

    public static function notACommandLine(string $sapi): self
    {
        return new self(sprintf(
            'La console de Wazi ne s\'exécute que dans un terminal, et elle a été appelée par « %s ». Elle ne doit'
            . ' jamais être accessible par un navigateur : elle donnerait à un visiteur les pouvoirs du développeur.',
            ValuePreview::of($sapi),
        ));
    }

    // ------------------------------------------------------------------
    // Erreurs de celui qui écrit une commande
    // ------------------------------------------------------------------

    /**
     * @param string $what ce qui est nommé : « une commande », « un argument », « une option »
     */
    public static function invalidName(string $what, string $name): self
    {
        return new self(sprintf(
            'Le nom « %s » ne convient pas pour %s. Un nom s\'écrit en minuscules, commence par une lettre, et ne'
            . ' contient que des lettres sans accent, des chiffres et des tirets. Pour une commande, un « : » peut'
            . ' séparer un groupe de son action, comme dans « make:controller ».',
            ValuePreview::of($name),
            $what,
        ));
    }

    public static function reservedOption(string $name): self
    {
        return new self(sprintf(
            'L\'option « --%s » est réservée à la console, qui l\'ajoute elle-même à chaque commande. Choisissez un autre nom.',
            $name,
        ));
    }

    public static function duplicateCommand(string $name): self
    {
        return new self(sprintf(
            'La commande « %s » est déjà déclarée. Deux commandes ne peuvent pas porter le même nom.',
            $name,
        ));
    }

    /**
     * @param string $what   ce qui est lu : « l'argument », « l'option », « le drapeau »
     * @param string $method la méthode de la commande où il aurait dû être déclaré
     */
    public static function undeclared(string $what, string $name, string $method): self
    {
        return new self(sprintf(
            'La commande lit %s « %s » sans l\'avoir déclaré. Ajoutez-le à sa méthode %s.',
            $what,
            ValuePreview::of($name),
            $method,
        ));
    }

    /**
     * @param list<string> $available
     */
    private static function suggestion(string $name, array $available, string $prefix = ''): string
    {
        $closest = null;
        $shortest = 3;

        foreach ($available as $candidate) {
            // levenshtein() compte les lettres à changer pour passer d'un mot à l'autre.
            $distance = levenshtein(substr($name, 0, 60), $candidate);

            if ($distance < $shortest) {
                $shortest = $distance;
                $closest = $candidate;
            }
        }

        return $closest !== null ? ' Vouliez-vous écrire « ' . $prefix . $closest . ' » ?' : '';
    }
}
