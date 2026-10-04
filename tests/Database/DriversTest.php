<?php

declare(strict_types=1);

namespace Wazi\Tests\Database;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wazi\Database\Database;
use Wazi\Database\Exception\DatabaseException;
use Wazi\Database\Migrator;

/**
 * Le même scénario, joué sur les trois bases : SQLite, MySQL, PostgreSQL.
 *
 * SQLite tourne partout. MySQL et PostgreSQL demandent un serveur : leurs
 * tests ne s'exécutent que si son adresse est donnée par une variable
 * d'environnement (la CI le fait), et sont ignorés sinon.
 *
 *     WAZI_TEST_MYSQL_URL=mysql://root:wazi@127.0.0.1:3306/wazi_test
 *     WAZI_TEST_POSTGRES_URL=postgres://wazi:wazi@127.0.0.1:5432/wazi_test
 *
 * Ces bases de test sont vidées par les tests : ne donnez jamais l'adresse
 * d'une base qui contient quelque chose.
 */
final class DriversTest extends TestCase
{
    private const array TABLES = ['essai_commentaires', 'essai_notes', 'essai_comptes', 'essai_types', 'essai_sans_id', 'essai_a', 'essai_b', Migrator::TABLE];

    /** Un dossier temporaire (base SQLite, migrations), supprimé après chaque test. */
    private string $directory;

