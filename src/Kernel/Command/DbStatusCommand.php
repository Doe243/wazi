<?php

declare(strict_types=1);

namespace Wazi\Kernel\Command;

use Wazi\Console\Application;
use Wazi\Console\Command;
use Wazi\Console\Input;
use Wazi\Console\Output;
use Wazi\Database\Exception\DatabaseException;
use Wazi\Database\Migrator;

/**
 * « wazi db:status » : où en est chaque migration.
 *
 *     20261004_153000_creer_notes.sql                  faite            2026-10-04 15:31:02
 *     20261012_091500_ajouter_couleur_aux_notes.sql    à faire
 *
 * Quatre états possibles :
 *   - faite : appliquée, et le fichier n'a pas changé depuis ;
 *   - à faire : « wazi db:migrate » l'appliquera ;
 *   - modifiée depuis : le fichier a changé après avoir été appliqué, la base ne le sait pas ;
 *   - fichier disparu : appliquée un jour, mais le fichier n'est plus dans le dossier.
 *
 * Cette commande ne modifie rien, sauf la toute première fois : elle crée
 * alors la table où Wazi note les migrations faites.
 */
final readonly class DbStatusCommand implements Command
{
    public function __construct(private Migrator $migrator) {}

    public function name(): string
    {
        return 'db:status';
    }

    public function description(): string
    {
        return 'Montre les migrations faites et celles qui restent à appliquer.';
    }

    public function arguments(): array
    {
        return [];
    }

    public function options(): array
    {
        return [];
    }

    public function run(Input $input, Output $output): int
    {
        try {
            $status = $this->migrator->status();
        } catch (DatabaseException $error) {
            $output->error($error->getMessage());

            return Application::FAILURE;
        }

        if ($status === []) {
            $output->line('Aucune migration pour l\'instant. Créez la première : wazi make:migration creer_notes');

            return Application::SUCCESS;
        }

        $nameWidth = 0;
        $stateWidth = 0;

        foreach ($status as $migration) {
            $nameWidth = max($nameWidth, mb_strlen($migration['name']));
            $stateWidth = max($stateWidth, mb_strlen($migration['state']));
        }

        $pending = 0;

        foreach ($status as $migration) {
            $pending += $migration['state'] === Migrator::PENDING ? 1 : 0;

            $output->line(rtrim(
                $migration['name'] . str_repeat(' ', $nameWidth - mb_strlen($migration['name']) + 4)
                . $migration['state'] . str_repeat(' ', $stateWidth - mb_strlen($migration['state']) + 4)
                . ($migration['appliedAt'] ?? ''),
            ));
        }

        $output->line();
        $output->line($pending === 0
            ? 'La base est à jour.'
            : $pending . ' migration(s) à faire : wazi db:migrate');

        return Application::SUCCESS;
    }
}
