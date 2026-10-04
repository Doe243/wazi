<?php

declare(strict_types=1);

namespace Wazi\Tests\View;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wazi\Http\CspNonce;
use Wazi\Http\CsrfToken;
use Wazi\View\CompiledTemplates;
use Wazi\View\Exception\KiooException;
use Wazi\View\Kioo;

/**
 * Les templates préparés à l'avance par « wazi views:compile » (ADR-030).
 */
final class CompiledTemplatesTest extends TestCase
{
    /** Un dossier temporaire : views/ pour les templates, compiled/ pour ceux qui sont préparés. */
    private string $directory;

    private string $views;

    private string $compiled;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wazi-' . bin2hex(random_bytes(8));
        $this->views = $this->directory . DIRECTORY_SEPARATOR . 'views';
        $this->compiled = $this->directory . DIRECTORY_SEPARATOR . 'compiled';
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

    // --- Préparé ou non, la même page ----------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function templates(): iterable
    {
        yield 'texte et valeurs' => ['<h1>{titre | upper}</h1><p class="note {type}">{prix * 2 | number(2)}</p>'];
        yield 'structures' => ['<ul><li k:for="i, n in notes" class="{i == 0 ? \'premier\' : \'\'}">{n}</li><li k:else>Rien</li></ul><p k:if="(notes | length) > 1">plusieurs</p><p k:else>peu</p>'];
        yield 'balises fixes et commentaires' => ["<nav><a href=\"/\">Accueil</a></nav>\n<!-- pour moi -->\n<svg><use href=\"#a\"></use></svg>"];
        yield 'attributs' => ['<input disabled="{actif}" value=\'{titre}\' data-x="a &amp; b"><a href="{lien}">x</a><br />'];
        yield 'formulaire et script' => ['<form method="post" action="/notes"><button>Ok</button></form><script>var a = {b: 1};</script>'];
        yield 'texte piégé pour du PHP' => ["<p>'); system('calc'); // \\ \$variable {titre} ?> <?php echo 1; ?></p>"];
        yield 'accolade écrite, entités, accents' => ['<p>\{pas une valeur} &lt;été&gt; « ça » 日本 {objet.methode(3)} {liste[1]} {absent ?? \'défaut\'}</p>'];
        yield 'données pour un script' => ['<k:json id="d" value="{notes}"><div data-n="{notes | json}"></div>'];
        yield 'nombres' => ['{1.0 + 2} {3 / 2} {10 % 3} {not actif ? 1 : 0}'];
    }

    /**
     * La règle d'or : un template préparé à l'avance donne EXACTEMENT la même
     * page que le même template analysé à la demande.
     */
    #[DataProvider('templates')]
    public function testAPreparedTemplateGivesExactlyTheSamePage(string $template): void
    {
        $this->write('page', $template);

        $variables = [
            'titre' => 'Mon <carnet>',
            'type' => 'importante',
            'prix' => 12.5,
            'notes' => ['Pain', 'Lait'],
            'actif' => true,
            'lien' => 'javascript:alert(1)',
            'liste' => ['a', 'b'],
            'objet' => new class {
                public function methode(int $n): string
                {
                    return str_repeat('x', $n);
                }
            },
        ];

        $csrf = new CsrfToken();
        $csrf->start(str_repeat('ab', 32));
        $nonce = new CspNonce();

        $interpreted = new Kioo($this->views, [], $nonce, $csrf)->render('page', $variables);

        $kioo = new Kioo($this->views, [], $nonce, $csrf, $this->compiled, true);
        $kioo->compileAll();

        self::assertSame($interpreted, new Kioo($this->views, [], $nonce, $csrf, $this->compiled, true)->render('page', $variables));
    }