    private ?Database $db = null;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wazi-drivers-' . bin2hex(random_bytes(8));
        mkdir($this->directory . '/migrations', 0o777, true);
    }

    protected function tearDown(): void
    {
        if ($this->db !== null && $this->db->driver() !== Database::SQLITE) {
            $this->dropTables($this->db);
        }

        // Sous Windows, un fichier SQLite ne se supprime qu'une fois la connexion fermée.
        $this->db = null;
        gc_collect_cycles();

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

    /**
     * @return iterable<string, array{string}>
     */
    public static function drivers(): iterable
    {
        yield 'SQLite' => [Database::SQLITE];
        yield 'MySQL' => [Database::MYSQL];
        yield 'PostgreSQL' => [Database::POSTGRES];
    }

    // --- Lire et écrire ---------------------------------------------------------

    #[DataProvider('drivers')]
    public function testRowsAreWrittenReadChangedAndRemoved(string $driver): void
    {
        $db = $this->connect($driver);
        $this->createNotes($db);

        self::assertSame(1, $db->insert('essai_notes', ['auteur' => 'alice', 'texte' => 'Pain']));
        self::assertSame(2, $db->insert('essai_notes', ['auteur' => 'bob', 'texte' => 'Lait']));

        self::assertSame(
            [['id' => 1, 'auteur' => 'alice', 'texte' => 'Pain'], ['id' => 2, 'auteur' => 'bob', 'texte' => 'Lait']],
            $db->select('SELECT id, auteur, texte FROM essai_notes ORDER BY id'),
        );
        self::assertSame(['texte' => 'Lait'], $db->selectOne('SELECT texte FROM essai_notes WHERE auteur = :auteur', ['auteur' => 'bob']));
        self::assertNull($db->selectOne('SELECT texte FROM essai_notes WHERE auteur = ?', ['carol']));
        self::assertSame(2, $db->selectValue('SELECT COUNT(*) FROM essai_notes'));

        self::assertSame(1, $db->update('essai_notes', ['texte' => 'Brioche'], ['id' => 1, 'auteur' => 'alice']));
        self::assertSame(0, $db->update('essai_notes', ['texte' => 'Volé'], ['id' => 1, 'auteur' => 'bob']));
        self::assertSame('Brioche', $db->selectValue('SELECT texte FROM essai_notes WHERE id = ?', [1]));

        self::assertSame(1, $db->delete('essai_notes', ['id' => 2]));
        self::assertSame(1, $db->execute('UPDATE essai_notes SET texte = ? WHERE texte IS NOT NULL', ['Fini']));
        self::assertSame(1, $db->selectValue('SELECT COUNT(*) FROM essai_notes'));
    }

    #[DataProvider('drivers')]
    public function testANullConditionMeansIsNull(string $driver): void
    {
        $db = $this->connect($driver);
        $this->createNotes($db);
        $db->insert('essai_notes', ['auteur' => 'alice', 'texte' => null]);
        $db->insert('essai_notes', ['auteur' => 'alice', 'texte' => 'Pain']);

        self::assertSame(1, $db->delete('essai_notes', ['auteur' => 'alice', 'texte' => null]));
        self::assertSame('Pain', $db->selectValue('SELECT texte FROM essai_notes'));
    }

    #[DataProvider('drivers')]
    public function testEachKindOfValueGoesInAndComesBack(string $driver): void
    {
        $db = $this->connect($driver);
        $db->execute(match ($driver) {
            Database::SQLITE => 'CREATE TABLE essai_types (entier INTEGER, vrai BOOLEAN, faux BOOLEAN, decimal REAL, rien TEXT, quand DATETIME, texte TEXT)',
            Database::MYSQL => 'CREATE TABLE essai_types (entier INT, vrai BOOLEAN, faux BOOLEAN, `decimal` DOUBLE, rien TEXT, quand DATETIME, texte TEXT)',
            default => 'CREATE TABLE essai_types (entier INTEGER, vrai BOOLEAN, faux BOOLEAN, decimal DOUBLE PRECISION, rien TEXT, quand TIMESTAMP, texte TEXT)',
        });

        $db->insert('essai_types', [
            'entier' => -12,
            'vrai' => true,
            'faux' => false,
            'decimal' => 1.5,
            'rien' => null,
            'quand' => new \DateTimeImmutable('2026-10-04 15:30:00'),
            // Un émoji prend quatre octets : MySQL ne le garde qu'en utf8mb4.
            'texte' => 'Été 😀 « ok »',
        ]);

        $row = $db->selectOne('SELECT * FROM essai_types');
        self::assertNotNull($row);

        // Un nombre revient comme un nombre, pas comme un texte.
        self::assertSame(-12, $row['entier']);
        self::assertSame(1.5, $row['decimal']);
        self::assertNull($row['rien']);
        self::assertSame('Été 😀 « ok »', $row['texte']);
        self::assertSame('2026-10-04 15:30:00', $row['quand']);
        // Les booléens reviennent tels que la base les garde : 1/0, ou true/false pour PostgreSQL.
        self::assertSame($driver === Database::POSTGRES ? true : 1, $row['vrai']);
        self::assertSame($driver === Database::POSTGRES ? false : 0, $row['faux']);

        // Et ils se retrouvent par une condition.
        self::assertSame(1, $db->selectValue('SELECT COUNT(*) FROM essai_types WHERE vrai = ? AND faux = ? AND entier = ?', [true, false, -12]));
    }

    #[DataProvider('drivers')]
    public function testInsertReturnsZeroWhenTheTableCreatesNoIdentifier(string $driver): void
    {
        $db = $this->connect($driver);
        $db->execute('CREATE TABLE essai_sans_id (code VARCHAR(10) NOT NULL PRIMARY KEY)');

        $id = $db->insert('essai_sans_id', ['code' => 'FR']);

        // SQLite numérote toujours ses lignes ; les deux autres n'ont rien à rendre.
        self::assertSame($driver === Database::SQLITE ? 1 : 0, $id);
        self::assertSame('FR', $db->selectValue('SELECT code FROM essai_sans_id'));
    }

    // --- Sécurité ----------------------------------------------------------------------

    /**
     * Sécurité : LE test, sur chaque base. Une valeur reste une valeur.
     */
    #[DataProvider('drivers')]
    public function testAValueCanNeverBecomeSql(string $driver): void
    {
        $db = $this->connect($driver);
        $this->createNotes($db);

        $attacks = [
            "x'); DROP TABLE essai_notes; --",
            "' OR '1'='1",
            '\\\'; DROP TABLE essai_notes; --',
            '" OR ""="',
            "1; DELETE FROM essai_notes",
            "x' UNION SELECT 1, 'a', 'b' --",
        ];

        foreach ($attacks as $attack) {
            $id = $db->insert('essai_notes', ['auteur' => 'alice', 'texte' => $attack]);

            self::assertSame($attack, $db->selectValue('SELECT texte FROM essai_notes WHERE id = ?', [$id]));
            self::assertSame([], $db->select('SELECT * FROM essai_notes WHERE auteur = ?', [$attack]));
            self::assertSame(0, $db->delete('essai_notes', ['auteur' => $attack]));
        }

        self::assertSame(count($attacks), $db->selectValue('SELECT COUNT(*) FROM essai_notes'));
    }

    #[DataProvider('drivers')]
    public function testSeveralStatementsInOneCallAreRefused(string $driver): void
    {
        $db = $this->connect($driver);
        $this->createNotes($db);
        $db->insert('essai_notes', ['auteur' => 'alice']);

        try {
            $db->execute('DELETE FROM essai_notes WHERE id = 99; DELETE FROM essai_notes');
            self::fail('Deux requêtes auraient dû être refusées.');
        } catch (DatabaseException $error) {
            self::assertStringContainsString('une requête par appel', $error->getMessage());
        }

        self::assertSame(1, $db->selectValue('SELECT COUNT(*) FROM essai_notes'));
    }

    #[DataProvider('drivers')]
    public function testTheWrongNumberOfValuesIsRefused(string $driver): void
    {
        $db = $this->connect($driver);
        $this->createNotes($db);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('une valeur par marqueur');

        $db->select('SELECT * FROM essai_notes WHERE id = ? AND auteur = ?', [1]);
    }

    #[DataProvider('drivers')]
    public function testLikeEscapeKeepsWildcardsLiteral(string $driver): void
    {
        $db = $this->connect($driver);
        $this->createNotes($db);

        foreach (['100% coton', '100 grammes', 'a_b', 'axb', 'fini !'] as $texte) {
            $db->insert('essai_notes', ['auteur' => 'alice', 'texte' => $texte]);
        }

        $search = static fn(string $typed): array => array_column(
            $db->select("SELECT texte FROM essai_notes WHERE texte LIKE ? ESCAPE '!' ORDER BY id", ['%' . Database::likeEscape($typed) . '%']),
            'texte',
        );

        self::assertSame(['100% coton'], $search('100%'));
        self::assertSame(['a_b'], $search('a_b'));
        self::assertSame(['fini !'], $search('!'));
        self::assertSame(['100% coton', '100 grammes'], $search('100'));
    }

    // --- Erreurs --------------------------------------------------------------------------

    #[DataProvider('drivers')]
    public function testAValueThatAlreadyExistsIsRecognised(string $driver): void
    {
        $db = $this->connect($driver);
        $db->execute('CREATE TABLE essai_comptes (email VARCHAR(190) NOT NULL UNIQUE, nom VARCHAR(80) NOT NULL)');
        $db->insert('essai_comptes', ['email' => 'alice@exemple.com', 'nom' => 'Alice']);

        try {
            $db->insert('essai_comptes', ['email' => 'alice@exemple.com', 'nom' => 'Autre']);
            self::fail('Le doublon aurait dû être refusé.');
        } catch (DatabaseException $error) {
            self::assertTrue($error->isDuplicate());
            self::assertSame($driver === Database::POSTGRES ? '23505' : '23000', $error->sqlState);
            self::assertDoesNotMatchRegularExpression('/[\x00-\x1F]/', $error->getMessage());
        }

        // Une autre règle non respectée n'est pas un doublon.
        try {
            $db->insert('essai_comptes', ['email' => 'bob@exemple.com', 'nom' => null]);
            self::fail('La valeur obligatoire aurait dû être exigée.');
        } catch (DatabaseException $error) {
            self::assertFalse($error->isDuplicate());
        }
    }

    #[DataProvider('drivers')]
    public function testAMissingTablePointsToTheMigrations(string $driver): void
    {
        $db = $this->connect($driver);

        try {
            $db->select('SELECT * FROM essai_notes');
            self::fail('La table n\'existe pas.');
        } catch (DatabaseException $error) {
            self::assertStringContainsString('wazi db:migrate', $error->getMessage());
            self::assertFalse($error->isDuplicate());
        }
    }

    #[DataProvider('drivers')]
    public function testLinksBetweenTablesAreChecked(string $driver): void
    {
        $db = $this->connect($driver);
        $this->createNotes($db);
        $db->execute('CREATE TABLE essai_commentaires (note_id INTEGER NOT NULL, FOREIGN KEY (note_id) REFERENCES essai_notes (id))');

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('ne respecte pas une règle de la table');

        $db->insert('essai_commentaires', ['note_id' => 99]);
    }

    // --- Transactions ---------------------------------------------------------------------

    #[DataProvider('drivers')]
    public function testATransactionKeepsEverythingOrNothing(string $driver): void
    {
        $db = $this->connect($driver);
        $this->createNotes($db);

        $id = $db->transaction(static function (Database $db): int {
            $db->insert('essai_notes', ['auteur' => 'alice']);

            return $db->insert('essai_notes', ['auteur' => 'bob']);
        });

        self::assertSame(2, $id);

        try {
            $db->transaction(static function (Database $db): void {
                $db->insert('essai_notes', ['auteur' => 'carol']);
                // Une valeur obligatoire manque : la base refuse.
                $db->insert('essai_notes', ['auteur' => null]);
            });
            self::fail('La transaction aurait dû échouer.');
        } catch (DatabaseException) {
        }

        // Carol n'a pas été gardée, et la base est de nouveau utilisable.
        self::assertSame(2, $db->selectValue('SELECT COUNT(*) FROM essai_notes'));
        self::assertGreaterThan(2, $db->insert('essai_notes', ['auteur' => 'dave']));
    }

    // --- Migrations -------------------------------------------------------------------------

    #[DataProvider('drivers')]
    public function testMigrationsBuildTheDatabaseStepByStep(string $driver): void
    {
        $db = $this->connect($driver);
        $migrator = new Migrator($db, $this->directory . '/migrations');

        file_put_contents(
            $this->directory . '/migrations/20261004_153000_creer_tables.sql',
            "-- Deux tables ; un point-virgule dans ce commentaire ne coupe rien.\n"
            . "CREATE TABLE essai_a (id INTEGER NOT NULL PRIMARY KEY, texte VARCHAR(80) DEFAULT 'a;b');\n\n"
            . "CREATE TABLE essai_b (id INTEGER NOT NULL PRIMARY KEY);\n",
        );
        file_put_contents($this->directory . '/migrations/20261005_100000_ajouter_colonne.sql', 'ALTER TABLE essai_b ADD COLUMN nom VARCHAR(80);');

        self::assertSame(['20261004_153000_creer_tables.sql', '20261005_100000_ajouter_colonne.sql'], $migrator->migrate());
        self::assertSame([], $migrator->migrate());
        self::assertSame([Migrator::APPLIED, Migrator::APPLIED], array_column($migrator->status(), 'state'));

        $db->insert('essai_a', ['id' => 1]);
        $db->insert('essai_b', ['id' => 1, 'nom' => 'x']);
        self::assertSame('a;b', $db->selectValue('SELECT texte FROM essai_a'));
    }

    /**
     * SQLite et PostgreSQL défont une migration qui échoue. MySQL valide
     * chaque changement de structure aussitôt : le message le dit.
     */
    #[DataProvider('drivers')]
    public function testAFailedMigrationSaysWhatIsLeftBehind(string $driver): void
    {
        $db = $this->connect($driver);
        $migrator = new Migrator($db, $this->directory . '/migrations');

        file_put_contents(
            $this->directory . '/migrations/20261004_153000_cassee.sql',
            "CREATE TABLE essai_a (id INTEGER NOT NULL PRIMARY KEY);\nCREATE TABLEAU essai_b (id INTEGER);",
        );

        try {
            $migrator->migrate();
            self::fail('La migration aurait dû échouer.');
        } catch (DatabaseException $error) {
            self::assertStringContainsString('a échoué à sa requête n° 2 sur 2', $error->getMessage());
            self::assertStringContainsString($driver === Database::MYSQL ? 'MySQL valide chaque changement' : 'Rien n\'a été gardé', $error->getMessage());
        }

        self::assertSame([Migrator::PENDING], array_column($migrator->status(), 'state'));

        $exists = true;

        try {
            $db->select('SELECT * FROM essai_a');
        } catch (DatabaseException) {
            $exists = false;
        }

        self::assertSame($driver === Database::MYSQL, $exists);
    }

    // --- Connexion ------------------------------------------------------------------------------

    /**
     * Sécurité : un mot de passe refusé n'apparaît ni dans le message, ni dans la trace.
     */
    #[DataProvider('drivers')]
    public function testAFailedConnectionNeverShowsThePassword(string $driver): void
    {
        if ($driver === Database::SQLITE) {
            self::markTestSkipped('SQLite n\'a pas de mot de passe.');
        }

        $uri = \Uri\Rfc3986\Uri::parse($this->url($driver));
        self::assertNotNull($uri);
        $host = (string) $uri->getHost();
        $port = (int) $uri->getPort();
        $previous = ini_set('zend.exception_ignore_args', '0');

        try {
            $db = $driver === Database::MYSQL
                ? Database::mysql('wazi_test', 'personne', 'mot-de-passe-secret', $host, $port)
                : Database::postgres('wazi_test', 'personne', 'mot-de-passe-secret', $host, $port);
            $db->select('SELECT 1');
            self::fail('La connexion aurait dû être refusée.');
        } catch (DatabaseException $error) {
            self::assertStringContainsString('La connexion à la base de données', $error->getMessage());
            self::assertStringNotContainsString('mot-de-passe-secret', $error->getMessage() . $error->getTraceAsString() . print_r($error->getTrace(), true));
            self::assertNull($error->getPrevious());
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previous);
        }
    }

    // --- Outils -----------------------------------------------------------------------------------

    private function connect(string $driver): Database
    {
        $db = $driver === Database::SQLITE
            ? Database::sqlite($this->directory . '/essai.sqlite')
            : Database::fromUrl($this->url($driver));

        self::assertSame($driver, $db->driver());

        if ($driver !== Database::SQLITE) {
            $this->dropTables($db);
        }

        return $this->db = $db;
    }

    /**
     * L'adresse du serveur de test, ou le test est ignoré.
     */
    private function url(string $driver): string
    {
        $variable = $driver === Database::MYSQL ? 'WAZI_TEST_MYSQL_URL' : 'WAZI_TEST_POSTGRES_URL';
        $url = getenv($variable);

        if (!is_string($url) || $url === '') {
            self::markTestSkipped('Pas de serveur de test : la variable ' . $variable . ' n\'est pas définie.');
        }

        return $url;
    }

    private function dropTables(Database $db): void
    {
        foreach (self::TABLES as $table) {
            $db->execute('DROP TABLE IF EXISTS ' . $table);
        }
    }

    private function createNotes(Database $db): void
    {
        $id = match ($db->driver()) {
            Database::SQLITE => 'id INTEGER PRIMARY KEY AUTOINCREMENT',
            Database::MYSQL => 'id INT AUTO_INCREMENT PRIMARY KEY',
            Database::POSTGRES => 'id SERIAL PRIMARY KEY',
        };

        $db->execute('CREATE TABLE essai_notes (' . $id . ', auteur VARCHAR(80) NOT NULL, texte VARCHAR(190))');
    }
}
