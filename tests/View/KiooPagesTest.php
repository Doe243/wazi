<?php

declare(strict_types=1);

namespace Wazi\Tests\View;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wazi\Http\CspNonce;
use Wazi\View\Exception\KiooException;
use Wazi\View\Kioo;

/**
 * Ce que Kioo fait pour une vraie page : passer des données au JavaScript,
 * autoriser les scripts des templates, garder un code source lisible.
 */
final class KiooPagesTest extends TestCase
{
    // --- Données pour le JavaScript ----------------------------------------

    public function testKJsonWritesAValueAsADataScript(): void
    {
        $html = new Kioo()->renderString('<k:json id="donnees" value="{notes}">', ['notes' => [['id' => 1, 'texte' => 'Été'], ['id' => 2, 'texte' => 'Pain']]]);

        self::assertSame('<script type="application/json" id="donnees">[{"id":1,"texte":"Été"},{"id":2,"texte":"Pain"}]</script>', $html);
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function jsonValues(): iterable
    {
        yield 'texte' => ['bonjour', '"bonjour"'];
        yield 'nombre' => [42, '42'];
        yield 'vrai' => [true, 'true'];
        yield 'null' => [null, 'null'];
        yield 'liste vide' => [[], '[]'];
        yield 'tableau à clés' => [['a' => 1], '{"a":1}'];
        yield 'objet aux propriétés publiques' => [new readonly class {
            public function __construct(public int $id = 7, public string $nom = 'x') {}
        }, '{"id":7,"nom":"x"}'];
    }

    #[DataProvider('jsonValues')]
    public function testKJsonAcceptsAnyValue(mixed $value, string $expected): void
    {
        self::assertSame(
            '<script type="application/json" id="d">' . $expected . '</script>',
            new Kioo()->renderString('<k:json id="d" value="{v}" />', ['v' => $value]),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function valuesThatTryToLeaveTheScript(): iterable
    {
        yield 'fermeture de balise script' => ['</script><script>alert(1)</script>'];
        yield 'fermeture en majuscules' => ['</SCRIPT><img src=x onerror=alert(1)>'];
        yield 'commentaire HTML' => ['<!-- <script>'];
        yield 'esperluette et guillemets' => ['a & "b" \'c\''];
    }

    /**
     * Sécurité : une valeur ne peut pas refermer la balise <script> qui la contient.
     */
    #[DataProvider('valuesThatTryToLeaveTheScript')]
    public function testAValueInKJsonCannotCloseTheScript(string $value): void
    {
        $html = new Kioo()->renderString('<k:json id="d" value="{v}"><p>après</p>', ['v' => $value]);
        $document = \Dom\HTMLDocument::createFromString('<!DOCTYPE html><body>' . $html, LIBXML_NOERROR);

        self::assertSame(1, substr_count(strtolower($html), '</script>'));
        self::assertStringNotContainsString('<img', $html);
        self::assertSame(1, $document->querySelectorAll('script')->length);
        self::assertSame($value, json_decode((string) $document->getElementById('d')?->textContent, flags: JSON_THROW_ON_ERROR));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidKJson(): iterable
    {
        yield 'sans id' => ['<k:json value="{v}">', 'a besoin de l\'attribut « id »'];
        yield 'sans value' => ['<k:json id="d">', 'a besoin de l\'attribut « value »'];
        yield 'id rempli par une valeur' => ['<k:json id="{v}" value="{v}">', 'doit être écrit en toutes lettres'];
    }

    #[DataProvider('invalidKJson')]
    public function testAnInvalidKJsonIsExplained(string $template, string $expectedHint): void
    {
        $this->expectException(KiooException::class);
        $this->expectExceptionMessage($expectedHint);

        new Kioo()->renderString($template, ['v' => 'x']);
    }

    public function testAValueThatCannotBeWrittenAsJsonIsExplained(): void
    {
        $this->expectException(KiooException::class);
        $this->expectExceptionMessage('qui peut s\'écrire en JSON');

        new Kioo()->renderString('<k:json id="d" value="{v}">', ['v' => "octets \xFF invalides"]);
    }

    public function testTheJsonFilterPassesDataThroughAnAttribute(): void
    {
        $html = new Kioo()->renderString('<div data-points="{points | json}"></div>', ['points' => [['x' => 1, 'nom' => 'a"b<c']]]);
        $div = \Dom\HTMLDocument::createFromString('<!DOCTYPE html><body>' . $html, LIBXML_NOERROR)->querySelector('div');

        self::assertNotNull($div);
        self::assertSame(1, $div->attributes->length, 'La valeur n\'est pas sortie de son attribut.');
        self::assertSame([['x' => 1, 'nom' => 'a"b<c']], json_decode((string) $div->getAttribute('data-points'), true, flags: JSON_THROW_ON_ERROR));
    }

    // --- Jeton des scripts -------------------------------------------------

    public function testScriptsOfATemplateReceiveTheNonce(): void
    {
        $nonce = new CspNonce();
        $kioo = new Kioo(nonce: $nonce);

        $html = $kioo->renderString('<script>a();</script><script src="/app.js"></script><SCRIPT type="module">b();</SCRIPT>');

        self::assertSame(
            '<script nonce="' . $nonce->value . '">a();</script><script src="/app.js" nonce="' . $nonce->value . '"></script><SCRIPT type="module" nonce="' . $nonce->value . '">b();</SCRIPT>',
            $html,
        );
    }

    public function testWithoutNonceScriptsAreLeftAsTheyAre(): void
    {
        self::assertSame('<script>a();</script>', new Kioo()->renderString('<script>a();</script>'));
    }

    public function testANonceWrittenByHandIsKept(): void
    {
        $html = new Kioo(nonce: new CspNonce())->renderString('<script nonce="le-mien">a();</script>');

        self::assertSame('<script nonce="le-mien">a();</script>', $html);
    }

    public function testOtherElementsDoNotReceiveTheNonce(): void
    {
        $html = new Kioo(nonce: new CspNonce())->renderString('<style>a{}</style><p>texte</p><k:json id="d" value="{v}">', ['v' => 1]);

        self::assertStringNotContainsString('nonce', $html);
    }

    /**
     * Sécurité : le jeton n'est posé que sur les balises écrites dans le
     * TEMPLATE. Un script arrivé par une valeur ne l'obtient jamais, même par
     * unsafe_raw : le navigateur refusera de l'exécuter.
     */
    public function testAScriptComingFromAValueNeverReceivesTheNonce(): void
    {
        $nonce = new CspNonce();
        $kioo = new Kioo(nonce: $nonce);
        $attack = '<script>alert(1)</script>';

        $escaped = $kioo->renderString('<p>{v}</p>', ['v' => $attack]);
        $raw = $kioo->renderString('<p>{v | unsafe_raw}</p>', ['v' => $attack]);

        self::assertStringNotContainsString($nonce->value, $escaped);
        self::assertStringNotContainsString($nonce->value, $raw);
        self::assertSame('<p><script>alert(1)</script></p>', $raw);
    }

    // --- Adresses multiples ------------------------------------------------

    public function testSrcsetIsCheckedLikeAnyAddress(): void
    {
        $kioo = new Kioo();

        self::assertSame('<img srcset="#">', $kioo->renderString('<img srcset="{s}">', ['s' => 'javascript:alert(1) 1x']));
        self::assertSame('<img srcset="/a.png 1x, /b.png 2x">', $kioo->renderString('<img srcset="{s}">', ['s' => '/a.png 1x, /b.png 2x']));
    }

    // --- Code source lisible -----------------------------------------------

    public function testALoopKeepsOneElementPerLine(): void
    {
        $template = "<ul>\n    <li k:for=\"n in notes\">{n}</li>\n</ul>";

        self::assertSame("<ul>\n    <li>a</li>\n    <li>b</li>\n    <li>c</li>\n</ul>", new Kioo()->renderString($template, ['notes' => ['a', 'b', 'c']]));
    }

    public function testALoopWrittenOnOneLineStaysOnOneLine(): void
    {
        self::assertSame('<ul><li>a</li><li>b</li></ul>', new Kioo()->renderString('<ul><li k:for="n in notes">{n}</li></ul>', ['notes' => ['a', 'b']]));
    }

    public function testTheIndentationOfALoopFollowsTabsAndWindowsLineEndings(): void
    {
        $template = "<ul>\r\n\t<li k:for=\"n in notes\">{n}</li>\r\n</ul>";

        self::assertSame("<ul>\r\n\t<li>a</li>\r\n\t<li>b</li>\r\n</ul>", new Kioo()->renderString($template, ['notes' => ['a', 'b']]));
    }

    // --- Réponse HTML ------------------------------------------------------

    public function testPageReturnsAnHtmlResponse(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wazi-' . bin2hex(random_bytes(8));
        mkdir($directory);
        file_put_contents($directory . '/accueil.kioo', '<h1>{titre}</h1>');

        try {
            $kioo = new Kioo($directory);
            $response = $kioo->page('accueil', ['titre' => 'Bonjour']);
            $notFound = $kioo->page('accueil', ['titre' => 'Introuvable'], 404);
        } finally {
            unlink($directory . '/accueil.kioo');
            rmdir($directory);
        }

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('<h1>Bonjour</h1>', (string) $response->getBody());
        self::assertSame(404, $notFound->getStatusCode());
    }
}