    public function testLayoutsAndIncludesArePreparedToo(): void
    {
        $this->write('base', '<title><k:block name="titre">Site</k:block></title><main><k:block name="content"></k:block></main><k:include file="partiels/pied" annee="{annee}">');
        $this->write('partiels/pied', '<footer>{annee}</footer>');
        $this->write('page', '<k:layout name="base"><k:block name="titre">{titre}</k:block><h1>{titre}</h1>');

        $result = $this->kioo()->compileAll();

        self::assertSame(['base', 'page', 'partiels/pied'], $result['compiled']);
        self::assertSame(
            '<title>Notes</title><main><h1>Notes</h1></main><footer>2026</footer>',
            $this->kioo()->render('page', ['titre' => 'Notes', 'annee' => 2026]),
        );
    }

    // --- Le fichier préparé sert vraiment -----------------------------------------

    public function testThePreparedTreeIsUsedInsteadOfParsing(): void
    {
        $this->write('page', '<p>fixe</p><b>{titre}</b>');
        $this->kioo()->compileAll();

        // On remplace le fichier préparé par un autre arbre, en gardant la
        // marque du template : si Kioo affiche « préparé », c'est qu'il lit ce fichier.
        $file = $this->compiledFiles()[0];
        $code = (string) file_get_contents($file);
        self::assertStringContainsString("'<p>fixe</p>'", $code);
        file_put_contents($file, str_replace("'<p>fixe</p>'", "'<p>préparé</p>'", $code));

        self::assertSame('<p>préparé</p><b>Notes</b>', $this->kioo()->render('page', ['titre' => 'Notes']));
    }

    /**
     * Une page n'est jamais périmée : un template modifié depuis qu'il a été
     * préparé est analysé à nouveau, comme s'il n'avait jamais été préparé.
     */
    public function testATemplateChangedSinceIsParsedAgain(): void
    {
        $this->write('page', '<p>ancien</p>');
        $this->kioo()->compileAll();

        $this->write('page', '<p>nouveau texte</p>');

        self::assertSame('<p>nouveau texte</p>', $this->kioo()->render('page'));
    }

    public function testATemplateTouchedWithoutChangingItsSizeIsParsedAgain(): void
    {
        $this->write('page', '<p>aaaa</p>');
        $this->kioo()->compileAll();

        $this->write('page', '<p>bbbb</p>');
        touch($this->views . '/page.kioo', time() + 10);
        clearstatcache();

        self::assertSame('<p>bbbb</p>', $this->kioo()->render('page'));
    }

    public function testATemplateThatWasNeverPreparedIsParsed(): void
    {
        $this->write('page', '<p>a</p>');
        $this->kioo()->compileAll();
        $this->write('nouvelle', '<p>pas encore préparée</p>');

        self::assertSame('<p>pas encore préparée</p>', $this->kioo()->render('nouvelle'));
    }

    public function testWithoutAnyPreparedFileEverythingStillWorks(): void
    {
        $this->write('page', '<p>{titre}</p>');
        mkdir($this->compiled);

        self::assertSame('<p>Notes</p>', $this->kioo()->render('page', ['titre' => 'Notes']));
    }

    // --- Préparer --------------------------------------------------------------------

    public function testPreparingAgainRemovesTheFilesOfTemplatesThatAreGone(): void
    {
        $this->write('page', '<p>a</p>');
        $this->write('ancienne', '<p>b</p>');
        $this->kioo()->compileAll();

        self::assertCount(2, $this->compiledFiles());

        unlink($this->views . '/ancienne.kioo');
        $result = $this->kioo()->compileAll();

        self::assertSame(['page'], $result['compiled']);
        self::assertSame(1, $result['removed']);
        self::assertCount(1, $this->compiledFiles());
    }

    /**
     * Sécurité : le ménage ne supprime que ce que Kioo a pu écrire. Un autre
     * fichier rangé là par erreur n'est pas touché.
     */
    public function testCleaningUpOnlyRemovesItsOwnFiles(): void
    {
        $this->write('page', '<p>a</p>');
        $this->kioo()->compileAll();
        file_put_contents($this->compiled . '/index.php', '<?php // à moi');
        file_put_contents($this->compiled . '/notes.txt', 'à moi aussi');

        $this->kioo()->compileAll();

        self::assertFileExists($this->compiled . '/index.php');
        self::assertFileExists($this->compiled . '/notes.txt');
    }

