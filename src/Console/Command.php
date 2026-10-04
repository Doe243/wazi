<?php

declare(strict_types=1);

namespace Wazi\Console;

/**
 * Une commande de la console : ce qui s'exécute quand on tape « wazi nom ».
 *
 *     final class BonjourCommand implements Command
 *     {
 *         public function name(): string { return 'bonjour'; }
 *
 *         public function description(): string { return 'Salue quelqu\'un.'; }
 *
 *         public function arguments(): array { return [new Argument('nom', 'Qui saluer')]; }
 *
 *         public function options(): array { return [new Option('fort', 'Écrire en majuscules')]; }
 *
 *         public function run(Input $input, Output $output): int
 *         {
 *             $texte = 'Bonjour ' . $input->argument('nom') . ' !';
 *             $output->line($input->flag('fort') ? mb_strtoupper($texte) : $texte);
 *
 *             return 0;
 *         }
 *     }
 *
 *     wazi bonjour Alice --fort        BONJOUR ALICE !
 *
 * Une commande déclare ce qu'elle accepte. Tout le reste est refusé avant
 * même qu'elle s'exécute : elle n'a pas à s'en défendre.
 */
interface Command
{
    /**
     * Le nom à taper : « serve », « make:controller ». Minuscules, chiffres, « - » et « : ».
     */
    public function name(): string;

    /**
     * Une phrase, affichée dans la liste des commandes.
     */
    public function description(): string;

    /**
     * Ce qui s'écrit après le nom, dans l'ordre.
     *
     * @return list<Argument>
     */
    public function arguments(): array;

    /**
     * Ce qui s'écrit avec deux tirets : --port=8000, --force.
     *
     * @return list<Option>
     */
    public function options(): array;

    /**
     * @return int le code de sortie : 0 si tout s'est bien passé, un autre nombre sinon
     */
    public function run(Input $input, Output $output): int;
}
