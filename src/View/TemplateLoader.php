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
 * morceau inclus dans une boucle n'est lu et analysé qu'une fois.
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
     * @param string|null $directory le dossier des vues ; null si le moteur ne lit pas de fichier
     */
    public function __construct(
        private readonly ?string $directory,
        private readonly TemplateParser $parser = new TemplateParser(),
    ) {}

    /**
     * @return list<TemplateNode>
     *
     * @throws KiooException si le nom est invalide, le fichier introuvable, ou le template mal écrit
     */
    public function load(string $name): array
    {
        return $this->loaded[$name] ??= $this->parser->parse($this->read($name), $name);
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

    private function read(string $name): string
    {
        if (preg_match(self::NAME, $name) !== 1) {
            throw KiooException::invalidTemplateName();
        }

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

        $directory = $this->realDirectory;

        // realpath() donne le chemin réel, liens symboliques résolus.
        $file = realpath($directory . DIRECTORY_SEPARATOR . $name . self::EXTENSION);

        if ($file === false
            || !is_file($file)
            || !str_starts_with($file, $directory . DIRECTORY_SEPARATOR)
        ) {
            throw KiooException::templateNotFound($name);
        }

        $source = file_get_contents($file);

        return $source !== false ? $source : throw KiooException::templateNotFound($name);
    }
}
