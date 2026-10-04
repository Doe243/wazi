<?php

declare(strict_types=1);

namespace Wazi\Tests\View;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wazi\View\Exception\KiooException;
use Wazi\View\Kioo;

/**
 * Ce qu'une application règle une fois, au démarrage : ses filtres et les
 * variables partagées avec tous les templates.
 */
final class KiooSettingsTest extends TestCase
{
    /** Un dossier de vues temporaire, supprimé après chaque test. */
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wazi-' . bin2hex(random_bytes(8));
        mkdir($this->directory . DIRECTORY_SEPARATOR . 'partiels', 0o777, true);
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

    // --- Filtres de l'application -------------------------------------------

    public function testAnApplicationAddsAFilter(): void
    {
        $kioo = new Kioo();
        $kioo->addFilter('euros', static fn(mixed $prix): string => number_format(is_numeric($prix) ? (float) $prix : 0.0, 2, ',', ' ') . ' €');

        self::assertSame('<p>12,50 €</p>', $kioo->renderString('<p>{prix | euros}</p>', ['prix' => 12.5]));
    }

    public function testAFilterReceivesItsArguments(): void
    {
        $kioo = new Kioo();
        $kioo->addFilter('repete', static fn(mixed $texte, mixed $fois = 2): string => str_repeat(is_string($texte) ? $texte : '', is_int($fois) ? $fois : 1));

        self::assertSame('ab ab ab ', $kioo->renderString("{'ab ' | repete(3)}"));
    }

