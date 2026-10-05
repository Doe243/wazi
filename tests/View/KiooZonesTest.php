<?php

declare(strict_types=1);

namespace Wazi\Tests\View;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wazi\View\Exception\KiooException;
use Wazi\View\Kioo;

/**
 * Les zones mises à jour : k:zone et k:update (ADR-036).
 *
 * Kioo ne fait que traduire ces deux attributs en attributs HTML ordinaires,
 * et refuser ce que le script ne saurait pas faire proprement.
 */
final class KiooZonesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function templates(): iterable
    {
        yield 'une zone devient un attribut data-k-zone' => ['<ul k:zone="liste"><li>a</li></ul>', '<ul data-k-zone="liste"><li>a</li></ul>'];
        yield 'les autres attributs sont gardés' => ['<p class="total" k:zone="compteur">3</p>', '<p class="total" data-k-zone="compteur">3</p>'];
        yield 'un nom avec des tirets et des chiffres' => ['<p k:zone="panier-total-2">x</p>', '<p data-k-zone="panier-total-2">x</p>'];
        yield 'guillemets simples' => ["<p k:zone='liste'>x</p>", '<p data-k-zone="liste">x</p>'];
        yield 'une zone dont le contenu dépend d\'une valeur' => ['<p k:zone="compteur">{notes | length} notes</p>', '<p data-k-zone="compteur">3 notes</p>'];
        yield 'une zone qui entoure une boucle' => ['<ul k:zone="liste"><li k:for="note in notes">{note}</li></ul>', '<ul data-k-zone="liste"><li>Pain</li><li>Lait</li><li>Œufs</li></ul>'];
        yield 'une zone sous condition, vraie' => ['<p k:if="actif" k:zone="message">oui</p>', '<p data-k-zone="message">oui</p>'];
        yield 'une zone sous condition, fausse' => ['<p k:if="inactif" k:zone="message">oui</p>', ''];
        yield 'deux zones de noms différents' => ['<p k:zone="a">1</p><p k:zone="b">2</p>', '<p data-k-zone="a">1</p><p data-k-zone="b">2</p>'];

        yield 'un lien qui met à jour une zone' => ['<a href="/notes?page=2" k:update="liste">Suite</a>', '<a href="/notes?page=2" data-k-update="liste">Suite</a>'];
        yield 'plusieurs zones, séparées par des virgules' => ['<a href="/x" k:update="liste, compteur">x</a>', '<a href="/x" data-k-update="liste compteur">x</a>'];
        yield 'plusieurs zones, séparées par des espaces' => ['<a href="/x" k:update="liste  compteur">x</a>', '<a href="/x" data-k-update="liste compteur">x</a>'];
        yield 'une zone nommée deux fois n\'est écrite qu\'une fois' => ['<a href="/x" k:update="liste, liste">x</a>', '<a href="/x" data-k-update="liste">x</a>'];
        yield 'un formulaire de recherche' => ['<form method="get" action="/notes" k:update="liste"><input name="q"></form>', '<form method="get" action="/notes" data-k-update="liste"><input name="q"></form>'];
        yield 'un lien par tour de boucle' => ['<a k:for="i, note in notes" href="/n/{i}" k:update="liste">{note}</a>', '<a href="/n/0" data-k-update="liste">Pain</a><a href="/n/1" data-k-update="liste">Lait</a><a href="/n/2" data-k-update="liste">Œufs</a>'];
        yield 'une zone qui est aussi un formulaire' => ['<form method="get" action="/x" k:zone="filtre" k:update="filtre, liste"></form>', '<form method="get" action="/x" data-k-update="filtre liste" data-k-zone="filtre"></form>'];
    }

    #[DataProvider('templates')]
    public function testZonesAreWrittenAsPlainDataAttributes(string $template, string $expected): void
    {
        self::assertSame($expected, new Kioo()->renderString($template, self::variables()));
    }

    public function testPostFormKeepsItsProtectionTokenAndItsZones(): void
    {
        $html = new Kioo()->renderString('<form method="post" action="/notes" k:update="liste, compteur"><button>Ajouter</button></form>');

        self::assertStringContainsString('data-k-update="liste compteur"', $html);
        self::assertStringNotContainsString('k:update', $html);
    }

    public function testSameZoneNameCanBeUsedAgainInAnotherTemplate(): void
    {
        $kioo = new Kioo();

        self::assertSame('<p data-k-zone="liste">a</p>', $kioo->renderString('<p k:zone="liste">a</p>'));
        self::assertSame('<p data-k-zone="liste">b</p>', $kioo->renderString('<p k:zone="liste">b</p>'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedTemplates(): iterable
    {
        // Sécurité : un nom de zone s'écrit en dur. Une valeur ne choisit jamais
        // quel morceau de page est remplacé.
        yield 'nom fait d\'une expression' => ['<p k:zone="{nom}">x</p>', 'Le nom de zone'];
        yield 'nom fait d\'une variable' => ['<a href="/x" k:update="{zones}">x</a>', 'Le nom de zone'];
        yield 'nom en majuscules' => ['<p k:zone="Liste">x</p>', 'Le nom de zone « Liste »'];
        yield 'nom avec un espace' => ['<p k:zone="ma liste">x</p>', 'Le nom de zone'];
        yield 'nom avec un guillemet' => ['<p k:zone=\'a"b\'>x</p>', 'Le nom de zone'];
        yield 'nom qui finit par un tiret' => ['<p k:zone="liste-">x</p>', 'Le nom de zone'];
        yield 'nom qui commence par un chiffre' => ['<p k:zone="2liste">x</p>', 'Le nom de zone'];
        yield 'nom vide' => ['<p k:zone="">x</p>', 'Le nom de zone'];
        yield 'nom absent' => ['<p k:zone>x</p>', 'Le nom de zone'];
        yield 'nom trop long' => ['<p k:zone="' . str_repeat('a', 41) . '">x</p>', 'quarante caractères'];
        yield 'retour à la ligne dans le nom' => ["<p k:zone=\"liste\nautre\">x</p>", 'Le nom de zone'];
        yield 'mauvais nom dans k:update' => ['<a href="/x" k:update="liste, Compteur">x</a>', 'Le nom de zone « Compteur »'];

        yield 'deux zones de même nom' => ["<p k:zone=\"liste\">a</p>\n<p k:zone=\"liste\">b</p>", 'déjà déclarée à la ligne 1'];
        yield 'zone sur une boucle' => ['<li k:for="note in notes" k:zone="note">{note}</li>', 'à la fois k:for et k:zone'];
        yield 'zone sur body' => ['<body k:zone="page">x</body>', 'ne peut pas être une zone'];
        yield 'zone sur html' => ['<html k:zone="page"><body>x</body></html>', 'ne peut pas être une zone'];
        yield 'zone sur un script' => ['<script k:zone="code">x()</script>', 'ne peut pas être une zone'];
        yield 'zone sur une balise sans contenu' => ['<img k:zone="photo" src="a.png">', 'ne peut pas être une zone'];
        yield 'zone sur une balise fermée sur elle-même' => ['<div k:zone="vide" />', 'ne peut pas être une zone'];
        yield 'zone sur une balise de Kioo' => ['<k:block name="content" k:zone="liste">x</k:block>', 'ne peut pas être une zone'];
        yield 'zone jamais fermée' => ['<p k:zone="liste">x', 'n\'est jamais fermée'];

        yield 'k:update sur un bouton' => ['<button k:update="liste">x</button>', 'k:update est écrit sur une balise <button>'];
        yield 'k:update sur un div' => ['<div k:update="liste">x</div>', 'formulaire ou un lien'];
        yield 'k:update sans nom' => ['<a href="/x" k:update="">x</a>', 'ne nomme aucune zone'];
        yield 'k:update fait de virgules' => ['<a href="/x" k:update=" , ">x</a>', 'ne nomme aucune zone'];
        yield 'k:update sans valeur' => ['<a href="/x" k:update>x</a>', 'ne nomme aucune zone'];

        yield 'faute de frappe : la bonne écriture est proposée' => ['<p k:zones="liste">x</p>', 'Vouliez-vous écrire « k:zone »'];
    }

    #[DataProvider('refusedTemplates')]
    public function testWhatTheScriptCouldNotHandleIsRefusedWhenTheTemplateIsRead(string $template, string $message): void
    {
        $this->expectException(KiooException::class);
        $this->expectExceptionMessage($message);

        new Kioo()->renderString($template, self::variables());
    }

    public function testValueCanNeverProduceZoneAttribute(): void
    {
        // Sécurité : une valeur affichée est échappée. Elle ne peut ni fermer
        // la balise, ni lui ajouter un attribut de zone.
        $html = new Kioo()->renderString(
            '<p k:zone="liste" title="{titre}">{texte}</p>',
            ['titre' => '" data-k-zone="autre', 'texte' => '<b data-k-zone="autre">x</b>'],
        );

        $document = \Dom\HTMLDocument::createFromString('<!doctype html><body>' . $html . '</body>', LIBXML_NOERROR);

        self::assertCount(1, $document->querySelectorAll('[data-k-zone]'));
        self::assertNull($document->querySelector('[data-k-zone="autre"]'));
    }

    public function testErrorTellsTheLine(): void
    {
        $this->expectException(KiooException::class);
        $this->expectExceptionMessage('ligne 3');

        new Kioo()->renderString("<h1>Notes</h1>\n\n<li k:for=\"note in notes\" k:zone=\"note\">x</li>", self::variables());
    }

    /**
     * @return array<string, mixed>
     */
    private static function variables(): array
    {
        return [
            'actif' => true,
            'inactif' => false,
            'notes' => ['Pain', 'Lait', 'Œufs'],
        ];
    }
}
