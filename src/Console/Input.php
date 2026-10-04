<?php

declare(strict_types=1);

namespace Wazi\Console;

use Wazi\Console\Exception\ConsoleException;

/**
 * Ce que l'utilisateur a écrit après le nom de la commande, vérifié et rangé.
 *
 *     wazi serve --port=8080
 *
 *     $input->option('port');      '8080'
 *     $input->flag('force');       false
 *
 * Une seule écriture est acceptée (ADR-026) :
 *
 *     valeur            un argument, dans l'ordre déclaré par la commande
 *     --nom=valeur      une option à valeur
 *     --nom             un drapeau
 *     --                tout ce qui suit est un argument, même s'il commence par « -- »
 *
 * Tout ce que la commande n'a pas déclaré est refusé ici, avec une
 * explication : la commande ne reçoit que ce qu'elle attend.
 */
final readonly class Input
{
    /**
     * @param array<string, string> $arguments nom => valeur
     * @param array<string, string> $options   nom => valeur, pour les options à valeur
     * @param array<string, bool>   $flags     nom => présent ou non, pour les drapeaux
     */
    private function __construct(private array $arguments, private array $options, private array $flags) {}

    /**
     * @param list<string> $tokens  ce qui a été écrit après le nom de la commande
     * @param string       $console ce qu'on tape pour lancer la console, cité dans les messages d'erreur
     *
     * @throws ConsoleException si un argument manque ou est en trop, si une option est inconnue ou mal écrite
     */
    public static function parse(Command $command, array $tokens, string $console = 'wazi'): self
    {
        $declared = [];

        foreach ($command->options() as $option) {
            $declared[$option->name] = $option;
        }

        $values = [];
        $options = [];
        $flags = [];
        $onlyArguments = false;

        foreach ($tokens as $token) {
            if (!$onlyArguments && $token === '--') {
                $onlyArguments = true;

                continue;
            }

            if ($onlyArguments || !str_starts_with($token, '-') || $token === '-') {
                $values[] = $token;

                continue;
            }

            // Une seule écriture : deux tirets. « -p » ou « -port » sont refusés
            // plutôt que devinés.
            if (!str_starts_with($token, '--')) {
                throw ConsoleException::shortOption($token, $command->name(), $console);
            }

            // « --port=8080 » : le nom avant le premier signe égal, la valeur après.
            $pair = explode('=', substr($token, 2), 2);
            $name = $pair[0];
            $value = $pair[1] ?? null;
            $option = $declared[$name] ?? throw ConsoleException::unknownOption($name, $command->name(), array_keys($declared), $console);

            if ($option->isFlag()) {
                if ($value !== null) {
                    throw ConsoleException::flagWithValue($name);
                }

                $flags[$name] = true;

                continue;
            }

            if ($value === null) {
                throw ConsoleException::optionWithoutValue($name, (string) $option->default);
            }

            $options[$name] = $value;
        }

        foreach ($declared as $name => $option) {
            if ($option->isFlag()) {
                $flags[$name] ??= false;
            } else {
                $options[$name] ??= (string) $option->default;
            }
        }

        return new self(self::arguments($command, $values, $console), $options, $flags);
    }

    /**
     * La valeur d'un argument.
     *
     * @throws ConsoleException si la commande n'a pas déclaré cet argument
     */
    public function argument(string $name): string
    {
        return $this->arguments[$name] ?? throw ConsoleException::undeclared('l\'argument', $name, 'arguments()');
    }

    /**
     * La valeur d'une option à valeur : celle qui a été écrite, ou sa valeur par défaut.
     *
     * @throws ConsoleException si la commande n'a pas déclaré cette option
     */
    public function option(string $name): string
    {
        return $this->options[$name] ?? throw ConsoleException::undeclared('l\'option', $name, 'options()');
    }

    /**
     * Vrai si le drapeau a été écrit.
     *
     * @throws ConsoleException si la commande n'a pas déclaré ce drapeau
     */
    public function flag(string $name): bool
    {
        return $this->flags[$name] ?? throw ConsoleException::undeclared('le drapeau', $name, 'options()');
    }

    /**
     * Range les valeurs écrites dans les arguments déclarés, dans l'ordre.
     *
     * @param list<string> $values
     *
     * @return array<string, string>
     */
    private static function arguments(Command $command, array $values, string $console): array
    {
        $arguments = [];

        foreach ($command->arguments() as $position => $argument) {
            $arguments[$argument->name] = $values[$position]
                ?? $argument->default
                ?? throw ConsoleException::missingArgument($argument->name, $command->name(), $console);
        }

        if (count($values) > count($arguments)) {
            throw ConsoleException::tooManyArguments($command->name(), count($arguments), count($values), $console);
        }

        return $arguments;
    }
}
