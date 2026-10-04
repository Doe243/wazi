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
 * « wazi make:controller Article » : crée un contrôleur et sa page, prêts à modifier.
 *
 *     src/ArticleController.php     le contrôleur, avec une route : GET /article
 *     views/article.kioo            le template de la page
 *
 * Le code créé est commenté : chaque ligne dit ce qu'elle fait. Une fois que
 * vous connaissez ces lignes, --no-comments les écrit sans les explications.
 *
 * La commande ne touche à aucun fichier existant. Elle ne modifie pas non
 * plus app.php : elle vous donne la ligne à y ajouter, pour que vous sachiez
 * toujours d'où viennent vos routes.
 *
 * Sécurité (ADR-028) :
 *   - le nom tapé sert à nommer un fichier et une classe. Il n'est accepté
 *     que s'il a la forme d'un nom de classe : des lettres et des chiffres,
 *     une majuscule en tête. Ni « / », ni « .. », ni espace ;
 *   - un fichier qui existe déjà n'est jamais remplacé.
 */
final readonly class MakeControllerCommand implements Command
{
    /** Un nom de classe écrit en PascalCase : Article, BlogPost, Page2. */
    private const string NAME = '/^[A-Z][A-Za-z0-9]{0,60}$/D';

    private const string NAMESPACE_NAME = '/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D';

    private const string SUFFIX = 'Controller';

    /**
     * @param string|null $directory le dossier du projet ; celui où la commande est tapée, par défaut
     */
    public function __construct(private ?string $directory = null) {}

    public function name(): string
    {
        return 'make:controller';
    }

    public function description(): string
    {
        return 'Crée un contrôleur et sa page, commentés et prêts à modifier.';
    }

    public function arguments(): array
    {
        return [new Argument('nom', 'Le nom du contrôleur, avec une majuscule : Article, BlogPost')];
    }

    public function options(): array
    {
        return [new Option('no-comments', 'Écrire le code sans les commentaires d\'explication')];
    }

    public function run(Input $input, Output $output): int
    {
        $name = $input->argument('nom');

        // « ArticleController » et « Article » désignent le même contrôleur.
        if (strlen($name) > strlen(self::SUFFIX) && str_ends_with($name, self::SUFFIX)) {
            $name = substr($name, 0, -strlen(self::SUFFIX));
        }

        // « Controller » tout seul ne nomme rien.
        if (preg_match(self::NAME, $name) !== 1 || $name === self::SUFFIX) {
            $output->error(
                'Ce nom ne convient pas pour un contrôleur. Écrivez un mot qui commence par une majuscule, fait'
                . ' de lettres sans accent et de chiffres, sans espace : Article, BlogPost.',
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

        [$namespace, $source] = self::sourceOf($project);
        $class = $name . self::SUFFIX;
        // BlogPost → blog-post : c'est l'adresse de la page, et le nom de son template.
        $slug = strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '-', $name));
        $withComments = !$input->flag('no-comments');
        $hasLayout = is_file($project . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'base.kioo');

        $files = [
            $source . '/' . $class . '.php' => self::controller($namespace, $class, $name, $slug, $withComments),
            'views/' . $slug . '.kioo' => self::view($name, $class, $source, $hasLayout, $withComments),
        ];

        // On vérifie tout avant d'écrire quoi que ce soit : ou les deux
        // fichiers sont créés, ou aucun.
        foreach (array_keys($files) as $file) {
            if (file_exists($project . '/' . $file)) {
                $output->error('Le fichier ' . $file . ' existe déjà. Rien n\'a été modifié : choisissez un autre nom, ou supprimez ce fichier vous-même.');

                return Application::FAILURE;
            }
        }

        foreach ($files as $file => $content) {
            if (!self::create($project . '/' . $file, $content)) {
                $output->error('Le fichier ' . $file . ' n\'a pas pu être créé. Vérifiez que vous avez le droit d\'écrire dans ce dossier.');

                return Application::FAILURE;
            }

            $output->success('Créé : ' . $file);
        }

        $output->line();
        $output->line('Il reste une ligne à ajouter dans app.php, avec les autres contrôleurs :');
        $output->line();
        $output->line('    $app->router->addController(\\' . $namespace . '\\' . $class . '::class);');
        $output->line();
        $output->line('Puis ouvrez /' . $slug . ' dans votre navigateur.');

        return Application::SUCCESS;
    }

    // ------------------------------------------------------------------
    // Outils internes
    // ------------------------------------------------------------------

    /**
     * L'espace de noms de vos classes et le dossier où elles sont rangées,
     * lus dans composer.json (« autoload », « psr-4 »). Par défaut : App et src.
     *
     * @return array{string, string}
     */
    private static function sourceOf(string $project): array
    {
        $content = file_get_contents($project . DIRECTORY_SEPARATOR . 'composer.json');
        $composer = $content === false ? null : json_decode($content, true);
        $psr4 = is_array($composer) && is_array($composer['autoload'] ?? null) ? $composer['autoload']['psr-4'] ?? null : null;

        foreach (is_array($psr4) ? $psr4 : [] as $namespace => $directory) {
            $namespace = rtrim((string) $namespace, '\\');
            // Seule la barre finale est retirée : un chemin qui commence par
            // « / » désigne un dossier hors du projet, et sera refusé.
            $directory = is_string($directory) ? rtrim($directory, '/') : '';

            // Ce qui est lu dans un fichier se vérifie : un espace de noms et
            // un dossier simples, situés dans le projet.
            if (preg_match(self::NAMESPACE_NAME, $namespace) === 1 && preg_match('#^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$#D', $directory) === 1) {
                return [$namespace, $directory];
            }
        }

        return ['App', 'src'];
    }

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

    private static function controller(string $namespace, string $class, string $name, string $slug, bool $withComments): string
    {
        $lines = [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            'namespace ' . $namespace . ';',
            '',
            'use Psr\Http\Message\ResponseInterface;',
            'use Wazi\Routing\Attribute\Get;',
            'use Wazi\View\Kioo;',
            '',
            '# /**',
            '#  * Un contrôleur traduit une requête en réponse : il prépare ce que la page',
            '#  * affiche, et la confie à un template.',
            '#  *',
            '#  * Pour que ses routes existent, il est déclaré dans app.php :',
            '#  *',
            '#  *     $app->router->addController(' . $class . '::class);',
            '#  */',
            'final readonly class ' . $class,
            '{',
            '#     // Ce dont le contrôleur a besoin se demande ici. Le conteneur lit ce',
            '#     // constructeur et fournit Kioo, le moteur de templates.',
            '    public function __construct(private Kioo $kioo) {}',
            '',
            '#     // L\'attribut dit pour quelle adresse cette méthode s\'exécute.',
            '#     // « wazi routes » liste toutes les routes de l\'application.',
            '    #[Get(\'/' . $slug . '\')]',
            '    public function index(): ResponseInterface',
            '    {',
            '#         // Le premier argument : le template views/' . $slug . '.kioo.',
            '#         // Le second : ce que ce template peut afficher.',
            '        return $this->kioo->page(\'' . $slug . '\', [\'titre\' => \'' . $name . '\']);',
            '    }',
            '}',
            '',
        ];

        return self::assemble($lines, $withComments);
    }

    private static function view(string $name, string $class, string $source, bool $hasLayout, bool $withComments): string
    {
        $comment = [
            '# <!-- La page de ' . $class . ' (' . $source . '/' . $class . '.php).',
            '#      {titre} affiche la valeur donnée par le contrôleur. Elle est échappée :',
            '#      une balise qui s\'y trouverait serait affichée, pas exécutée.',
            '#      Ce commentaire est pour vous : Kioo ne l\'envoie jamais au visiteur. -->',
        ];

        $lines = $hasLayout
            ? [
                '<k:layout name="base">',
                '<k:block name="titre">{titre}</k:block>',
                '',
                ...$comment,
                '<h1>{titre}</h1>',
                '<p>Cette page vient d\'être créée. Modifiez-la dans son template.</p>',
                '',
            ]
            : [
                ...$comment,
                '<!DOCTYPE html>',
                '<html lang="fr">',
                '<head>',
                '    <meta charset="utf-8">',
                '    <meta name="viewport" content="width=device-width, initial-scale=1">',
                '    <title>{titre}</title>',
                '</head>',
                '<body>',
                '    <h1>{titre}</h1>',
                '    <p>Cette page vient d\'être créée. Modifiez-la dans son template.</p>',
                '</body>',
                '</html>',
                '',
            ];

        return self::assemble($lines, $withComments);
    }

    /**
     * Assemble les lignes d'un fichier. Celles qui commencent par « # » sont
     * les commentaires d'explication : gardés, ou retirés avec --no-comments.
     *
     * @param list<string> $lines
     */
    private static function assemble(array $lines, bool $withComments): string
    {
        $kept = [];

        foreach ($lines as $line) {
            if (!str_starts_with($line, '# ')) {
                $kept[] = $line;
            } elseif ($withComments) {
                $kept[] = substr($line, 2);
            }
        }

        return implode("\n", $kept);
    }
}
