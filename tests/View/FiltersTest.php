<?php

declare(strict_types=1);

namespace Wazi\Tests\View;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wazi\View\Exception\KiooException;
use Wazi\View\Filters;

final class FiltersTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<mixed>, mixed}>
     */
    public static function results(): iterable
    {
        yield 'upper' => ['upper', ['été à kinshasa'], 'ÉTÉ À KINSHASA'];
        yield 'upper sur un nombre' => ['upper', [42], '42'];
        yield 'lower' => ['lower', ['ÉTÉ À KINSHASA'], 'été à kinshasa'];
        yield 'capitalize' => ['capitalize', ['été à kinshasa'], 'Été à kinshasa'];
        yield 'capitalize sur un texte vide' => ['capitalize', [''], ''];
        yield 'trim' => ['trim', ["  bonjour \n"], 'bonjour'];
        yield 'length d\'un texte accentué' => ['length', ['été'], 3];
        yield 'length d\'une liste' => ['length', [[1, 2, 3]], 3];
        yield 'length d\'une liste vide' => ['length', [[]], 0];
        yield 'number sans décimale' => ['number', [1234567], "1\u{00A0}234\u{00A0}567"];
        yield 'number arrondi' => ['number', [1234.567, 2], "1\u{00A0}234,57"];
        yield 'number négatif' => ['number', [-0.5, 1], '-0,5'];
        yield 'date, format par défaut' => ['date', [new \DateTimeImmutable('2026-10-03 14:30')], '03/10/2026'];
        yield 'date, format choisi' => ['date', [new \DateTimeImmutable('2026-10-03 14:30'), 'H:i'], '14:30'];
        yield 'join' => ['join', [['Alice', 'Bob']], 'Alice, Bob'];
        yield 'join avec séparateur' => ['join', [[1, 2, 3], ' - '], '1 - 2 - 3'];
        yield 'join d\'une liste vide' => ['join', [[]], ''];
        yield 'first' => ['first', [['a', 'b', 'c']], 'a'];
        yield 'last' => ['last', [['a', 'b', 'c']], 'c'];
        yield 'first d\'un tableau à clés' => ['first', [['x' => 1, 'y' => 2]], 1];
        yield 'first d\'une liste vide' => ['first', [[]], null];
        yield 'last d\'une liste vide' => ['last', [[]], null];
        yield 'json d\'une liste' => ['json', [[1, 'été', true, null]], '[1,"été",true,null]'];
    }

    /**
     * @param list<mixed> $arguments
     */
    #[DataProvider('results')]
    public function testAFilterTransformsItsValue(string $filter, array $arguments, mixed $expected): void
    {
        self::assertSame($expected, Filters::defaults()[$filter](...$arguments));
    }

    /**
     * @return iterable<string, array{string, list<mixed>, string}>
     */
    public static function wrongTypes(): iterable
    {
        yield 'upper sur une liste' => ['upper', [['a']], 'un texte'];
        yield 'lower sur null' => ['lower', [null], 'un texte'];
        yield 'trim sur un booléen' => ['trim', [true], 'un texte'];
        yield 'capitalize sur un objet' => ['capitalize', [new \stdClass()], 'un texte'];
        yield 'length sur un nombre' => ['length', [42], 'un texte ou une liste'];
        yield 'number sur un texte' => ['number', ['12'], 'un nombre'];
        yield 'number avec des décimales en texte' => ['number', [12, '2'], 'décimales'];
        yield 'number avec trop de décimales' => ['number', [12, 50], 'décimales'];
        yield 'number avec des décimales négatives' => ['number', [12, -1], 'décimales'];
        yield 'date sur un texte' => ['date', ['2026-10-03'], 'DateTime'];
        yield 'date sur un nombre' => ['date', [1790000000], 'DateTime'];
        yield 'date avec un format qui n\'est pas un texte' => ['date', [new \DateTimeImmutable(), 3], 'format'];
        yield 'join sur un texte' => ['join', ['abc'], 'une liste'];
        yield 'join avec un séparateur qui n\'est pas un texte' => ['join', [['a'], 1], 'séparateur'];
        yield 'join d\'une liste de listes' => ['join', [[['a']]], 'liste de textes ou de nombres'];
        yield 'first sur un texte' => ['first', ['abc'], 'une liste'];
        yield 'last sur null' => ['last', [null], 'une liste'];
    }

    /**
     * @param list<mixed> $arguments
     */
    #[DataProvider('wrongTypes')]
    public function testAFilterExplainsWhatItExpects(string $filter, array $arguments, string $expectedHint): void
    {
        try {
            Filters::defaults()[$filter](...$arguments);
            self::fail('Une exception était attendue.');
        } catch (KiooException $exception) {
            self::assertStringContainsString($expectedHint, $exception->getMessage());
        }
    }

    /**
     * Sécurité : dans le JSON produit, les caractères qui ont un sens en HTML
     * sont écrits sous forme de codes. Recopié n'importe où dans une page, ce
     * texte ne peut fermer ni une balise ni un attribut.
     */
    public function testJsonNeverContainsCharactersThatMeanSomethingInHtml(): void
    {
        $value = '<a href="x">&\'</a>';

        $json = Filters::json($value);

        self::assertDoesNotMatchRegularExpression('/[<>&\']/', $json);
        self::assertSame(2, substr_count($json, '"'), 'Seuls les deux guillemets qui entourent le texte.');
        self::assertSame($value, json_decode($json, flags: JSON_THROW_ON_ERROR));
    }

    /**
     * Sécurité : la liste des filtres est fermée. Aucun ne porte le nom d'une
     * fonction de PHP capable de lire un fichier ou d'exécuter une commande.
     */
    public function testTheDefaultFiltersAreAShortClosedList(): void
    {
        $names = array_keys(Filters::defaults());

        self::assertSame(['upper', 'lower', 'capitalize', 'trim', 'length', 'number', 'date', 'join', 'first', 'last', 'json'], $names);
    }
}
