<?php

declare(strict_types=1);

namespace Wazi\Console\Command;

use Wazi\Console\Application;
use Wazi\Console\Argument;
use Wazi\Console\Command;
use Wazi\Console\Input;
use Wazi\Console\Option;
use Wazi\Console\Output;

/**
 * « wazi make:migration creer_notes » : crée un fichier de migration, prêt à remplir.
 *
 *     migrations/20261004_153000_creer_notes.sql
 *
 * Une migration est un fichier SQL qui change la structure de la base : créer
 * une table, ajouter une colonne. Le nom du fichier commence par la date et
 * l'heure : c'est ce qui fixe l'ordre dans lequel les migrations s'appliquent.
 *
 * Le fichier créé contient un exemple en commentaire. Écrivez-y votre SQL,
 * puis lancez « wazi db:migrate ».
 *
 * Sécurité (ADR-028) :
 *   - le nom tapé sert à nommer un fichier. Il n'est accepté que s'il est
 *     fait de minuscules, de chiffres et de « _ ». Ni « / », ni « .. », ni espace ;
 *   - un fichier qui existe déjà n'est jamais remplacé.
 */
final readonly class MakeMigrationCommand implements Command
{
    /** creer_notes, ajouter_couleur_aux_notes, notes2. */
    private const string NAME = '/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/D';

    private const int NAME_MAX = 60;

    /**
     * @param string|null             $directory le dossier du projet ; celui où la commande est tapée, par défaut
     * @param \DateTimeImmutable|null $now       la date à écrire dans le nom ; maintenant, par défaut
     */
    public function __construct(private ?string $directory = null, private ?\DateTimeImmutable $now = null) {}

    public function name(): string
    {
        return 'make:migration';
    }

    public function description(): string
    {
        return 'Crée un fichier de migration : un changement de la structure de la base, en SQL.';
    }

    public function arguments(): array
    {
        return [new Argument('nom', 'Ce que fait la migration, en minuscules : creer_notes, ajouter_couleur_aux_notes')];
    }

    public function options(): array
    {
        return [new Option('no-comments', 'Créer le fichier sans les commentaires d\'explication')];
    }

    public function run(Input $input, Output $output): int
    {
        $name = $input->argument('nom');

        if (preg_match(self::NAME, $name) !== 1 || strlen($name) > self::NAME_MAX) {
            $output->error(
                'Ce nom ne convient pas pour une migration. Décrivez le changement en minuscules, sans accent,'
                . ' avec des « _ » entre les mots : creer_notes, ajouter_couleur_aux_notes.',
            );

            return Application::USAGE_ERROR;
        }

        $project = $this->directory ?? (string) getcwd();

        if (!is_file($project . DIRECTORY_SEPARATOR . 'composer.json')) {
            $output->error(
                'Le fichier composer.json est introuvable ici. Lancez cette commande depuis le dossier de votre'
                . ' projet, celui qui contient composer.json et app.php.',
            );

            return Application::FAILURE;
        }

        // La date en tête du nom fixe l'ordre : les fichiers se trient par nom.
        $file = 'migrations/' . ($this->now ?? new \DateTimeImmutable())->format('Ymd_His') . '_' . $name . '.sql';

        if (file_exists($project . '/' . $file)) {
            $output->error('Le fichier ' . $file . ' existe déjà. Rien n\'a été modifié : attendez une seconde, ou choisissez un autre nom.');

            return Application::FAILURE;
        }

        if (!self::create($project . '/' . $file, self::content($name, !$input->flag('no-comments')))) {
            $output->error('Le fichier ' . $file . ' n\'a pas pu être créé. Vérifiez que vous avez le droit d\'écrire dans ce dossier.');

            return Application::FAILURE;
        }

        $output->success('Créé : ' . $file);
        $output->line();
        $output->line('Écrivez-y le SQL du changement, puis appliquez-le :');
        $output->line();
        $output->line('    wazi db:migrate');

        return Application::SUCCESS;
    }

    // ------------------------------------------------------------------
    // Outils internes
    // ------------------------------------------------------------------

    /**
     * Crée un fichier, et seulement s'il n'existe pas.
     */
    private static function create(string $file, string $content): bool
    {
        if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0o755, true) && !is_dir(dirname($file))) {
            return false;
        }

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

    private static function content(string $name, bool $withComments): string
    {
        if (!$withComments) {
            return '';
        }

        return implode("\n", [
            '-- Migration : ' . $name,
            '--',
            '-- Écrivez ici le SQL qui change la structure de la base, puis lancez :',
            '--',
            '--     wazi db:migrate',
            '--',
            '-- Plusieurs requêtes se séparent par un « ; ». Chaque fichier est appliqué',
            '-- une seule fois : pour corriger une migration déjà appliquée, créez-en une',
            '-- nouvelle (wazi make:migration ...) au lieu de modifier celle-ci.',
            '--',
            '-- Un exemple, à adapter. La colonne « id » est un numéro que la base donne',
            '-- elle-même à chaque ligne ; sa définition change d\'une base à l\'autre :',
            '--',
            '--     SQLite       id INTEGER PRIMARY KEY AUTOINCREMENT',
            '--     MySQL        id INT AUTO_INCREMENT PRIMARY KEY',
            '--     PostgreSQL   id SERIAL PRIMARY KEY',
            '--',
            '-- CREATE TABLE notes (',
            '--     id INTEGER PRIMARY KEY AUTOINCREMENT,',
            '--     auteur VARCHAR(80) NOT NULL,',
            '--     texte TEXT NOT NULL,',
            '--     creee_le VARCHAR(19) NOT NULL',
            '-- );',
            '--',
            '-- CREATE INDEX notes_auteur ON notes (auteur);',
            '',
        ]);
    }
}
