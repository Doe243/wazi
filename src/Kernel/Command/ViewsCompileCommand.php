<?php

declare(strict_types=1);

namespace Wazi\Kernel\Command;

use Wazi\Console\Application;
use Wazi\Console\Command;
use Wazi\Console\Input;
use Wazi\Console\Output;
use Wazi\View\Exception\KiooException;
use Wazi\View\Kioo;

/**
 * « wazi views:compile » : prépare les templates à l'avance, pour la mise en ligne.
 *
 * Sans cette commande, chaque template est analysé à chaque requête : c'est
 * ce qui convient pendant qu'on développe. En ligne, on la lance une fois, au
 * déploiement : chaque template est analysé ici, et son arbre est écrit dans
 * un fichier que PHP garde en mémoire. Les pages s'affichent alors bien plus vite.
 *
 * Elle vérifie au passage TOUS les templates : une faute dans l'un d'eux
 * arrête la commande, avant que le site ne soit mis en ligne.
 *
 * Sécurité (ADR-030) : les fichiers écrits sont du PHP. En ligne, le dossier
 * où ils se trouvent ne doit pas être inscriptible par le serveur web ; sinon
 * Wazi refuse de s'en servir, et analyse les templates comme d'habitude.
 */
final readonly class ViewsCompileCommand implements Command
{
    public function __construct(private Kioo $kioo) {}

    public function name(): string
    {
        return 'views:compile';
    }

    public function description(): string
    {
        return 'Prépare les templates à l\'avance, pour la mise en ligne : ils ne sont plus analysés à chaque requête.';
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
            $result = $this->kioo->compileAll();
        } catch (KiooException $exception) {
            // Un template mal écrit, ou un dossier non réglé : le message dit lequel.
            $output->error($exception->getMessage());

            return Application::FAILURE;
        }

        foreach ($result['compiled'] as $name) {
            $output->line('  ' . $name);
        }

        $output->line();
        $output->success(count($result['compiled']) . ' template(s) préparé(s).');

        if ($result['removed'] > 0) {
            $output->line('    ' . $result['removed'] . ' fichier(s) périmé(s) supprimé(s) : leur template n\'existe plus.');
        }

        $output->line();
        $output->line('En ligne, le dossier des templates préparés ne doit pas être inscriptible par le serveur web :');
        $output->line('sinon Wazi refuse de s\'en servir, et analyse les templates à chaque requête.');
        $output->line('Après avoir modifié un template, relancez cette commande.');

        return Application::SUCCESS;
    }
}
