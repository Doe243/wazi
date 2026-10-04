<?php

declare(strict_types=1);

namespace Wazi\View;

use Wazi\View\Exception\KiooException;
use Wazi\View\Template\TemplateNode;
use Wazi\View\Template\TemplateParser;

/**
 * Trouve un template par son nom, lit son fichier et en construit l'arbre.
 *
 *     « notes/liste »  ──►  <dossier des vues>/notes/liste.kioo
 *
 * Un template déjà lu est gardé en mémoire le temps de la requête : un
 * morceau inclus dans une boucle n'est lu et analysé qu'une fois. Si les
 * templates ont été préparés à l'avance (CompiledTemplates), l'arbre est
 * repris tel quel, sans analyse.
 *
 * Sécurité (ADR-006 et ADR-019) : un nom de template ne peut désigner qu'un
 * fichier .kioo situé DANS le dossier des vues.
 *   - le nom n'accepte que des lettres, des chiffres, « - », « _ » et « / » :
 *     ni « .. », ni chemin absolu, ni extension, ni protocole ;
 *   - le chemin réel du fichier trouvé est comparé à celui du dossier : un lien
 *     symbolique qui mènerait ailleurs est refusé.
 */
final class TemplateLoader
{
    private const string NAME = '#^[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)*$#D';

    private const string EXTENSION = '.kioo';

    /** @var array<string, list<TemplateNode>> */
    private array $loaded = [];

    /** Le chemin réel du dossier des vues, cherché une seule fois. */
    private ?string $realDirectory = null;

    /**
     * @param string|null            $directory le dossier des vues ; null si le moteur ne lit pas de fichier
     * @param CompiledTemplates|null $compiled  les templates préparés à l'avance, ou null pour les analyser à chaque requête
     */
    public function __construct(
        private readonly ?string $directory,
        private readonly TemplateParser $parser = new TemplateParser(),
        private readonly ?CompiledTemplates $compiled = null,
    ) {}

    /**
     * @return list<TemplateNode>
     *
     * @throws KiooException si le nom est invalide, le fichier introuvable, ou le template mal écrit
     */
    public function load(string $name): array
    {
        return $this->loaded[$name] ??= $this->loadFile($name);
    }

    /**
     * Analyse un template donné directement en texte, sans passer par un fichier.
     *
     * @return list<TemplateNode>
     */
    public function parse(string $source, string $name): array
    {
        return $this->parser->parse($source, $name);
    }

    /**
     * Prépare à l'avance tous les templates du dossier des vues (voir CompiledTemplates).
     * Appelée par la commande « wazi views:compile », jamais pendant une requête.
     *
     * @return array{compiled: list<string>, removed: int} les noms des templates préparés, et le nombre de fichiers périmés supprimés
     *
     * @throws KiooException si un template est mal écrit, ou si rien n'a été réglé pour les préparer
     */
    public function compileAll(): array
    {
        if ($this->compiled === null) {
            throw KiooException::noCompiledDirectory();
        }

        $directory = $this->realDirectory();
        $names = [];
        $files = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo || !$entry->isFile() || !str_ends_with($entry->getFilename(), self::EXTENSION)) {
                continue;
            }

            // « C:\…\views\notes\liste.kioo » → « notes/liste »
            $relative = substr($entry->getPathname(), strlen($directory) + 1, -strlen(self::EXTENSION));
            $names[] = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
        }

        sort($names);

        foreach ($names as $name) {
            $file = $this->find($name);
            $source = file_get_contents($file);

            if ($source === false || !$this->compiled->write($file, $name, $this->parser->parse($source, $name))) {
                throw KiooException::compiledNotWritable($name);
            }

            $files[] = $file;
        }

        return ['compiled' => $names, 'removed' => $this->compiled->removeAllExcept($files)];
    }

    /**
     * @return list<TemplateNode>
     */
    private function loadFile(string $name): array
    {
        $file = $this->find($name);

        // Préparé à l'avance, et pas modifié depuis ? L'arbre est prêt.
        $nodes = $this->compiled?->read($file);

        if ($nodes !== null) {
            return $nodes;
        }

        $source = file_get_contents($file);

        return $source !== false ? $this->parser->parse($source, $name) : throw KiooException::templateNotFound($name);
    }

    /**
     * Le chemin réel du fichier d'un template.
     */
    private function find(string $name): string
    {
        if (preg_match(self::NAME, $name) !== 1) {
            throw KiooException::invalidTemplateName();
        }

        $directory = $this->realDirectory();

        // realpath() donne le chemin réel, liens symboliques résolus.
        $file = realpath($directory . DIRECTORY_SEPARATOR . $name . self::EXTENSION);

        if ($file === false
            || !is_file($file)
            || !str_starts_with($file, $directory . DIRECTORY_SEPARATOR)
        ) {
            throw KiooException::templateNotFound($name);
        }

        return $file;
    }

    private function realDirectory(): string
    {
        if ($this->directory === null) {
            throw KiooException::noViewsDirectory();
        }

        if ($this->realDirectory === null) {
            $directory = realpath($this->directory);

            if ($directory === false || !is_dir($directory)) {
                throw KiooException::viewsDirectoryNotFound();
            }

            $this->realDirectory = $directory;
        }

        return $this->realDirectory;
    }
}
