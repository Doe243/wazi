<?php

declare(strict_types=1);

namespace Wazi\Console\Command;

use Wazi\Console\Application;
use Wazi\Console\DetailedCommand;
use Wazi\Console\Input;
use Wazi\Console\Output;

/**
 * « wazi zones:install » : copie le script des zones mises à jour dans le
 * dossier public du projet.
 *
 *     public/wazi.js
 *
 * Ce script est ce qui permet à un formulaire ou à un lien marqué k:update de
 * ne remplacer que des morceaux de la page (k:zone), sans la recharger. C'est
 * un fichier ordinaire de votre projet : vous pouvez l'ouvrir et le lire.
 *
 * Après une mise à jour de Wazi, relancez la commande : elle remplace le
 * fichier par sa nouvelle version.
 *
 * Sécurité (ADR-036) :
 *   - la commande n'écrit qu'un seul fichier, dont le nom est fixé ici ;
 *   - elle ne remplace que SON fichier, reconnu à sa première ligne. Un autre
 *     fichier nommé wazi.js, écrit par vous, n'est jamais touché ;
 *   - elle n'écrit pas à travers un lien symbolique.
 */
final readonly class ZonesInstallCommand implements DetailedCommand
{
    private const string FILE = 'public/wazi.js';

    /** Le début de la première ligne du script : ce qui le fait reconnaître. */
    private const string SIGNATURE = '/*! wazi.js : les zones mises à jour de Wazi.';

    /** La ligne à écrire dans la mise en page. */
    private const string SCRIPT_TAG = '<script src="/wazi.js" defer></script>';

    private string $source;

    /**
     * @param string|null $directory le dossier du projet ; celui où la commande est tapée, par défaut
     * @param string|null $source    le script à copier ; celui que Wazi fournit, par défaut
     */
    public function __construct(private ?string $directory = null, ?string $source = null)
    {
        $this->source = $source ?? dirname(__DIR__, 3) . '/resources/wazi.js';
    }

    public function name(): string
    {
        return 'zones:install';
    }

    public function description(): string
    {
        return 'Installe public/wazi.js : le script qui met à jour des morceaux de page sans la recharger.';
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
            'Copie le script de Wazi dans public/wazi.js. Ajoutez ensuite une ligne à',
            'votre mise en page, avant </head> :',
            '',
            '    ' . self::SCRIPT_TAG,
            '',
            'Dans vos templates, k:zone marque un morceau de page, et k:update, sur un',
            'formulaire ou un lien, dit quelles zones il met à jour.',
            '',
            'Relancez la commande après une mise à jour de Wazi. Un fichier wazi.js que',
            'vous auriez écrit vous-même n\'est jamais remplacé.',
        ]);
    }

    public function examples(): array
    {
        return ['' => 'Crée public/wazi.js, ou le met à jour'];
    }

    public function run(Input $input, Output $output): int
    {
        $project = $this->directory ?? (string) getcwd();

        if (!is_dir($project . '/public')) {
            $output->error(
                'Le dossier public/ est introuvable ici. Lancez cette commande depuis le dossier de votre projet,'
                . ' celui qui contient app.php et le dossier public/.',
            );

            return Application::FAILURE;
        }

        $script = is_file($this->source) ? file_get_contents($this->source) : false;

        if ($script === false || !str_starts_with($script, self::SIGNATURE)) {
            $output->error(
                'Le script de Wazi est introuvable dans le dossier du framework. Réinstallez les bibliothèques'
                . ' avec « composer install », puis relancez cette commande.',
            );

            return Application::FAILURE;
        }

        $file = $project . '/' . self::FILE;

        if (is_link($file)) {
            $output->error(self::FILE . ' est un lien vers un autre fichier. Rien n\'a été modifié : supprimez ce lien, puis relancez la commande.');

            return Application::FAILURE;
        }

        if (!file_exists($file)) {
            if (!self::create($file, $script)) {
                $output->error('Le fichier ' . self::FILE . ' n\'a pas pu être créé. Vérifiez que vous avez le droit d\'écrire dans ce dossier.');

                return Application::FAILURE;
            }

            $output->success('Créé : ' . self::FILE);
            $this->explain($output);

            return Application::SUCCESS;
        }

        $current = is_file($file) ? file_get_contents($file) : false;

        if ($current === $script) {
            $output->success(self::FILE . ' est déjà à jour.');

            return Application::SUCCESS;
        }

        // Un fichier de ce nom qui ne vient pas de Wazi est le vôtre.
        if ($current === false || !str_starts_with($current, self::SIGNATURE)) {
            $output->error(
                'Un fichier ' . self::FILE . ' existe déjà, et ce n\'est pas celui de Wazi. Rien n\'a été modifié :'
                . ' renommez votre fichier, puis relancez la commande.',
            );

            return Application::FAILURE;
        }

        if (!self::replace($file, $script)) {
            $output->error('Le fichier ' . self::FILE . ' n\'a pas pu être mis à jour. Vérifiez que vous avez le droit d\'écrire dans ce dossier.');

            return Application::FAILURE;
        }

        $output->success('Mis à jour : ' . self::FILE);

        return Application::SUCCESS;
    }

    // ------------------------------------------------------------------
    // Outils internes
    // ------------------------------------------------------------------

    private function explain(Output $output): void
    {
        $output->line();
        $output->line('Ajoutez cette ligne à votre mise en page, avant </head> :');
        $output->line();
        $output->line('    ' . self::SCRIPT_TAG);
        $output->line();
        $output->line('Puis, dans un template, marquez une zone et ce qui la met à jour :');
        $output->line();
        $output->line('    <ul k:zone="liste"> ... </ul>');
        $output->line('    <form method="post" action="/notes" k:update="liste"> ... </form>');
    }

    /**
     * Crée un fichier, et seulement s'il n'existe pas.
     */
    private static function create(string $file, string $content): bool
    {
        // Le mode « x » échoue si le fichier existe : même créé entre notre
        // vérification et cette ligne, il ne serait pas remplacé.
        $handle = @fopen($file, 'x');

        if ($handle === false) {
            return false;
        }

        $written = fwrite($handle, $content);
        fclose($handle);

        return $written !== false;
    }

    /**
     * Remplace le fichier d'un seul geste : la nouvelle version est écrite à
     * côté, puis prend sa place. Le site ne sert jamais un fichier à moitié écrit.
     */
    private static function replace(string $file, string $content): bool
    {
        $temporary = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (!self::create($temporary, $content)) {
            return false;
        }

        if (@rename($temporary, $file)) {
            return true;
        }

        @unlink($temporary);

        return false;
    }
}
