<?php

declare(strict_types=1);

namespace Wazi\Tests\View;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wazi\Http\CspNonce;
use Wazi\Http\CsrfToken;
use Wazi\View\Exception\KiooException;
use Wazi\View\Kioo;
use Wazi\View\Template\Element;
use Wazi\View\Template\StaticElement;
use Wazi\View\Template\TemplateParser;

/**
 * Ce que Kioo prépare dès la lecture d'un template (ADR-025) : les
 * commentaires sont retirés, et les balises qui ne dépendent d'aucune valeur
 * sont écrites une fois pour toutes.
 */
final class KiooPreparedTest extends TestCase
{
    // --- Commentaires -----------------------------------------------------------

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function comments(): iterable
    {
        yield 'dans une ligne' => ['<p>a<!-- note --> b</p>', '<p>a b</p>'];
        yield 'seul dans la page' => ['<!-- note -->', ''];
        yield 'seul sur sa ligne : la ligne disparaît' => ["<ul>\n    <!-- la liste -->\n    <li>a</li>\n</ul>", "<ul>\n    <li>a</li>\n</ul>"];
        yield 'sur plusieurs lignes' => ["<p>a</p>\n<!-- une\n     explication\n     longue -->\n<p>b</p>", "<p>a</p>\n<p>b</p>"];
        yield 'en fin de ligne : la ligne reste' => ["<p>a</p> <!-- note -->\n<p>b</p>", "<p>a</p> \n<p>b</p>"];
        yield 'en début de ligne, suivi de texte' => ["<!-- note --><p>a</p>\n", "<p>a</p>\n"];
        yield 'premier du fichier' => ["<!-- en-tête -->\n<p>a</p>", '<p>a</p>'];
        yield 'dernier du fichier' => ["<p>a</p>\n<!-- fin -->", "<p>a</p>\n"];
        yield 'fins de ligne Windows' => ["<p>a</p>\r\n  <!-- note -->\r\n<p>b</p>", "<p>a</p>\r\n<p>b</p>"];
        yield 'deux de suite' => ["<p>a</p>\n<!-- un -->\n<!-- deux -->\n<p>b</p>", "<p>a</p>\n<p>b</p>"];
        yield 'des accolades dedans : jamais interprétées' => ['<!-- {titre} {inconnue | filtre} --><p>a</p>', '<p>a</p>'];
        yield 'une balise dedans : jamais interprétée' => ['<!-- <p k:if="inconnue">x</p> --><p>a</p>', '<p>a</p>'];
        yield 'après une valeur' => ["<p>{titre}</p>\n  <!-- note -->\n<p>b</p>", "<p>Carnet</p>\n<p>b</p>"];
        yield 'dans une boucle : pas répété' => ["<ul><li k:for=\"n in notes\"><!-- une note -->{n}</li></ul>", '<ul><li>a</li><li>b</li></ul>'];
        yield 'la déclaration du document reste' => ["<!DOCTYPE html>\n<!-- note -->\n<html></html>", "<!DOCTYPE html>\n<html></html>"];
    }

    /**
     * Sécurité : un commentaire s'adresse à qui lit le template. Il n'est
     * jamais envoyé au visiteur, à qui il apprendrait comment le site est fait.
     */
    #[DataProvider('comments')]
    public function testCommentsAreNeverSentToTheVisitor(string $template, string $expected): void
    {
        self::assertSame($expected, new Kioo()->renderString($template, ['titre' => 'Carnet', 'notes' => ['a', 'b']]));
    }

    public function testAnUnterminatedCommentIsAnError(): void
    {
        $this->expectException(KiooException::class);
        $this->expectExceptionMessage('-->');

        new Kioo()->renderString("<p>a</p>\n<!-- jamais fermé");
    }

    public function testCommentsInsideScriptAndStyleAreLeftAlone(): void
    {
        $template = '<script>// <!-- pas un commentaire HTML -->' . "\n" . 'var a = 1;</script><style>/* <!-- idem --> */</style>';

        self::assertSame($template, new Kioo()->renderString($template));
    }

    /**
     * Le numéro de ligne d'une erreur compte les lignes du FICHIER, y compris
     * celles des commentaires retirés.
     */
    public function testLineNumbersStillCountTheRemovedComments(): void
    {
        try {
            new Kioo()->renderString("<p>a</p>\n<!-- un\n     commentaire -->\n\n<p>{inconnue}</p>");
            self::fail('Une exception était attendue.');
        } catch (KiooException $exception) {
            self::assertStringContainsString('ligne 5', $exception->getMessage());
        }
    }

