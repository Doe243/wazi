<?php

declare(strict_types=1);

namespace Wazi\Kernel\Command;

use Wazi\Console\Application;
use Wazi\Console\DetailedCommand;
use Wazi\Console\Input;
use Wazi\Console\Output;
use Wazi\Database\Exception\DatabaseException;
use Wazi\Database\Migrator;

/**
 * « wazi db:migrate » : applique les migrations qui ne l'ont pas encore été.
 *
 *     OK  20261004_153000_creer_notes.sql
 *     OK  20261012_091500_ajouter_couleur_aux_notes.sql
 *
 *     2 migration(s) appliquée(s).
 *
 * À lancer après avoir créé ou récupéré une migration, et à chaque mise en
 * ligne. La relancer ne fait rien de plus : une migration ne s'applique qu'une fois.
 *
 * Cette commande vit dans la couche d'assemblage (à côté du Kernel) : c'est
 * la seule qui a le droit de connaître à la fois la console et la base (ADR-027).
 *
 * Sécurité (ADR-032) : la structure de la base ne se modifie que d'ici, dans
 * un terminal. Aucune adresse du site ne déclenche une migration.
 */
final readonly class DbMigrateCommand implements DetailedCommand
{
    public function __construct(private Migrator $migrator) {}

    public function name(): string
    {
        return 'db:migrate';
    }

    public function description(): string
    {
        return 'Applique les migrations en attente : crée ou modifie les tables de la base.';
    }

    public function arguments(): array
    {
        return [];
    }

    public function options(): array
    {
        return [];
    }

    public function help(): string
    {
        return implode("\n", [
            'Applique, dans l\'ordre de leur nom, les fichiers du dossier migrations/ qui',
            'ne l\'ont pas encore été. Relancer la commande ne fait rien de plus.',
            '',
            'À lancer après avoir créé ou récupéré une migration, et à chaque mise en ligne.',
            '',
            'Si une migration échoue, SQLite et PostgreSQL la défont entièrement. MySQL',
            'garde ce qui a déjà été appliqué : le message le dit.',
        ]);
    }

    public function examples(): array
    {
        return [
            '' => 'Met la base à jour',
        ];
    }

    public function run(Input $input, Output $output): int
    {
        try {
            $modified = array_filter($this->migrator->status(), static fn(array $migration): bool => $migration['state'] === Migrator::MODIFIED);
            $applied = $this->migrator->migrate(static fn(string $name) => $output->success($name));
        } catch (DatabaseException $error) {
            $output->error($error->getMessage());

            return Application::FAILURE;
        }

        if ($applied === []) {
            $output->line('La base est à jour : aucune migration en attente.');
        } else {
            $output->line();
            $output->line(count($applied) . ' migration(s) appliquée(s).');
        }

        foreach ($modified as $migration) {
            $output->warning(
                $migration['name'] . ' a été modifiée après avoir été appliquée : ce changement n\'est PAS dans la'
                . ' base. Une migration ne s\'applique qu\'une fois ; écrivez le changement dans une nouvelle'
                . ' migration (wazi make:migration ...).',
            );
        }

        return Application::SUCCESS;
    }
}