    /**
     * Préparer les templates les vérifie tous : une faute arrête tout, avec
     * le nom du template et la ligne. C'est un filet avant la mise en ligne.
     */
    public function testABrokenTemplateStopsThePreparation(): void
    {
        $this->write('bonne', '<p>a</p>');
        $this->write('cassee', "<p>a</p>\n<p k:else>b</p>");

        try {
            $this->kioo()->compileAll();
            self::fail('Une exception était attendue.');
        } catch (KiooException $exception) {
            self::assertStringContainsString('« cassee », ligne 2', $exception->getMessage());
        }
    }

    public function testWithoutADirectoryForPreparedTemplatesItExplainsWhatToWrite(): void
    {
        $this->write('page', '<p>a</p>');

        $this->expectException(KiooException::class);
        $this->expectExceptionMessage('compiledViews:');

        new Kioo($this->views)->compileAll();
    }

    // --- Sécurité : un fichier préparé est du PHP ------------------------------------------

    /**
     * Sécurité (ADR-030) : le texte d'un template ne devient jamais du code.
     * Tout y est écrit par var_export(), entre apostrophes.
     */
    public function testTemplateTextNeverBecomesCode(): void
    {
        $marker = $this->directory . DIRECTORY_SEPARATOR . 'execute.txt';
        $attack = "'); file_put_contents(" . var_export($marker, true) . ", 'x'); ('";

        $this->write('page', '<p class="' . $attack . '">' . $attack . ' <?php file_put_contents(' . var_export($marker, true) . ', "x"); ?> {\'' . "a\\'b" . '\'}</p>');
        $this->kioo()->compileAll();

        $html = $this->kioo()->render('page');

        self::assertFileDoesNotExist($marker, 'Aucun code venu du template n\'a été exécuté.');
        self::assertStringContainsString('file_put_contents', $html, 'Le texte est resté du texte.');
        self::assertSame(new Kioo($this->views)->render('page'), $html);
    }

    public function testThePreparedFileOnlyBuildsKnownClasses(): void
    {
        $this->write('page', '<p k:for="n in notes" class="{n}">{n | upper}</p><p k:if="a ?? false">x</p>');
        $this->kioo()->compileAll();

        $code = (string) file_get_contents($this->compiledFiles()[0]);
        preg_match_all('/new \\\\([A-Za-z\\\\]+)\(/', $code, $classes);

        self::assertNotSame([], $classes[1]);

        foreach (array_unique($classes[1]) as $class) {
            self::assertStringStartsWith('Wazi\View\\', $class);
        }

        self::assertStringStartsWith("<?php\n", $code);
        self::assertSame(1, substr_count($code, 'return ['));
    }

    /**
     * Sécurité (ADR-030) : un dossier où PHP peut écrire est un dossier où une
     * faille pourrait déposer du code. Sans le geste explicite, il n'est pas lu.
     */
    public function testAWritableDirectoryIsNotReadWithoutTheExplicitFlag(): void
    {
        $this->write('page', '<p>fixe</p><b>{titre}</b>');
        $this->kioo()->compileAll();

        $file = $this->compiledFiles()[0];
        file_put_contents($file, str_replace("'<p>fixe</p>'", "'<p>préparé</p>'", (string) file_get_contents($file)));

        // Le dossier temporaire des tests est inscriptible : sans le drapeau, il est ignoré.
        self::assertFalse(new CompiledTemplates($this->compiled)->isUsable());
        self::assertSame('<p>fixe</p><b>Notes</b>', new Kioo($this->views, compiledDirectory: $this->compiled)->render('page', ['titre' => 'Notes']));

        // Avec le drapeau, il est lu.
        self::assertSame('<p>préparé</p><b>Notes</b>', $this->kioo()->render('page', ['titre' => 'Notes']));
    }

