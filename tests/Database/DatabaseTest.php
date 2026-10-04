<?php

declare(strict_types=1);

namespace Wazi\Tests\Database;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wazi\Database\Database;
use Wazi\Database\Exception\DatabaseException;
use Wazi\Tests\Database\Fixtures\Color;

/**
 * Le comportement de Database, vérifié sur une base SQLite en mémoire.
 * Ce qui dépend de la sorte de base est dans DriversTest.
 */
final class DatabaseTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        $this->db = Database::sqlite(':memory:');
        $this->db->execute('CREATE TABLE notes (id INTEGER PRIMARY KEY AUTOINCREMENT, auteur TEXT NOT NULL, texte TEXT, vues INTEGER NOT NULL DEFAULT 0)');
    }

    // --- Lire -------------------------------------------------------------------

    public function testSelectReturnsEveryRowAsAnArray(): void
    {
        $this->db->insert('notes', ['auteur' => 'alice', 'texte' => 'Pain']);
        $this->db->insert('notes', ['auteur' => 'bob', 'texte' => 'Lait']);

        self::assertSame(
            [['id' => 1, 'auteur' => 'alice', 'texte' => 'Pain'], ['id' => 2, 'auteur' => 'bob', 'texte' => 'Lait']],
            $this->db->select('SELECT id, auteur, texte FROM notes ORDER BY id'),
        );
        self::assertSame([], $this->db->select('SELECT * FROM notes WHERE auteur = ?', ['carol']));
    }

    public function testSelectOneReturnsTheFirstRowOrNull(): void
    {
        $this->db->insert('notes', ['auteur' => 'alice', 'texte' => 'Pain']);

        self::assertSame(['auteur' => 'alice'], $this->db->selectOne('SELECT auteur FROM notes WHERE id = ?', [1]));
        self::assertNull($this->db->selectOne('SELECT auteur FROM notes WHERE id = ?', [99]));
    }

    public function testSelectValueReturnsASingleValueOrNull(): void
    {
        $this->db->insert('notes', ['auteur' => 'alice']);

        self::assertSame(1, $this->db->selectValue('SELECT COUNT(*) FROM notes'));
        self::assertSame('alice', $this->db->selectValue('SELECT auteur FROM notes WHERE id = 1'));
        self::assertNull($this->db->selectValue('SELECT auteur FROM notes WHERE id = 99'));
    }

    public function testNumbersComeBackAsNumbers(): void
    {
        $row = $this->db->selectOne('SELECT 42 AS entier, 1.5 AS decimal, NULL AS rien, \'x\' AS texte');

        self::assertSame(['entier' => 42, 'decimal' => 1.5, 'rien' => null, 'texte' => 'x'], $row);
    }

    // --- Les valeurs passent à part ----------------------------------------------

    public function testValuesAreGivenByPositionOrByName(): void
    {
        $this->db->insert('notes', ['auteur' => 'alice', 'texte' => 'Pain']);

        self::assertSame(1, $this->db->selectValue('SELECT id FROM notes WHERE auteur = ? AND texte = ?', ['alice', 'Pain']));
        self::assertSame(1, $this->db->selectValue('SELECT id FROM notes WHERE auteur = :auteur AND texte = :texte', ['auteur' => 'alice', 'texte' => 'Pain']));
        self::assertSame(1, $this->db->selectValue('SELECT id FROM notes WHERE auteur = :auteur', [':auteur' => 'alice']));
    }

    /**
     * Sécurité : LE test. Une valeur, quoi qu'elle contienne, reste une valeur.
     */
    public function testAValueCanNeverBecomeSql(): void
    {
        $attack = "x'); DROP TABLE notes; --";

        $id = $this->db->insert('notes', ['auteur' => $attack, 'texte' => "' OR '1'='1"]);

        self::assertSame($attack, $this->db->selectValue('SELECT auteur FROM notes WHERE id = ?', [$id]));
        self::assertSame([], $this->db->select('SELECT * FROM notes WHERE auteur = ?', ["' OR '1'='1"]));
        self::assertSame(1, $this->db->selectValue('SELECT COUNT(*) FROM notes'));
    }

    public function testEachKindOfValueIsStoredAsItIs(): void
    {
        $this->db->execute('CREATE TABLE essais (a, b, c, d, e, f, g)');
        $this->db->insert('essais', [
            'a' => 12,
            'b' => true,
            'c' => 1.5,
            'd' => null,
            'e' => new \DateTimeImmutable('2026-10-04 15:30:00'),
            'f' => Color::Honey,
            'g' => 'é😀',
        ]);

        self::assertSame(
            ['a' => 12, 'b' => 1, 'c' => '1.5', 'd' => null, 'e' => '2026-10-04 15:30:00', 'f' => 'miel', 'g' => 'é😀'],
            $this->db->selectOne('SELECT * FROM essais'),
        );
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function valuesADatabaseCannotKeep(): iterable
    {
        yield 'tableau' => [['a', 'b']];
        yield 'objet' => [new \stdClass()];
        yield 'infini' => [INF];
        yield 'pas un nombre' => [NAN];
        yield 'fonction' => [static fn(): int => 1];
    }

    #[DataProvider('valuesADatabaseCannotKeep')]
    public function testAValueThatCannotBeStoredIsRefusedWithItsTypeOnly(mixed $value): void
    {
        try {
            $this->db->select('SELECT * FROM notes WHERE auteur = ?', [$value]);
            self::fail('La valeur aurait dû être refusée.');
        } catch (DatabaseException $error) {
            self::assertStringContainsString('La valeur n° 1', $error->getMessage());
            self::assertStringContainsString(get_debug_type($value), $error->getMessage());
        }
    }

    public function testMixingPositionsAndNamesIsRefused(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('mélangent deux écritures');

        $this->db->select('SELECT * FROM notes WHERE id = ? AND auteur = :auteur', [1, 'auteur' => 'alice']);
    }

    public function testAnInvalidValueNameIsRefused(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Le nom d\'une valeur');

        $this->db->select('SELECT * FROM notes WHERE auteur = :auteur', ['auteur OR 1=1' => 'alice']);
    }

    public function testTheWrongNumberOfValuesIsExplained(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('une valeur par marqueur');

        $this->db->select('SELECT * FROM notes WHERE id = ? AND auteur = ?', [1]);
    }

    /**
     * SQLite mettrait null à la place d'une valeur manquante, sans rien dire.
     */
    public function testAMisspelledValueNameIsNotSilentlyNull(): void
    {
        $this->db->insert('notes', ['auteur' => 'alice']);

        try {
            $this->db->select('SELECT * FROM notes WHERE auteur = :auteur', ['auter' => 'alice']);
            self::fail('Le nom mal écrit aurait dû être signalé.');
        } catch (DatabaseException $error) {
            self::assertStringContainsString('attend 1 valeur(s)', $error->getMessage());
        }
    }

    public function testMarkersInsideTextsAndCastsAreNotCounted(): void
    {
        self::assertSame('a?b', $this->db->selectValue("SELECT 'a?b'"));
        self::assertSame('12:30', $this->db->selectValue("SELECT '12:30'"));
        self::assertSame('x', $this->db->selectValue("SELECT ? -- et après ?", ['x']));
        self::assertSame('xx', $this->db->selectValue('SELECT :a || :a', ['a' => 'x']));
    }

    // --- Une requête par appel -----------------------------------------------------

    /**
     * Sécurité : SQLite exécuterait la première requête et ignorerait la
     * suite sans rien dire. Ici, c'est refusé avant d'atteindre la base.
     */
    public function testSeveralStatementsInOneCallAreRefused(): void
    {
        $this->db->insert('notes', ['auteur' => 'alice']);

        try {
            $this->db->execute('DELETE FROM notes WHERE id = 99; DELETE FROM notes');
            self::fail('Deux requêtes auraient dû être refusées.');
        } catch (DatabaseException $error) {
            self::assertStringContainsString('une requête par appel', $error->getMessage());
        }

        self::assertSame(1, $this->db->selectValue('SELECT COUNT(*) FROM notes'));
    }

    public function testASemicolonAtTheEndOrInsideATextIsFine(): void
    {
        self::assertSame(1, $this->db->selectValue('SELECT 1;'));
        self::assertSame('a;b', $this->db->selectValue("SELECT 'a;b'"));
        self::assertSame('a;b', $this->db->selectValue('SELECT ?', ['a;b']));
    }

    public function testAnEmptyQueryIsRefused(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('La requête SQL est vide');

        $this->db->execute("  \n ");
    }

    // --- Écrire ----------------------------------------------------------------------

    public function testInsertReturnsTheNewIdentifier(): void
    {
        self::assertSame(1, $this->db->insert('notes', ['auteur' => 'alice']));
        self::assertSame(2, $this->db->insert('notes', ['auteur' => 'bob']));
    }

    public function testExecuteReturnsTheNumberOfChangedRows(): void
    {
        $this->db->insert('notes', ['auteur' => 'alice']);
        $this->db->insert('notes', ['auteur' => 'alice']);
        $this->db->insert('notes', ['auteur' => 'bob']);

        self::assertSame(2, $this->db->execute('UPDATE notes SET vues = vues + 1 WHERE auteur = ?', ['alice']));
        self::assertSame(0, $this->db->execute('DELETE FROM notes WHERE auteur = ?', ['carol']));
    }

    public function testUpdateChangesTheRowsThatMatchEveryCondition(): void
    {
        $this->db->insert('notes', ['auteur' => 'alice', 'texte' => 'Pain']);
        $this->db->insert('notes', ['auteur' => 'bob', 'texte' => 'Pain']);

        self::assertSame(1, $this->db->update('notes', ['texte' => 'Brioche', 'vues' => 3], ['auteur' => 'alice', 'texte' => 'Pain']));
        self::assertSame(
            [['auteur' => 'alice', 'texte' => 'Brioche', 'vues' => 3], ['auteur' => 'bob', 'texte' => 'Pain', 'vues' => 0]],
            $this->db->select('SELECT auteur, texte, vues FROM notes ORDER BY id'),
        );
    }

    public function testDeleteRemovesTheRowsThatMatch(): void
    {
        $this->db->insert('notes', ['auteur' => 'alice']);
        $this->db->insert('notes', ['auteur' => 'bob']);

        self::assertSame(1, $this->db->delete('notes', ['auteur' => 'alice']));
        self::assertSame('bob', $this->db->selectValue('SELECT auteur FROM notes'));
    }

    /**
     * En SQL, « texte = NULL » n'est jamais vrai. Une condition null se
     * traduit par « IS NULL ».
     */
    public function testANullConditionMeansIsNull(): void
    {
        $this->db->insert('notes', ['auteur' => 'alice', 'texte' => null]);
        $this->db->insert('notes', ['auteur' => 'alice', 'texte' => 'Pain']);

        self::assertSame(1, $this->db->update('notes', ['vues' => 5], ['auteur' => 'alice', 'texte' => null]));
        self::assertSame(1, $this->db->delete('notes', ['texte' => null]));
        self::assertSame('Pain', $this->db->selectValue('SELECT texte FROM notes'));
    }

    /**
     * Sécurité : une condition vide toucherait toute la table.
     */
    public function testAnEmptyConditionIsRefused(): void
    {
        $this->db->insert('notes', ['auteur' => 'alice']);

        foreach (['update', 'delete'] as $method) {
            try {
                $method === 'update' ? $this->db->update('notes', ['vues' => 1], []) : $this->db->delete('notes', []);
                self::fail('Une condition vide aurait dû être refusée.');
            } catch (DatabaseException $error) {
                self::assertStringContainsString($method . '() a reçu une condition vide', $error->getMessage());
            }
        }

        self::assertSame(0, $this->db->selectValue('SELECT vues FROM notes'));
    }

    public function testEmptyValuesAreRefused(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('insert() a reçu un tableau de valeurs vide');

        $this->db->insert('notes', []);
    }

    // --- Sécurité : les noms ----------------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function badNames(): iterable
    {
        yield 'requête glissée' => ['notes; DROP TABLE notes'];
        yield 'guillemet' => ['notes"'];
        yield 'espace' => ['notes n'];
        yield 'tiret de commentaire' => ['notes--'];
        yield 'point' => ['main.notes'];
        yield 'accent' => ['idée'];
        yield 'chiffre en tête' => ['1notes'];
        yield 'vide' => [''];
        yield 'trop long' => [str_repeat('a', 64)];
        yield 'retour à la ligne final' => ["notes\n"];
    }

    /**
     * Sécurité : une requête préparée protège les valeurs, pas les noms. Un
     * nom ne passe que s'il a la forme d'un nom, et il n'est pas recopié
     * dans le message.
     */
    #[DataProvider('badNames')]
    public function testATableOrColumnNameMustLookLikeAName(string $name): void
    {
        $calls = [
            fn() => $this->db->insert($name, ['auteur' => 'x']),
            fn() => $this->db->insert('notes', [$name => 'x']),
            fn() => $this->db->update($name, ['auteur' => 'x'], ['id' => 1]),
            fn() => $this->db->update('notes', [$name => 'x'], ['id' => 1]),
            fn() => $this->db->update('notes', ['auteur' => 'x'], [$name => 1]),
            fn() => $this->db->delete($name, ['id' => 1]),
            fn() => $this->db->delete('notes', [$name => 1]),
        ];

        foreach ($calls as $index => $call) {
            try {
                $call();
                self::fail('Le nom aurait dû être refusé (appel n° ' . $index . ').');
            } catch (DatabaseException $error) {
                self::assertStringContainsString('est invalide', $error->getMessage());

                if ($name !== '') {
                    self::assertStringNotContainsString($name, $error->getMessage());
                }
            }
        }

        // La table est toujours là.
        self::assertSame(0, $this->db->selectValue('SELECT COUNT(*) FROM notes'));
    }

    public function testANameThatIsAlsoASqlWordWorks(): void
    {
        $this->db->execute('CREATE TABLE "order" ("select" TEXT, "from" INTEGER)');

        $this->db->insert('order', ['select' => 'a', 'from' => 1]);
        $this->db->update('order', ['select' => 'b'], ['from' => 1]);

        self::assertSame('b', $this->db->selectValue('SELECT "select" FROM "order"'));
        self::assertSame(1, $this->db->delete('order', ['select' => 'b']));
    }

    // --- Transactions ---------------------------------------------------------------

    public function testATransactionKeepsEverythingWhenItSucceeds(): void
    {
        $result = $this->db->transaction(function (Database $db): string {
            $db->insert('notes', ['auteur' => 'alice']);
            $db->insert('notes', ['auteur' => 'bob']);

            return 'fait';
        });

        self::assertSame('fait', $result);
        self::assertSame(2, $this->db->selectValue('SELECT COUNT(*) FROM notes'));
    }

    public function testATransactionKeepsNothingWhenItFails(): void
    {
        try {
            $this->db->transaction(function (Database $db): void {
                $db->insert('notes', ['auteur' => 'alice']);

                throw new \LogicException('Quelque chose a mal tourné.');
            });
        } catch (\LogicException $error) {
            self::assertSame('Quelque chose a mal tourné.', $error->getMessage());
        }

        self::assertSame(0, $this->db->selectValue('SELECT COUNT(*) FROM notes'));

        // La base est de nouveau utilisable, transaction comprise.
        $this->db->transaction(static fn(Database $db): int => $db->insert('notes', ['auteur' => 'bob']));
        self::assertSame(1, $this->db->selectValue('SELECT COUNT(*) FROM notes'));
    }

    public function testAFailedQueryInsideATransactionUndoesIt(): void
    {
        try {
            $this->db->transaction(function (Database $db): void {
                $db->insert('notes', ['auteur' => 'alice']);
                $db->insert('notes', ['auteur' => null]);
            });
            self::fail('La requête aurait dû échouer.');
        } catch (DatabaseException) {
        }

        self::assertSame(0, $this->db->selectValue('SELECT COUNT(*) FROM notes'));
    }

    public function testATransactionInsideATransactionIsRefused(): void
    {
        try {
            $this->db->transaction(function (Database $db): void {
                $db->insert('notes', ['auteur' => 'alice']);
                $db->transaction(static fn(): null => null);
            });
            self::fail('La transaction intérieure aurait dû être refusée.');
        } catch (DatabaseException $error) {
            self::assertStringContainsString('à l\'intérieur d\'une autre transaction', $error->getMessage());
        }

        self::assertSame(0, $this->db->selectValue('SELECT COUNT(*) FROM notes'));
    }

    // --- Erreurs ----------------------------------------------------------------------

    public function testARefusedQueryExplainsWhatToCheck(): void
    {
        try {
            $this->db->select('SELEC * FROM notes');
            self::fail('La requête aurait dû échouer.');
        } catch (DatabaseException $error) {
            self::assertStringContainsString('La base de données a refusé cette requête : SELEC * FROM notes', $error->getMessage());
            self::assertStringContainsString('Relisez le SQL', $error->getMessage());
            self::assertInstanceOf(\PDOException::class, $error->getPrevious());
            self::assertFalse($error->isDuplicate());
        }
    }

    public function testAMissingTablePointsToTheMigrations(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('wazi db:migrate');

        $this->db->select('SELECT * FROM articles');
    }

    public function testAValueThatAlreadyExistsIsRecognised(): void
    {
        $this->db->execute('CREATE TABLE comptes (email TEXT NOT NULL UNIQUE)');
        $this->db->insert('comptes', ['email' => 'alice@exemple.com']);

        try {
            $this->db->insert('comptes', ['email' => 'alice@exemple.com']);
            self::fail('Le doublon aurait dû être refusé.');
        } catch (DatabaseException $error) {
            self::assertTrue($error->isDuplicate());
            self::assertSame('23000', $error->sqlState);
            self::assertStringContainsString('isDuplicate()', $error->getMessage());
            // Sécurité : la requête citée porte des marqueurs, pas la valeur.
            self::assertStringNotContainsString('alice@exemple.com', $error->getMessage());
        }
    }

    public function testAnotherBrokenRuleIsNotADuplicate(): void
    {
        try {
            $this->db->insert('notes', ['auteur' => null]);
            self::fail('La valeur obligatoire aurait dû être exigée.');
        } catch (DatabaseException $error) {
            self::assertFalse($error->isDuplicate());
            self::assertStringContainsString('ne respecte pas une règle de la table', $error->getMessage());
        }
    }

    /**
     * Sécurité : un retour à la ligne dans un message permettrait d'écrire
     * une fausse ligne dans un journal.
     */
    public function testTheQuotedQueryIsCleanedAndShortened(): void
    {
        try {
            $this->db->select("SELEC 1\n[faux] ligne de journal " . str_repeat('x', 500));
            self::fail('La requête aurait dû échouer.');
        } catch (DatabaseException $error) {
            self::assertDoesNotMatchRegularExpression('/[\x00-\x1F]/', $error->getMessage());
            self::assertLessThan(900, mb_strlen($error->getMessage()));
        }
    }

    // --- Réglages imposés ----------------------------------------------------------------

    /**
     * SQLite ignore les clés étrangères tant qu'on ne les active pas.
     */
    public function testSqliteChecksTheLinksBetweenTables(): void
    {
        $this->db->execute('CREATE TABLE commentaires (id INTEGER PRIMARY KEY, note_id INTEGER NOT NULL REFERENCES notes (id))');

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('ne respecte pas une règle de la table');

        $this->db->insert('commentaires', ['note_id' => 99]);
    }

    // --- Chercher un texte -----------------------------------------------------------------

    /**
     * Sécurité : dans un LIKE, « % » et « _ » sont des jokers. Tapés par un
     * visiteur, ils doivent rester des caractères.
     */
    public function testLikeEscapeKeepsWildcardsLiteral(): void
    {
        foreach (['100% coton', '100 grammes', 'a_b', 'axb', 'fini !'] as $texte) {
            $this->db->insert('notes', ['auteur' => 'alice', 'texte' => $texte]);
        }

        $search = fn(string $typed): array => array_column(
            $this->db->select("SELECT texte FROM notes WHERE texte LIKE ? ESCAPE '!' ORDER BY id", ['%' . Database::likeEscape($typed) . '%']),
            'texte',
        );

        self::assertSame(['100% coton'], $search('100%'));
        self::assertSame(['a_b'], $search('a_b'));
        self::assertSame(['fini !'], $search('!'));
        self::assertSame(['100% coton', '100 grammes'], $search('100'));
        self::assertCount(5, $search(''));
    }
}
