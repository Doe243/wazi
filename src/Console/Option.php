<?php

declare(strict_types=1);

namespace Wazi\Console;

use Wazi\Console\Exception\ConsoleException;

/**
 * Une option d'une commande : ce qui s'écrit avec deux tirets.
 *
 * Il en existe deux sortes :
 *
 *     --port=8080     une option à valeur : new Option('port', 'Le port', '8000')
 *     --force         un drapeau, présent ou absent : new Option('force', 'Remplacer le fichier')
 *
 * Une option à valeur a toujours une valeur par défaut : c'est ce qui la
 * distingue d'un drapeau.
 */
final readonly class Option
{
    private const string NAME = '/^[a-z][a-z0-9-]*$/D';

    /**
     * @param string      $name        le nom, sans les tirets
     * @param string      $description une phrase, affichée par --help
     * @param string|null $default     la valeur quand l'option n'est pas écrite ; null : c'est un drapeau
     *
     * @throws ConsoleException si le nom est mal formé, ou réservé
     */
    public function __construct(public string $name, public string $description, public ?string $default = null)
    {
        if (preg_match(self::NAME, $name) !== 1) {
            throw ConsoleException::invalidName('une option', $name);
        }

        // --help appartient à la console : elle l'ajoute à toutes les commandes.
        if ($name === 'help') {
            throw ConsoleException::reservedOption($name);
        }
    }

    public function isFlag(): bool
    {
        return $this->default === null;
    }
}