    /**
     * Sécurité : dans le dossier public, les fichiers préparés seraient
     * appelables par un navigateur. Ce dossier est refusé, même avec le drapeau.
     */
    public function testADirectoryInsideThePublicDirectoryIsNeverRead(): void
    {
        $this->write('page', '<p>a</p>');
        $this->kioo()->compileAll();

        $previous = $_SERVER['DOCUMENT_ROOT'] ?? null;
        $_SERVER['DOCUMENT_ROOT'] = $this->directory;

        try {
            self::assertFalse(new CompiledTemplates($this->compiled, true)->isUsable());
        } finally {
            if ($previous === null) {
                unset($_SERVER['DOCUMENT_ROOT']);
            } else {
                $_SERVER['DOCUMENT_ROOT'] = $previous;
            }
        }
    }

    /**
     * Le cas voulu en ligne : PHP ne peut pas écrire dans le dossier, donc il
     * le lit sans qu'aucun drapeau soit nécessaire.
     */
    public function testADirectoryThatPhpCannotWriteToIsReadWithoutAnyFlag(): void
    {
        $this->write('page', '<p>fixe</p><b>{titre}</b>');
        $this->kioo()->compileAll();

        $file = $this->compiledFiles()[0];
        file_put_contents($file, str_replace("'<p>fixe</p>'", "'<p>préparé</p>'", (string) file_get_contents($file)));

        chmod($this->compiled, 0o555);
        clearstatcache();

        try {
            // Sous Windows, et pour le compte « root », un dossier reste
            // toujours inscriptible : ce cas ne peut pas y être reproduit.
            if (is_writable($this->compiled)) {
                self::markTestSkipped('Ce système ne permet pas de retirer à PHP le droit d\'écrire dans un dossier.');
            }

            self::assertTrue(new CompiledTemplates($this->compiled)->isUsable());
            self::assertSame(
                '<p>préparé</p><b>Notes</b>',
                new Kioo($this->views, compiledDirectory: $this->compiled)->render('page', ['titre' => 'Notes']),
            );
        } finally {
            // Pour que le dossier puisse être supprimé après le test.
            chmod($this->compiled, 0o755);
        }
    }

    public function testAMissingDirectoryIsSimplyNotUsed(): void
    {
        $this->write('page', '<p>a</p>');

        self::assertFalse(new CompiledTemplates($this->compiled, true)->isUsable());
        self::assertSame('<p>a</p>', $this->kioo()->render('page'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function damagedFiles(): iterable
    {
        yield 'ne retourne rien' => ['<?php'];
        yield 'retourne un texte' => ['<?php return "x";'];
        yield 'sans marque' => ['<?php return ["nodes" => []];'];
        yield 'nœuds qui n\'en sont pas' => ['<?php return ["stamp" => "STAMP", "nodes" => ["<p>injecté</p>", 12]];'];
        yield 'nœuds absents' => ['<?php return ["stamp" => "STAMP"];'];
    }

    /**
     * Un fichier préparé abîmé ne casse pas la page : le template est analysé.
     */
    #[DataProvider('damagedFiles')]
    public function testADamagedPreparedFileIsIgnored(string $content): void
    {
        $this->write('page', '<p>vrai</p>');
        $this->kioo()->compileAll();

        $file = $this->compiledFiles()[0];
        preg_match("/'stamp' => '([^']+)'/", (string) file_get_contents($file), $stamp);
        file_put_contents($file, str_replace('STAMP', $stamp[1] ?? '', $content));

        self::assertSame('<p>vrai</p>', $this->kioo()->render('page'));
    }

    // --- Outils -------------------------------------------------------------------------

    /**
     * Un Kioo qui lit et écrit les templates préparés. Le dossier des tests
     * est inscriptible : le drapeau explicite est donc nécessaire.
     */
    private function kioo(): Kioo
    {
        return new Kioo($this->views, compiledDirectory: $this->compiled, unsafeAllowWritableCompiledDirectory: true);
    }

    private function write(string $name, string $content): void
    {
        file_put_contents($this->views . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $name) . '.kioo', $content);
        clearstatcache();
    }

    /**
     * @return list<string>
     */
    private function compiledFiles(): array
    {
        return glob($this->compiled . DIRECTORY_SEPARATOR . '*.php') ?: [];
    }
}
