<?php

declare(strict_types=1);

namespace Wazi\View;

use Wazi\View\Expression\Node\Binary;
use Wazi\View\Expression\Node\Filter;
use Wazi\View\Expression\Node\Index;
use Wazi\View\Expression\Node\Literal;
use Wazi\View\Expression\Node\MethodCall;
use Wazi\View\Expression\Node\Property;
use Wazi\View\Expression\Node\Ternary;
use Wazi\View\Expression\Node\Unary;
use Wazi\View\Expression\Node\Variable;
use Wazi\View\Template\Attribute;
use Wazi\View\Template\Element;
use Wazi\View\Template\Interpolation;
use Wazi\View\Template\Loop;
use Wazi\View\Template\Raw;
use Wazi\View\Template\StaticElement;
use Wazi\View\Template\TemplateNode;
use Wazi\View\Template\Text;

/**
 * Les templates préparés à l'avance, pour ne plus les analyser à chaque requête.
 *
 * Analyser un template (reconnaître ses balises, ses attributs, ses
 * expressions) prend bien plus de temps que de l'afficher. Au déploiement, la
 * commande « wazi views:compile » fait ce travail une fois pour toutes : pour
 * chaque template, elle écrit un fichier PHP qui reconstruit directement son
 * arbre. PHP garde ce fichier en mémoire (OPcache) : à chaque requête, l'arbre
 * est prêt.
 *
 *     notes/liste.kioo  ──wazi views:compile──►  var/views/3f9a….php
 *                                                      │
 *                          chaque requête  ◄───────────┘  (sans analyse)
 *
 * Le template reste affiché par le même code (Renderer) : préparé ou non, il
 * donne exactement la même page.
 *
 * Un fichier préparé note la date et la taille de son template. Si le
 * template a changé depuis, il est ignoré, et le template est analysé comme
 * d'habitude : une page n'est jamais périmée.
 *
 * Sécurité (ADR-030) — un fichier préparé est du PHP, qui s'exécute :
 *   - il n'est JAMAIS écrit pendant une requête. Seule la commande de la
 *     console en écrit, lancée par le développeur ;
 *   - à la lecture, le dossier est refusé si PHP a le droit d'y écrire. Une
 *     faille qui permettrait à un visiteur d'écrire un fichier sur le serveur
 *     ne peut donc pas y déposer du code. Dans ce cas, les templates sont
 *     analysés à chaque requête : plus lent, mais sûr ;
 *   - le code écrit ne contient que des « new » de classes de la liste
 *     ci-dessous et des valeurs écrites par var_export(). Rien du texte d'un
 *     template n'y devient du code.
 */
final class CompiledTemplates
{
    /** À changer dès que la forme de l'arbre change : les anciens fichiers sont alors ignorés. */
    private const int FORMAT = 1;

    /** Le nom d'un fichier préparé : une empreinte, rien qui vienne d'un chemin. */
    private const string FILE_NAME = '/^[a-f0-9]{32}\.php$/D';

    /**
     * Les seules classes qu'un fichier préparé construit.
     *
     * @var list<class-string>
     */
    private const array CLASSES = [
        Element::class,
        StaticElement::class,
        Attribute::class,
        Text::class,
        Raw::class,
        Interpolation::class,
        Loop::class,
        Literal::class,
        Variable::class,
        Property::class,
        Index::class,
        MethodCall::class,
        Unary::class,
        Binary::class,
        Ternary::class,
        Filter::class,
    ];

    /**
     * Le nom des arguments du constructeur de chaque classe, lu une fois.
     *
     * @var array<class-string, list<string>>
     */
    private array $parameters = [];

    /** Vrai si le dossier peut servir à la lecture ; décidé une fois par requête. */
    private ?bool $usable = null;

    /**
     * @param string $directory     le dossier des templates préparés, hors du dossier public
     * @param bool   $allowWritable true pour lire le dossier même si PHP peut y écrire (dangereux, voir la classe)
     */
    public function __construct(private readonly string $directory, private readonly bool $allowWritable = false) {}

    // ------------------------------------------------------------------
    // Lire, à chaque requête
    // ------------------------------------------------------------------

    /**
     * L'arbre préparé d'un template, ou null s'il n'y en a pas, s'il est
     * périmé, ou si le dossier n'est pas sûr.
     *
     * @param string $file le chemin réel du template
     *
     * @return list<TemplateNode>|null
     */
    public function read(string $file): ?array
    {
        if (!$this->isUsable()) {
            return null;
        }

        $compiled = $this->compiledFile($file);

        if (!is_file($compiled)) {
            return null;
        }

        $prepared = require $compiled;

        // Le template a changé depuis qu'il a été préparé : on ne s'en sert pas.
        if (!is_array($prepared) || ($prepared['stamp'] ?? null) !== self::stamp($file) || !is_array($prepared['nodes'] ?? null)) {
            return null;
        }

        $nodes = [];

        foreach ($prepared['nodes'] as $node) {
            if (!$node instanceof TemplateNode) {
                return null;
            }

            $nodes[] = $node;
        }

        return $nodes;
    }

