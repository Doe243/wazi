<?php

declare(strict_types=1);

namespace Wazi\Tests\View;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wazi\View\Exception\KiooException;
use Wazi\View\Kioo;

/**
 * Les structures de Kioo : k:if, k:else et k:for.
 */
final class KiooStructuresTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function templates(): iterable
    {
        // k:if
        yield 'condition vraie : la balise est écrite, sans son attribut k:if' => ['<p k:if="actif" class="a">oui</p>', '<p class="a">oui</p>'];
        yield 'condition fausse : rien' => ['<p k:if="inactif">oui</p>', ''];
        yield 'expression complète' => ['<p k:if="age >= 18 and actif">majeur</p>', '<p>majeur</p>'];
        yield 'liste remplie' => ['<p k:if="notes">des notes</p>', '<p>des notes</p>'];
        yield 'liste vide' => ['<p k:if="aucune">des notes</p>', ''];
        yield 'filtre dans la condition' => ['<p k:if="(notes | length) > 1">plusieurs</p>', '<p>plusieurs</p>'];
        yield 'guillemets simples autour de la valeur' => ["<p k:if='age == 30'>trente</p>", '<p>trente</p>'];
        yield 'texte entre guillemets simples dans la condition' => ['<p k:if="prenom == \'René\'">René</p>', '<p>René</p>'];
        yield 'signe plus petit écrit en entité' => ['<p k:if="age &lt; 40">jeune</p>', '<p>jeune</p>'];
        yield 'sur une balise sans contenu' => ['<img k:if="actif" src="a.png">', '<img src="a.png">'];
        yield 'le contenu d\'une balise écartée n\'est pas calculé' => ['<p k:if="inactif">{inconnue}</p>', ''];
        yield 'imbriquées' => ['<div k:if="actif"><p k:if="inactif">a</p><p k:if="actif">b</p></div>', '<div><p>b</p></div>'];

        // k:else
        yield 'sinon, condition vraie' => ['<p k:if="actif">oui</p><p k:else>non</p>', '<p>oui</p>'];
        yield 'sinon, condition fausse' => ['<p k:if="inactif">oui</p><p k:else class="b">non</p>', '<p class="b">non</p>'];
        yield 'sinon, séparé par des retours à la ligne' => ["<p k:if=\"inactif\">oui</p>\n\n  <p k:else>non</p>", '<p>non</p>'];
        yield 'sinon, séparé par un commentaire' => ["<p k:if=\"inactif\">oui</p>
<!-- sinon : le visiteur est connecté -->
<p k:else>non</p>", '<p>non</p>'];
        yield 'sinon, séparé par un commentaire, condition vraie' => ['<p k:if="actif">oui</p><!-- sinon --><p k:else>non</p>', '<p>oui</p>'];
        yield 'sinon, séparé par deux commentaires' => ['<p k:if="inactif">oui</p> <!-- a --> <!-- b --> <p k:else>non</p>', '<p>non</p>'];
        yield 'liste vide, sinon après un commentaire' => ['<li k:for="note in aucune">{note}</li><!-- liste vide --><li k:else>Aucune.</li>', '<li>Aucune.</li>'];
        yield 'les commentaires voisins ne sont pas écrits' => ['<!-- avant --><p k:if="actif">oui</p><p k:else>non</p><!-- après -->', '<p>oui</p>'];
        yield 'sinon sur une autre sorte de balise' => ['<strong k:if="inactif">oui</strong><em k:else>non</em>', '<em>non</em>'];
        yield 'ce qui suit le sinon est écrit dans les deux cas' => ['<p k:if="actif">oui</p><p k:else>non</p><hr>', '<p>oui</p><hr>'];

        // k:for
        yield 'une balise par élément' => ['<li k:for="note in notes">{note}</li>', '<li>Pain</li><li>Lait</li><li>Œufs</li>'];
        yield 'les attributs de la balise voient l\'élément' => ['<li k:for="note in notes" title="{note}">x</li>', '<li title="Pain">x</li><li title="Lait">x</li><li title="Œufs">x</li>'];
        yield 'avec le numéro' => ['<li k:for="i, note in notes">{i + 1}. {note}</li>', '<li>1. Pain</li><li>2. Lait</li><li>3. Œufs</li>'];
        yield 'avec la clé' => ['<dd k:for="cle, valeur in auteur">{cle} : {valeur}</dd>', '<dd>prenom : René</dd><dd>nom : Mumba</dd>'];
        yield 'liste vide : rien' => ['<ul><li k:for="note in aucune">{note}</li></ul>', '<ul></ul>'];
        yield 'liste vide, avec sinon' => ['<ul><li k:for="note in aucune">{note}</li><li k:else>Aucune note.</li></ul>', '<ul><li>Aucune note.</li></ul>'];
        yield 'liste remplie, avec sinon' => ['<li k:for="note in notes">{note}</li><li k:else>Aucune.</li>', '<li>Pain</li><li>Lait</li><li>Œufs</li>'];
        yield 'expression comme liste' => ['<li k:for="note in inconnue ?? aucune">{note}</li><li k:else>Rien.</li>', '<li>Rien.</li>'];
        yield 'objets' => ['<li k:for="p in personnes">{p.nom} ({p.age})</li>', '<li>Alice (30)</li><li>Bob (17)</li>'];
        yield 'condition à l\'intérieur d\'une boucle' => ['<li k:for="p in personnes"><b k:if="p.age >= 18">{p.nom}</b><i k:else>{p.nom}</i></li>', '<li><b>Alice</b></li><li><i>Bob</i></li>'];
        yield 'boucles imbriquées' => ['<tr k:for="ligne in tableau"><td k:for="c in ligne">{c}</td></tr>', '<tr><td>1</td><td>2</td></tr><tr><td>3</td><td>4</td></tr>'];
        yield 'une variable de la page reste visible dans la boucle' => ['<li k:for="note in notes">{prenom} : {note}</li>', '<li>René : Pain</li><li>René : Lait</li><li>René : Œufs</li>'];
        yield 'générateur' => ['<li k:for="n in compte">{n}</li>', '<li>1</li><li>2</li><li>3</li>'];
        yield 'boucle sur une balise sans contenu' => ['<br k:for="note in notes">', '<br><br><br>'];
        yield 'espaces dans la valeur' => ['<li k:for="  i ,  note   in   notes ">{i}</li>', '<li>0</li><li>1</li><li>2</li>'];
    }

    #[DataProvider('templates')]
    public function testStructuresDecideWhatIsWritten(string $template, string $expected): void
    {
        self::assertSame($expected, new Kioo()->renderString($template, self::variables()));
    }

    /**
     * L'élément d'une boucle n'existe que dans sa balise : après elle, la
     * variable de la page (ou son absence) est retrouvée intacte.
     */
    public function testTheLoopVariableOnlyExistsInsideItsElement(): void
    {
        $kioo = new Kioo();

        self::assertSame(
            '<li>Pain</li><li>Lait</li><li>Œufs</li><p>René</p>',
            $kioo->renderString('<li k:for="prenom in notes">{prenom}</li><p>{prenom}</p>', self::variables()),
            'Une variable masquée par la boucle retrouve sa valeur.',
        );

        $this->expectException(KiooException::class);
        $this->expectExceptionMessage('La variable « note » n\'existe pas');

        $kioo->renderString('<li k:for="note in notes">{note}</li><p>{note}</p>', self::variables());
    }

    public function testValuesInsideALoopAreEscaped(): void
    {
        $html = new Kioo()->renderString('<li k:for="x in liste" title="{x}">{x}</li>', ['liste' => ['<script>alert(1)</script>', '" onclick="x']]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertSame(2, substr_count($html, '<li '));
        self::assertStringContainsString('title="&quot; onclick=&quot;x"', $html);
    }

    // --- Erreurs pédagogiques ----------------------------------------------

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidTemplates(): iterable
    {
        yield 'accolades dans k:if' => ['<p k:if="{actif}">x</p>', 'sans accolades : k:if="total > 0"'];
        yield 'opérateur après un filtre' => ['<p k:if="notes | length > 1">x</p>', 'entourez-le de parenthèses : (valeur | length) > 1'];
        yield 'accolades dans k:for' => ['<li k:for="note in {notes}">x</li>', 'sans accolades : k:for="note in notes"'];
        yield 'k:if vide' => ['<p k:if="">x</p>', 'une valeur'];
        yield 'k:if sans valeur' => ['<p k:if>x</p>', 'une valeur'];
        yield 'k:if mal écrit' => ['<p k:if="age >">x</p>', 'est mal écrite'];
        yield 'k:for sans « in »' => ['<li k:for="notes">x</li>', 'un élément dans une liste'];
        yield 'k:for à la manière de PHP' => ['<li k:for="notes as note">x</li>', 'k:for="note in notes"'];
        yield 'k:for avec une liste mal écrite' => ['<li k:for="note in notes +">x</li>', 'est mal écrite'];
        yield 'k:else sans k:if avant' => ['<p>a</p><p k:else>b</p>', 'k:else se place sur la balise qui suit immédiatement'];
        yield 'k:else en tout premier' => ['<p k:else>b</p>', 'k:else se place'];
        yield 'k:else séparé par du texte' => ['<p k:if="actif">a</p> ou <p k:else>b</p>', 'k:else se place'];
        yield 'k:else séparé par un commentaire et du texte' => ['<p k:if="actif">a</p><!-- c --> ou <p k:else>b</p>', 'seuls des espaces et des commentaires'];
        yield 'k:else séparé par une autre balise' => ['<p k:if="actif">a</p><hr><p k:else>b</p>', 'k:else se place'];
        yield 'k:else après un commentaire, sans k:if' => ['<!-- seul --><p k:else>b</p>', 'k:else se place'];
        yield 'k:else séparé par une déclaration' => ['<p k:if="actif">a</p><!DOCTYPE html><p k:else>b</p>', 'k:else se place'];
        yield 'deux k:else' => ['<p k:if="actif">a</p><p k:else>b</p><p k:else>c</p>', 'a déjà son k:else'];
        yield 'k:if et k:for ensemble' => ['<li k:for="note in notes" k:if="actif">x</li>', 'Gardez-en un seul'];
        yield 'k:if et k:else ensemble' => ['<p k:if="actif">a</p><p k:else k:if="inactif">b</p>', 'Gardez-en un seul'];
        yield 'attribut k: inconnu, avec suggestion' => ['<p k:fi="actif">x</p>', 'Vouliez-vous écrire « k:if » ?'];
        yield 'attribut k: inconnu : la liste est donnée' => ['<p k:show="actif">x</p>', 'Disponibles : k:if, k:else, k:for'];
        yield 'balise k: inconnue' => ['<k:foreach>x</k:foreach>', 'Disponibles : <k:layout>, <k:include>, <k:block>'];
        yield 'balise à structure jamais fermée' => ['<ul><li k:for="note in notes">{note}</ul>', 'Ajoutez la balise fermante </li>'];
        yield 'balise k:if jamais fermée' => ['<p k:if="actif">texte', 'Ajoutez la balise fermante </p>'];
    }

    #[DataProvider('invalidTemplates')]
    public function testAnInvalidStructureIsExplainedWhenTheTemplateIsRead(string $template, string $expectedHint): void
    {
        try {
            new Kioo()->renderString($template, self::variables(), 'liste.kioo');
            self::fail('Une exception était attendue.');
        } catch (KiooException $exception) {
            self::assertStringContainsString('Dans le template « liste.kioo », ligne 1 :', $exception->getMessage());
            self::assertStringContainsString($expectedHint, $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function failingTemplates(): iterable
    {
        yield 'k:for sur un texte' => ["<ul>\n<li k:for=\"x in prenom\">{x}</li>\n</ul>", 2, 'de type string'];
        yield 'k:for sur null' => ['<li k:for="x in rien">{x}</li>', 1, 'de type null'];
        yield 'k:for sur une variable inconnue' => ["\n\n<li k:for=\"x in nots\">{x}</li>", 3, 'Vouliez-vous écrire « notes » ?'];
        yield 'k:if sur une variable inconnue' => ["<p>a</p>\n<p k:if=\"actiff\">b</p>", 2, 'Vouliez-vous écrire « actif » ?'];
        yield 'erreur dans le contenu d\'une boucle' => ["<li k:for=\"note in notes\">\n{note.x}\n</li>", 2, 'de type string'];
    }

    #[DataProvider('failingTemplates')]
    public function testAFailingStructureTellsTheLine(string $template, int $line, string $expectedHint): void
    {
        try {
            new Kioo()->renderString($template, self::variables(), 'liste.kioo');
            self::fail('Une exception était attendue.');
        } catch (KiooException $exception) {
            self::assertStringContainsString('ligne ' . $line . ' :', $exception->getMessage());
            self::assertStringContainsString($expectedHint, $exception->getMessage());
        }
    }

    public function testAStructureAttributeIsNeverWrittenInThePage(): void
    {
        $html = new Kioo()->renderString(
            '<ul><li k:for="note in notes" class="a">{note}</li><li k:else>x</li></ul><p k:if="actif">y</p><p k:if="inactif">z</p><p k:else>w</p>',
            self::variables() + ['x' => 1],
            'liste.kioo',
        );

        self::assertStringNotContainsString('k:', $html);
    }

    /**
     * @return array<string, mixed>
     */
    private static function variables(): array
    {
        return [
            'actif' => true,
            'inactif' => false,
            'age' => 30,
            'prenom' => 'René',
            'rien' => null,
            'notes' => ['Pain', 'Lait', 'Œufs'],
            'aucune' => [],
            'auteur' => ['prenom' => 'René', 'nom' => 'Mumba'],
            'personnes' => [['nom' => 'Alice', 'age' => 30], ['nom' => 'Bob', 'age' => 17]],
            'tableau' => [[1, 2], [3, 4]],
            'compte' => (static function (): \Generator {
                yield 1;
                yield 2;
                yield 3;
            })(),
        ];
    }
}
