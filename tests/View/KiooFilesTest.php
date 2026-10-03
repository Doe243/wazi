<?php

declare(strict_types=1);

namespace Wazi\Tests\View;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wazi\View\Exception\KiooException;
use Wazi\View\Kioo;

/**
 * Les templates lus dans des fichiers : dossier des vues, mise en page, inclusions.
 */
final class KiooFilesTest extends TestCase
{
    /** Un dossier temporaire propre à chaque test, supprimé ensuite. */
    private string $directory;

    private string $views;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wazi-' . bin2hex(random_bytes(8));
        $this->views = $this->directory . DIRECTORY_SEPARATOR . 'views';
        mkdir($this->views . DIRECTORY_SEPARATOR . 'partiels', 0o777, true);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
        }

        rmdir($this->directory);
    }

    // --- Dossier des vues --------------------------------------------------

    public function testItRendersATemplateFile(): void
    {
        $this->view('accueil', '<h1>{titre}</h1>');

        self::assertSame('<h1>Bonjour</h1>', $this->kioo()->render('accueil', ['titre' => 'Bonjour']));
    }

    public function testATemplateCanLiveInASubfolder(): void
    {
        $this->view('partiels/pied', '<footer>{annee}</footer>');

        self::assertSame('<footer>2026</footer>', $this->kioo()->render('partiels/pied', ['annee' => 2026]));
    }

    public function testAnErrorInAFileNamesTheFileAndTheLine(): void
    {
        $this->view('accueil', "<h1>Titre</h1>\n<p>{tittre}</p>");

        $this->expectException(KiooException::class);
        $this->expectExceptionMessage('Dans le template « accueil », ligne 2 :');

        $this->kioo()->render('accueil', ['titre' => 'Bonjour']);
    }

    public function testAMissingTemplateIsExplained(): void
    {
        try {
            $this->kioo()->render('notes/liste');
            self::fail('Une exception était attendue.');
        } catch (KiooException $exception) {
            self::assertStringContainsString('« notes/liste » est introuvable', $exception->getMessage());
            self::assertStringContainsString('notes/liste.kioo', $exception->getMessage());
            self::assertStringNotContainsString($this->directory, $exception->getMessage(), 'Le chemin du serveur ne fuit pas.');
        }
    }

    public function testAnEngineWithoutViewsDirectoryCannotReadFiles(): void
    {
        $this->expectException(KiooException::class);
        $this->expectExceptionMessage('n\'a pas de dossier de vues');

        new Kioo()->render('accueil');
    }

    public function testAMissingViewsDirectoryIsExplained(): void
    {
        $this->expectException(KiooException::class);
        $this->expectExceptionMessage('n\'existe pas');

        new Kioo($this->directory . '/absent')->render('accueil');
    }

    // --- Sécurité : rester dans le dossier des vues -------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function namesThatLeaveTheViewsDirectory(): iterable
    {
        yield 'dossier parent' => ['../secret'];
        yield 'dossier parent au milieu' => ['partiels/../../secret'];
        yield 'barre oblique inversée' => ['..\\secret'];
        yield 'chemin absolu' => ['/etc/passwd'];
        yield 'lecteur Windows' => ['C:/Windows/win'];
        yield 'avec extension' => ['accueil.kioo'];
        yield 'fichier PHP' => ['../public/index.php'];
        yield 'adresse à protocole' => ['php://filter/resource=accueil'];
        yield 'archive phar' => ['phar://piege.phar/accueil'];
        yield 'octet nul' => ["accueil\0"];
        yield 'retour à la ligne' => ["accueil\nFAUSSE LIGNE"];
        yield 'barre finale' => ['partiels/'];
        yield 'barre au début' => ['/accueil'];
        yield 'deux barres' => ['partiels//pied'];
        yield 'point seul' => ['.'];
        yield 'vide' => [''];
    }

    #[DataProvider('namesThatLeaveTheViewsDirectory')]
    public function testATemplateNameCannotLeaveTheViewsDirectory(string $name): void
    {
        file_put_contents($this->directory . '/secret.kioo', 'mot-de-passe-tres-secret');
        $this->view('accueil', 'accueil');

        try {
            $html = $this->kioo()->render($name);
            self::fail('Une exception était attendue, page obtenue : ' . $html);
        } catch (KiooException $exception) {
            self::assertStringContainsString('Ce nom de template est invalide', $exception->getMessage());
            self::assertStringNotContainsString("\n", $exception->getMessage());
            self::assertStringNotContainsString('secret', $exception->getMessage());
        }
    }

    public function testASymbolicLinkLeadingOutsideIsRefused(): void
    {
        file_put_contents($this->directory . '/secret.kioo', 'mot-de-passe-tres-secret');

        // Créer un lien symbolique demande des droits particuliers sous Windows.
        if (!@symlink($this->directory . '/secret.kioo', $this->views . '/lien.kioo')) {
            self::markTestSkipped('Ce système ne permet pas de créer un lien symbolique.');
        }

        $this->expectException(KiooException::class);
        $this->expectExceptionMessage('introuvable');

        $this->kioo()->render('lien');
    }

    // --- Inclusions --------------------------------------------------------

    public function testAnIncludeWritesAnotherTemplateInPlace(): void
    {
        $this->view('page', '<main>contenu</main><k:include file="partiels/pied" annee="{annee}">');
        $this->view('partiels/pied', '<footer>© {annee}</footer>');

        self::assertSame('<main>contenu</main><footer>© 2026</footer>', $this->kioo()->render('page', ['annee' => 2026]));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function includeAttributes(): iterable
    {
        yield 'valeur passée telle quelle (un nombre reste un nombre)' => ['n="{nombre}"', '[int:42]'];
        yield 'une liste reste une liste' => ['n="{liste}"', '[array]'];
        yield 'texte écrit en dur' => ['n="bonjour"', '[string:bonjour]'];
        yield 'texte mêlé à une valeur' => ['n="n° {nombre}"', '[string:n° 42]'];
        yield 'entité dans un texte écrit en dur' => ['n="a &amp; b"', '[string:a &amp; b]'];
        yield 'attribut sans valeur' => ['n', '[bool]'];
        yield 'expression' => ['n="{nombre > 10 ? \'grand\' : \'petit\'}"', '[string:grand]'];
        yield 'self-fermante' => ['n="{nombre}" /', '[int:42]'];
    }

    #[DataProvider('includeAttributes')]
    public function testIncludeAttributesBecomeVariables(string $attributes, string $expected): void
    {
        $this->view('page', '<k:include file="partiels/valeur" ' . $attributes . '>');
        $this->view('partiels/valeur', '[{n | type}]');

        $kioo = new Kioo($this->views, [
            'type' => static fn(mixed $value): string => get_debug_type($value) . (is_scalar($value) && !is_bool($value) ? ':' . $value : ''),
        ]);

        self::assertSame($expected, $kioo->render('page', ['nombre' => 42, 'liste' => [1, 2]]));
    }

    /**
     * Sécurité : un template inclus ne voit que ce qu'on lui passe. Les
     * variables de la page (un utilisateur connecté, un jeton) ne le suivent pas.
     */
    public function testAnIncludedTemplateOnlySeesWhatItIsGiven(): void
    {
        $this->view('page', '<k:include file="partiels/pied" annee="{annee}">');
        $this->view('partiels/pied', '{annee} {secret ?? "invisible"}');

        self::assertSame('2026 invisible', $this->kioo()->render('page', ['annee' => 2026, 'secret' => 'mot-de-passe']));
    }

    public function testAnIncludeCanBeRepeatedAndConditional(): void
    {
        $this->view('page', '<ul><k:include k:for="note in notes" file="partiels/note" note="{note}"></ul><k:include k:if="vide" file="partiels/note" note="jamais">');
        $this->view('partiels/note', '<li>{note}</li>');

        self::assertSame('<ul><li>Pain</li><li>Lait</li></ul>', $this->kioo()->render('page', ['notes' => ['Pain', 'Lait'], 'vide' => false]));
    }

    public function testIncludesCanBeNested(): void
    {
        $this->view('page', '<k:include file="partiels/a" x="1">');
        $this->view('partiels/a', 'a{x}<k:include file="partiels/b" y="{x}2">');
        $this->view('partiels/b', 'b{y}');

        self::assertSame('a1b12', $this->kioo()->render('page'));
    }

    public function testATemplateThatIncludesItselfIsDetected(): void
    {
        $this->view('boucle', 'x<k:include file="boucle">');

        try {
            $this->kioo()->render('boucle');
            self::fail('Une exception était attendue.');
        } catch (KiooException $exception) {
            self::assertStringContainsString('il y a sans doute une boucle', $exception->getMessage());
        }
    }

    public function testAMissingIncludedTemplateTellsWhereItWasAsked(): void
    {
        $this->view('page', "<p>a</p>\n<k:include file=\"partiels/absent\">");

        $this->expectException(KiooException::class);
        $this->expectExceptionMessage('Dans le template « page », ligne 2 : Le template « partiels/absent » est introuvable');

        $this->kioo()->render('page');
    }

    public function testAnErrorInAnIncludedTemplateNamesThatTemplate(): void
    {
        $this->view('page', "<p>a</p>\n<k:include file=\"partiels/pied\">");
        $this->view('partiels/pied', "<footer>\n\n{annee}</footer>");

        $this->expectException(KiooException::class);
        $this->expectExceptionMessage('Dans le template « partiels/pied », ligne 3 :');

        $this->kioo()->render('page', ['annee' => 2026]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidIncludes(): iterable
    {
        yield 'sans attribut file' => ['<k:include annee="2026">', 'a besoin de l\'attribut « file »'];
        yield 'file rempli par une valeur' => ['<k:include file="{page}">', 'doit être écrit en toutes lettres'];
        yield 'file mêlé à une valeur' => ['<k:include file="partiels/{page}">', 'doit être écrit en toutes lettres'];
        yield 'nom de variable invalide' => ['<k:include file="partiels/pied" mon-annee="2026">', 'ne peut pas servir de nom de variable'];
        yield 'nom de fichier qui sort du dossier' => ['<k:include file="../secret">', 'Ce nom de template est invalide'];
    }

    /**
     * Sécurité : le fichier inclus s'écrit en dur. Une valeur, qui peut venir
     * d'un visiteur, ne choisit jamais quel fichier est lu.
     */
    #[DataProvider('invalidIncludes')]
    public function testAnInvalidIncludeIsExplained(string $template, string $expectedHint): void
    {
        file_put_contents($this->directory . '/secret.kioo', 'mot-de-passe-tres-secret');
        $this->view('page', $template);
        $this->view('partiels/pied', 'pied');

        try {
            $html = $this->kioo()->render('page', ['page' => '../secret']);
            self::fail('Une exception était attendue, page obtenue : ' . $html);
        } catch (KiooException $exception) {
            self::assertStringContainsString($expectedHint, $exception->getMessage());
        }
    }

    // --- Mise en page ------------------------------------------------------

    public function testAPageIsPlacedInsideItsLayout(): void
    {
        $this->view('base', "<html><head><title><k:block name=\"titre\">Sans titre</k:block></title></head>\n<body><k:block name=\"content\"></k:block></body></html>");
        $this->view('page', "<k:layout name=\"base\">\n<k:block name=\"titre\">{titre}</k:block>\n\n<h1>{titre}</h1>\n<p>Texte.</p>\n");

        self::assertSame(
            "<html><head><title>Accueil</title></head>\n<body><h1>Accueil</h1>\n<p>Texte.</p></body></html>",
            $this->kioo()->render('page', ['titre' => 'Accueil']),
        );
    }

    public function testABlockThePageDoesNotFillKeepsItsDefaultContent(): void
    {
        $this->view('base', '<title><k:block name="titre">Sans titre</k:block></title><k:block name="content" /><k:block name="pied"><footer>{annee}</footer></k:block>');
        $this->view('page', '<k:layout name="base"><p>contenu</p>');

        self::assertSame('<title>Sans titre</title><p>contenu</p><footer>2026</footer>', $this->kioo()->render('page', ['annee' => 2026]));
    }

    public function testTheLayoutSeesTheVariablesOfThePage(): void
    {
        $this->view('base', '<body class="{theme}"><k:block name="content" /></body>');
        $this->view('page', '<k:layout name="base">x');

        self::assertSame('<body class="sombre">x</body>', $this->kioo()->render('page', ['theme' => 'sombre']));
    }

    public function testBlocksAndContentCanUseStructuresAndIncludes(): void
    {
        $this->view('base', '<nav><k:block name="menu">menu</k:block></nav><main><k:block name="content" /></main>');
        $this->view('page', '<k:layout name="base" /><k:block name="menu"><a k:for="lien in liens" href="/{lien}">{lien}</a></k:block><k:include file="partiels/pied" annee="{annee}">');
        $this->view('partiels/pied', '<footer>{annee}</footer>');

        self::assertSame(
            '<nav><a href="/a">a</a><a href="/b">b</a></nav><main><footer>2026</footer></main>',
            $this->kioo()->render('page', ['liens' => ['a', 'b'], 'annee' => 2026]),
        );
    }

    public function testALayoutFileRenderedAloneShowsItsDefaultContent(): void
    {
        $this->view('base', '<title><k:block name="titre">Sans titre</k:block></title><k:block name="content" />');

        self::assertSame('<title>Sans titre</title>', $this->kioo()->render('base'));
    }

    public function testAnIncludedTemplateCanHaveItsOwnLayout(): void
    {
        $this->view('cadre', '<aside><k:block name="content" /></aside>');
        $this->view('partiels/encart', '<k:layout name="cadre">{texte}');
        $this->view('page', '<p>a</p><k:include file="partiels/encart" texte="b">');

        self::assertSame('<p>a</p><aside>b</aside>', $this->kioo()->render('page'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidLayouts(): iterable
    {
        yield 'k:layout après une balise' => ['<p>a</p><k:layout name="base">', 'doit être la toute première'];
        yield 'k:layout après du texte' => ['Bonjour <k:layout name="base">', 'doit être la toute première'];
        yield 'k:layout dans une balise' => ['<div><k:layout name="base"></div>', 'doit être la toute première'];
        yield 'k:layout sans nom' => ['<k:layout>', 'a besoin de l\'attribut « name »'];
        yield 'k:layout rempli par une valeur' => ['<k:layout name="{mise}">', 'doit être écrit en toutes lettres'];
        yield 'k:block sans nom' => ['<k:block>x</k:block>', 'a besoin de l\'attribut « name »'];
        yield 'k:block jamais fermé' => ['<k:block name="titre">x', 'Ajoutez la balise fermante </k:block>'];
        yield 'k:block dans une balise, avec une mise en page' => ['<k:layout name="base"><div><k:block name="titre">x</k:block></div>', 'se place au premier niveau'];
        yield 'bloc content déclaré dans la page' => ['<k:layout name="base"><k:block name="content">x</k:block>', 'ne se déclare pas dans une page'];
        yield 'mise en page introuvable' => ['<k:layout name="absente">x', '« absente » est introuvable'];
        yield 'mise en page qui a elle-même une mise en page' => ['<k:layout name="double">x', 'qu\'un niveau de mise en page'];
    }

    #[DataProvider('invalidLayouts')]
    public function testAnInvalidLayoutIsExplained(string $template, string $expectedHint): void
    {
        $this->view('base', '<k:block name="content" />');
        $this->view('double', '<k:layout name="base">x');
        $this->view('page', $template);

        try {
            $html = $this->kioo()->render('page', ['mise' => 'base']);
            self::fail('Une exception était attendue, page obtenue : ' . $html);
        } catch (KiooException $exception) {
            self::assertStringContainsString($expectedHint, $exception->getMessage());
        }
    }

    public function testADoctypeOrACommentCanComeBeforeTheLayout(): void
    {
        $this->view('base', '[<k:block name="content" />]');
        $this->view('page', "<!-- la page d'accueil -->\n<k:layout name=\"base\">x");

        self::assertStringContainsString('[', $this->kioo()->render('page'));
    }

    public function testAFileIsOnlyReadOncePerEngine(): void
    {
        $this->view('page', '<k:include k:for="n in liste" file="partiels/n" n="{n}">');
        $this->view('partiels/n', '{n}');
        $kioo = $this->kioo();

        self::assertSame('123', $kioo->render('page', ['liste' => [1, 2, 3]]));

        // Le fichier change sur le disque : le moteur garde ce qu'il a déjà lu.
        $this->view('partiels/n', 'modifié');

        self::assertSame('123', $kioo->render('page', ['liste' => [1, 2, 3]]));
        self::assertSame('modifiémodifié', $this->kioo()->render('page', ['liste' => [1, 2]]), 'Un nouveau moteur relit le fichier.');
    }

    // --- Outils ------------------------------------------------------------

    private function kioo(): Kioo
    {
        return new Kioo($this->views);
    }

    private function view(string $name, string $source): void
    {
        file_put_contents($this->views . DIRECTORY_SEPARATOR . $name . '.kioo', $source);
    }
}