    /**
     * Sécurité : ce que retourne un filtre de l'application est échappé comme
     * toute valeur. Seul « unsafe_raw » écrit du HTML tel quel.
     */
    public function testWhatAnApplicationFilterReturnsIsEscaped(): void
    {
        $kioo = new Kioo();
        $kioo->addFilter('gras', static fn(mixed $texte): string => '<b>' . (is_string($texte) ? $texte : '') . '</b>');

        self::assertSame('&lt;b&gt;titre&lt;/b&gt;', $kioo->renderString('{titre | gras}', ['titre' => 'titre']));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function existingFilters(): iterable
    {
        yield 'filtre de Kioo' => ['upper'];
        yield 'le filtre dangereux' => ['unsafe_raw'];
        yield 'filtre déjà ajouté' => ['euros'];
        yield 'filtre donné au constructeur' => ['initiale'];
    }

    /**
     * Sécurité : un filtre ne peut pas en remplacer un autre. Sinon, un filtre
     * ajouté par mégarde changerait le comportement de tous les templates, ou
     * prendrait la place de « unsafe_raw ».
     */
    #[DataProvider('existingFilters')]
    public function testAFilterCannotReplaceAnotherOne(string $name): void
    {
        $kioo = new Kioo(filters: ['initiale' => static fn(mixed $texte): string => 'A']);
        $kioo->addFilter('euros', static fn(mixed $prix): string => '€');

        try {
            $kioo->addFilter($name, static fn(mixed $value): string => 'détourné');
            self::fail('Une exception était attendue.');
        } catch (KiooException $exception) {
            self::assertStringContainsString('existe déjà', $exception->getMessage());
        }

        self::assertSame('TITRE', $kioo->renderString('{titre | upper}', ['titre' => 'titre']));
        self::assertSame('<b>', $kioo->renderString('{html | unsafe_raw}', ['html' => '<b>']));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'vide' => [''];
        yield 'espace' => ['mon filtre'];
        yield 'tiret' => ['mon-filtre'];
        yield 'chiffre en tête' => ['2euros'];
        yield 'accent' => ['été'];
        yield 'barre' => ['a|b'];
        yield 'retour à la ligne' => ["euros\nFAUSSE LIGNE"];
    }

    #[DataProvider('invalidNames')]
    public function testAFilterNameIsASimpleName(string $name): void
    {
        try {
            new Kioo()->addFilter($name, static fn(mixed $value): string => '');
            self::fail('Une exception était attendue.');
        } catch (KiooException $exception) {
            self::assertStringContainsString('filtre', $exception->getMessage());
            self::assertStringNotContainsString("\n", $exception->getMessage());
        }
    }

    #[DataProvider('invalidNames')]
    public function testASharedVariableNameIsASimpleName(string $name): void
    {
        try {
            new Kioo()->share($name, 1);
            self::fail('Une exception était attendue.');
        } catch (KiooException $exception) {
            self::assertStringContainsString('variable', $exception->getMessage());
            self::assertStringNotContainsString("\n", $exception->getMessage());
        }
    }

    // --- Variables partagées --------------------------------------------------

    public function testASharedVariableIsSeenByEveryTemplate(): void
    {
        $this->write('base', '<header>{site}</header><k:block name="content"></k:block><k:include file="partiels/pied">');
        $this->write('partiels/pied', '<footer>{site} {annee}</footer>');
        $this->write('page', '<k:layout name="base"><p>{site}</p>');

        $kioo = new Kioo($this->directory);
        $kioo->share('site', 'Carnet');
        $kioo->share('annee', 2026);

        self::assertSame('<header>Carnet</header><p>Carnet</p><footer>Carnet 2026</footer>', $kioo->render('page'));
    }

    public function testAVariableGivenToThePageWinsOverASharedOne(): void
    {
        $kioo = new Kioo();
        $kioo->share('titre', 'partagé');

        self::assertSame('de la page', $kioo->renderString('{titre}', ['titre' => 'de la page']));
        self::assertSame('partagé', $kioo->renderString('{titre}'));
    }

    public function testAVariableGivenToAnIncludeWinsOverASharedOne(): void
    {
        $this->write('partiels/pied', '{annee}');
        $this->write('page', '<k:include file="partiels/pied" annee="{1999}">/<k:include file="partiels/pied">');

        $kioo = new Kioo($this->directory);
        $kioo->share('annee', 2026);

        self::assertSame('1999/2026', $kioo->render('page'));
    }

    /**
     * Une valeur connue seulement au moment d'afficher (le visiteur connecté,
     * un message) se donne par une fonction, appelée une fois par page.
     */
    public function testASharedFunctionIsCalledOncePerRenderedPage(): void
    {
        $this->write('base', '{compteur}<k:block name="content"></k:block><k:include file="partiels/pied">');
        $this->write('partiels/pied', '{compteur}');
        $this->write('page', '<k:layout name="base">{compteur}');

        $calls = 0;
        $kioo = new Kioo($this->directory);
        $kioo->share('compteur', static function () use (&$calls): int {
            return ++$calls;
        });

        self::assertSame(0, $calls, 'Rien n\'est calculé tant qu\'aucune page n\'est affichée.');
        self::assertSame('111', $kioo->render('page'));
        self::assertSame('222', $kioo->render('page'));
    }

    public function testASharedValueIsEscapedLikeAnyOther(): void
    {
        $kioo = new Kioo();
        $kioo->share('nom', '<script>alert(1)</script>');

        self::assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', $kioo->renderString('{nom}'));
    }

    /**
     * Sécurité : hormis ce que l'application partage volontairement, un
     * template inclus ne voit toujours rien de la page qui l'inclut.
     */
    public function testAnIncludeStillDoesNotSeeTheVariablesOfThePage(): void
    {
        $this->write('partiels/pied', '{secret}');
        $this->write('page', '<k:include file="partiels/pied">');

        $kioo = new Kioo($this->directory);
        $kioo->share('annee', 2026);

        $this->expectException(KiooException::class);
        $this->expectExceptionMessage('« secret »');

        $kioo->render('page', ['secret' => 'ne doit pas passer']);
    }

    public function testAnUnknownVariableIsStillAnError(): void
    {
        $kioo = new Kioo();
        $kioo->share('annee', 2026);

        $this->expectException(KiooException::class);
        $this->expectExceptionMessage('« anee »');

        $kioo->renderString('{anee}');
    }

    // --- Le filtre « url » --------------------------------------------------------

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function urlValues(): iterable
    {
        yield 'espaces et esperluette' => ['pain & lait', 'pain%20%26%20lait'];
        yield 'ce qui couperait l\'adresse' => ['a#b?c=d/e', 'a%23b%3Fc%3Dd%2Fe'];
        yield 'accents' => ['été', '%C3%A9t%C3%A9'];
        yield 'guillemets et balise' => ['"><script>', '%22%3E%3Cscript%3E'];
        yield 'nombre' => [42, '42'];
        yield 'vide' => ['', ''];
    }

    #[DataProvider('urlValues')]
    public function testUrlPreparesAValueForAnAddress(mixed $value, string $expected): void
    {
        self::assertSame(
            '<a href="/recherche?q=' . $expected . '">x</a>',
            new Kioo()->renderString('<a href="/recherche?q={mots | url}">x</a>', ['mots' => $value]),
        );
    }

    public function testUrlRefusesWhatIsNotATextOrANumber(): void
    {
        $this->expectException(KiooException::class);
        $this->expectExceptionMessage('url');

        new Kioo()->renderString('{mots | url}', ['mots' => ['a']]);
    }

    private function write(string $name, string $content): void
    {
        file_put_contents($this->directory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $name) . '.kioo', $content);
    }
}
