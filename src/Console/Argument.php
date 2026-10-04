<?php

declare(strict_types=1);

namespace Wazi\Console;

use Wazi\Console\Exception\ConsoleException;

/**
 * Un argument d'une commande : une valeur écrite après son nom, sans tirets.
 *
 *     wazi make:controller Article
 *                          └─ l'argument « nom »
 *
 * Sans valeur par défaut, l'argument est obligatoire.
 */
final readonly class Argument
{
    private const string NAME = '/^[a-z][a-z0-9-]*$/D';

    /**
     * @param string      $name        le nom par lequel la commande le lit : $input->argument('nom')
     * @param string      $description une phrase, affichée par --help
     * @param string|null $default     la valeur quand l'argument n'est pas écrit ; null : il est obligatoire
     *
     * @throws ConsoleException si le nom est mal formé
     */
    public function __construct(public string $name, public string $description, public ?string $default = null)
    {
        if (preg_match(self::NAME, $name) !== 1) {
            throw ConsoleException::invalidName('un argument', $name);
        }
    }
}
