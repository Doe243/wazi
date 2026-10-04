<?php

declare(strict_types=1);

namespace Wazi\Tests\Console\Fixtures;

use Wazi\Console\Argument;
use Wazi\Console\Command;
use Wazi\Console\Input;
use Wazi\Console\Option;
use Wazi\Console\Output;

/**
 * Une commande d'essai : « bonjour <nom> [formule] --fort --fois=2 ».
 */
final class GreetCommand implements Command
{
    public function __construct(private readonly string $name = 'bonjour') {}

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return 'Salue quelqu\'un.';
    }

    public function arguments(): array
    {
        return [
            new Argument('nom', 'Qui saluer'),
            new Argument('formule', 'Le mot d\'accueil', 'Bonjour'),
        ];
    }

    public function options(): array
    {
        return [
            new Option('fort', 'Écrire en majuscules'),
            new Option('fois', 'Combien de fois', '1'),
        ];
    }

    public function run(Input $input, Output $output): int
    {
        if ($input->argument('nom') === 'panne') {
            throw new \RuntimeException('La commande est tombée en panne.');
        }

        $text = $input->argument('formule') . ' ' . $input->argument('nom') . ' !';

        for ($turn = 0; $turn < (int) $input->option('fois'); $turn++) {
            $output->line($input->flag('fort') ? mb_strtoupper($text) : $text);
        }

        return $input->argument('nom') === 'personne' ? 3 : 0;
    }
}