    /**
     * Vrai si ce dossier peut être lu sans danger.
     */
    public function isUsable(): bool
    {
        // Sécurité : un dossier où PHP peut écrire est un dossier où une
        // faille pourrait déposer du code. On ne lit pas de PHP là-dedans.
        // Un dossier visible depuis un navigateur est refusé lui aussi.
        return $this->usable ??= is_dir($this->directory)
            && !$this->isInsideDocumentRoot()
            && ($this->allowWritable || !is_writable($this->directory));
    }

    /**
     * Vrai si le dossier se trouve dans celui que le serveur web distribue.
     */
    private function isInsideDocumentRoot(): bool
    {
        $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';

        if (!is_string($documentRoot) || $documentRoot === '') {
            return false;
        }

        // realpath() donne le chemin réel, sans « .. » ni lien symbolique.
        $realDirectory = realpath($this->directory);
        $realRoot = realpath($documentRoot);

        if ($realDirectory === false || $realRoot === false) {
            return false;
        }

        $realRoot = rtrim($realRoot, '/\\');

        return $realDirectory === $realRoot || str_starts_with($realDirectory, $realRoot . DIRECTORY_SEPARATOR);
    }

    // ------------------------------------------------------------------
    // Écrire, depuis la console
    // ------------------------------------------------------------------

    /**
     * Écrit le fichier préparé d'un template.
     *
     * @param string             $file  le chemin réel du template
     * @param string             $name  son nom, noté en commentaire dans le fichier écrit
     * @param list<TemplateNode> $nodes son arbre
     *
     * @return bool faux si le fichier n'a pas pu être écrit
     */
    public function write(string $file, string $name, array $nodes): bool
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0o755, true) && !is_dir($this->directory)) {
            return false;
        }

        $code = "<?php\n\n"
            . '// Préparé par « wazi views:compile » à partir du template ' . preg_replace('/[^a-zA-Z0-9_\/-]/', '?', $name) . ".\n"
            . "// Ne modifiez pas ce fichier : relancez la commande.\n\n"
            . 'return [' . var_export('stamp', true) . ' => ' . var_export(self::stamp($file), true) . ', '
            . var_export('nodes', true) . ' => ' . $this->export($nodes) . "];\n";

        // On écrit d'abord un fichier provisoire, puis on le renomme : une
        // requête ne peut jamais lire un fichier à moitié écrit.
        $target = $this->compiledFile($file);
        $temporary = $target . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (file_put_contents($temporary, $code, LOCK_EX) === false) {
            return false;
        }

        if (!rename($temporary, $target)) {
            unlink($temporary);

            return false;
        }

        return true;
    }

    /**
     * Supprime les fichiers préparés qui ne correspondent à aucun de ces templates.
     *
     * @param list<string> $files les chemins réels des templates qui existent
     *
     * @return int le nombre de fichiers supprimés
     */
    public function removeAllExcept(array $files): int
    {
        $kept = array_map($this->compiledFile(...), $files);
        $removed = 0;

        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*.php') ?: [] as $compiled) {
            // On ne supprime que ce que cette classe a pu écrire.
            if (preg_match(self::FILE_NAME, basename($compiled)) === 1 && !in_array($compiled, $kept, true) && unlink($compiled)) {
                $removed++;
            }
        }

        return $removed;
    }

    // ------------------------------------------------------------------
    // Outils internes
    // ------------------------------------------------------------------

    private function compiledFile(string $file): string
    {
        // Le nom du fichier préparé est une empreinte du chemin du template :
        // rien de ce chemin ne sert à construire un nom de fichier.
        return $this->directory . DIRECTORY_SEPARATOR . hash('xxh128', $file) . '.php';
    }

    /**
     * Ce qui identifie une version d'un template : sa date, sa taille, et le format des fichiers préparés.
     */
    private static function stamp(string $file): string
    {
        return self::FORMAT . ':' . (int) filemtime($file) . ':' . (int) filesize($file);
    }

    /**
     * Écrit une valeur de l'arbre sous forme de code PHP.
     *
     *     new Variable('note', 0)   devient   new \Wazi\View\Expression\Node\Variable('note', 0)
     *
     * Sécurité : un texte est toujours écrit par var_export(), qui en fait un
     * texte PHP entre apostrophes, quoi qu'il contienne. Un objet n'est écrit
     * que si sa classe est dans la liste.
     */
    private function export(mixed $value): string
    {
        if (is_array($value)) {
            return '[' . implode(', ', array_map($this->export(...), $value)) . ']';
        }

        if (!is_object($value)) {
            return var_export($value, true);
        }

        if (!in_array($value::class, self::CLASSES, true)) {
            throw new \LogicException('Classe inattendue dans l\'arbre d\'un template : ' . $value::class);
        }

        $arguments = [];

        foreach ($this->parametersOf($value::class) as $name) {
            $arguments[] = $this->export(new \ReflectionProperty($value, $name)->getValue($value));
        }

        return 'new \\' . $value::class . '(' . implode(', ', $arguments) . ')';
    }

    /**
     * @param class-string $class
     *
     * @return list<string>
     */
    private function parametersOf(string $class): array
    {
        return $this->parameters[$class] ??= array_map(
            static fn(\ReflectionParameter $parameter): string => $parameter->getName(),
            new \ReflectionClass($class)->getConstructor()?->getParameters() ?? [],
        );
    }
}