    // --- Balises fixes ------------------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function fixedMarkup(): iterable
    {
        yield 'icône' => ['<svg class="icone" aria-hidden="true"><use href="/icones.svg#loupe"></use></svg>'];
        yield 'balises imbriquées' => ['<nav><ul><li><a href="/">Accueil</a></li><li><a href="/notes">Notes</a></li></ul></nav>'];
        yield 'balise sans contenu' => ['<img src="a.png" alt="">'];
        yield 'balise auto-fermante' => ['<path d="M1 2" />'];
        yield 'attribut sans valeur, guillemets simples, sans guillemets' => ["<input disabled class='a b' type=text>"];
        yield 'entités' => ['<p title="a &amp; b">Fromage &amp; dessert &lt;3</p>'];
        yield 'balise jamais fermée' => ['<ul><li>Un<li>Deux</ul>'];
        yield 'style' => ['<style>p { color: red }</style>'];
        yield 'majuscules' => ['<DIV CLASS="a"><P>x</P></DIV>'];
    }

    #[DataProvider('fixedMarkup')]
    public function testFixedMarkupComesOutExactlyAsWritten(string $html): void
    {
        self::assertSame($html, new Kioo()->renderString($html));

        $nodes = new TemplateParser()->parse($html);
        self::assertInstanceOf(StaticElement::class, $nodes[0], 'Cette balise ne dépend d\'aucune valeur : elle est écrite dès la lecture.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function markupDecidedAtRenderTime(): iterable
    {
        yield 'une valeur dans le texte' => ['<p>{titre}</p>'];
        yield 'une valeur dans un attribut' => ['<p class="{titre}">x</p>'];
        yield 'une valeur plus profond' => ['<div><ul><li>{titre}</li></ul></div>'];
        yield 'k:if' => ['<p k:if="titre">x</p>'];
        yield 'k:for' => ['<li k:for="n in notes">x</li>'];
        yield 'un formulaire : il reçoit le jeton de protection' => ['<form method="post" action="/notes"></form>'];
        yield 'un formulaire plus profond' => ['<div><form method="post"></form></div>'];
        yield 'un script : il reçoit le jeton du jour' => ['<script>var a = 1;</script>'];
        yield 'un script plus profond' => ['<div><script src="/app.js"></script></div>'];
        yield 'une balise de Kioo' => ['<div><k:json id="d" value="{notes}"></div>'];
    }

    #[DataProvider('markupDecidedAtRenderTime')]
    public function testWhatDependsOnTheRequestIsNotPrepared(string $html): void
    {
        self::assertInstanceOf(Element::class, new TemplateParser()->parse($html)[0]);
    }

    /**
     * Sécurité : préparer les balises fixes ne doit rien changer aux deux
     * jetons, qui sont propres à chaque requête.
     */
    public function testTheTokensAreStillAddedInsideFixedMarkup(): void
    {
        $csrf = new CsrfToken();
        $csrf->start(null);
        $nonce = new CspNonce();
        $kioo = new Kioo(null, [], $nonce, $csrf);

        $html = $kioo->renderString('<main><section><form method="post" action="/a"><button>Ok</button></form><script>a()</script></section></main>');

        self::assertStringContainsString('<input type="hidden" name="_csrf" value="' . $csrf->value() . '">', $html);
        self::assertStringContainsString('<script nonce="' . $nonce->value . '">', $html);
    }

    public function testAFixedTagInsideALoopIsWrittenAtEachTurn(): void
    {
        $html = new Kioo()->renderString('<li k:for="n in notes"><svg><use href="#a"></use></svg>{n}</li>', ['notes' => ['a', 'b', 'c']]);

        self::assertSame(3, substr_count($html, '<svg><use href="#a"></use></svg>'));
    }

    public function testAFixedTagBeforeTheLayoutIsStillRefused(): void
    {
        $this->expectException(KiooException::class);
        $this->expectExceptionMessage('k:layout');

        new Kioo()->renderString('<p>avant</p><k:layout name="base">');
    }

    public function testElseStillNeedsAStructureBeforeItNotAFixedTag(): void
    {
        $this->expectException(KiooException::class);
        $this->expectExceptionMessage('k:else se place');

        new Kioo()->renderString('<p>fixe</p><p k:else>b</p>');
    }
}
