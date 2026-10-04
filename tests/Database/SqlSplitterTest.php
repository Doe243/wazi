<?php

declare(strict_types=1);

namespace Wazi\Tests\Database;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wazi\Database\SqlSplitter;

final class SqlSplitterTest extends TestCase
{
    public function testStatementsAreSeparatedBySemicolons(): void
    {
        self::assertSame(
            ['CREATE TABLE a (id INT)', 'CREATE INDEX b ON a (id)'],
            SqlSplitter::split("CREATE TABLE a (id INT);\n\nCREATE INDEX b ON a (id);\n"),
        );
    }

    public function testTheLastStatementNeedsNoSemicolon(): void
    {
        self::assertSame(['SELECT 1', 'SELECT 2'], SqlSplitter::split('SELECT 1; SELECT 2'));
    }

    public function testEmptyStatementsAreDropped(): void
    {
        self::assertSame(['SELECT 1'], SqlSplitter::split(" ;; SELECT 1 ;\n ; "));
        self::assertSame([], SqlSplitter::split(''));
        self::assertSame([], SqlSplitter::split("  \n "));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function semicolonsThatDoNotSeparate(): iterable
    {
        yield 'dans un texte' => ["INSERT INTO a VALUES ('x;y')"];
        yield 'dans un texte avec une apostrophe doublée' => ["INSERT INTO a VALUES ('l''été;fini')"];
        yield 'dans un nom entre guillemets' => ['SELECT "a;b" FROM c'];
        yield 'dans un nom entre accents graves' => ['SELECT `a;b` FROM c'];
        yield 'dans un commentaire de fin de ligne' => ["SELECT 1 -- un; deux\n FROM a"];
        yield 'dans un commentaire en bloc' => ['SELECT 1 /* un; deux */ FROM a'];
        yield 'dans un texte entre dollars' => ['SELECT $$ a;b $$'];
        yield 'dans un texte entre dollars nommés' => ['CREATE FUNCTION f() RETURNS int AS $corps$ SELECT 1; $corps$ LANGUAGE sql'];
    }

    #[DataProvider('semicolonsThatDoNotSeparate')]
    public function testASemicolonInsideSomethingDoesNotSeparate(string $sql): void
    {
        self::assertSame([$sql], SqlSplitter::split($sql));
        self::assertSame([$sql, 'SELECT 2'], SqlSplitter::split($sql . "\n; SELECT 2"));
    }

    /**
     * Un texte jamais fermé court jusqu'à la fin : la base dira l'erreur.
     */
    public function testAnUnclosedTextRunsToTheEnd(): void
    {
        self::assertSame(["SELECT 'a; b; SELECT 2"], SqlSplitter::split("SELECT 'a; b; SELECT 2"));
    }

    public function testBareSqlHasNoTextsNamesOrComments(): void
    {
        $bare = SqlSplitter::bare("SELECT '?', \"a?\", `b?` FROM t -- c?\n WHERE x = ? /* d? */ AND y = :y");

        self::assertSame(1, substr_count($bare, '?'));
        self::assertStringContainsString(':y', $bare);
        self::assertStringNotContainsString('a?', $bare);
    }

    public function testADollarThatOpensNothingIsAnOrdinaryCharacter(): void
    {
        self::assertSame(['SELECT $1', 'SELECT 2'], SqlSplitter::split('SELECT $1; SELECT 2'));
    }

    public function testAMinusOrASlashAloneIsAnOrdinaryCharacter(): void
    {
        self::assertSame(['SELECT 4 - 2 / 1', 'SELECT 2'], SqlSplitter::split('SELECT 4 - 2 / 1; SELECT 2'));
    }

    /**
     * Un fichier qui ne contient que des commentaires ne contient aucune requête.
     */
    public function testCommentsAloneAreNotAStatement(): void
    {
        self::assertSame([], SqlSplitter::split("-- rien\n/* du tout */\n"));
        self::assertSame(["-- avant\nSELECT 1"], SqlSplitter::split("-- avant\nSELECT 1;\n-- après\n"));
    }

    public function testCommentsInsideAStatementAreKept(): void
    {
        self::assertSame(
            ["-- la table des notes\nCREATE TABLE notes (id INT)"],
            SqlSplitter::split("-- la table des notes\nCREATE TABLE notes (id INT);"),
        );
    }

    /**
     * Sécurité : dans MySQL, « \' » écrit une apostrophe sans fermer le texte.
     * Mal lu, le « ; » qui suit couperait la requête au mauvais endroit.
     */
    public function testBackslashEscapesAreUnderstoodForMysqlOnly(): void
    {
        $sql = "INSERT INTO a VALUES ('l\\'été;fini')";

        self::assertSame([$sql], SqlSplitter::split($sql, backslashEscapes: true));
        self::assertCount(2, SqlSplitter::split($sql, backslashEscapes: false));
    }

    public function testALongFileIsSplitQuickly(): void
    {
        $sql = str_repeat("INSERT INTO a VALUES ('x;y', \"z\"); -- suite;\n", 5000);

        self::assertCount(5000, SqlSplitter::split($sql));
    }
}
