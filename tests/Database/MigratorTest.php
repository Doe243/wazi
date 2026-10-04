<?php

declare(strict_types=1);

namespace Wazi\Tests\Database;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wazi\Database\Database;
use Wazi\Database\Exception\DatabaseException;
use Wazi\Database\Migrator;

final class MigratorTest extends TestCase
{
    /** Le dossier des migrations, temporaire, supprimé après chaque test. */
    private string $directory;

    private Database $db;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wazi-migrations-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        $this->db = Database::sqlite(':memory:');
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->directory) ?: [] as $file) {
            if ($file !== '.' && $file !== '..') {
                unlink($this->directory . '/' . $file);
            }
        }

        rmdir($this->directory);
    }

    // --- Appliquer --------------------------------------------------------------

    public function testMigrationsAreAppliedInTheOrderOfTheirNames(): void
    {
        // Écrites dans le désordre : c'est le nom qui fixe l'ordre.
        $this->write('20261012_091500_ajouter_couleur.sql', 'ALTER TABLE notes ADD COLUMN couleur TEXT;');
        $this->write('20261004_153000_creer_notes.sql', "CREATE TABLE notes (id INTEGER PRIMARY KEY, texte TEXT);\nCREATE INDEX notes_texte ON notes (texte);");

        $seen = [];
        $applied = $this->migrator()->migrate(static function (string $name) use (&$seen): void {
            $seen[] = $name;
        });

        self::assertSame(['20261004_153000_creer_notes.sql', '20261012_091500_ajouter_couleur.sql'], $applied);
        self::assertSame($applied, $seen);

        $this->db->insert('notes', ['texte' => 'Pain', 'couleur' => 'miel']);
        self::assertSame('miel', $this->db->selectValue('SELECT couleur FROM notes'));
    }

    public function testAMigrationIsAppliedOnlyOnce(): void
    {
        $this->write('20261004_153000_creer_notes.sql', 'CREATE TABLE notes (id INTEGER PRIMARY KEY);');

        self::assertCount(1, $this->migrator()->migrate());
        self::assertSame([], $this->migrator()->migrate());

        // Une nouvelle migration : elle seule est appliquée.
        $this->write('20261005_100000_creer_auteurs.sql', 'CREATE TABLE auteurs (id INTEGER PRIMARY KEY);');

        self::assertSame(['20261005_100000_creer_auteurs.sql'], $this->migrator()->migrate());
    }

    public function testOtherFilesMayLiveInTheDirectory(): void
    {
        $this->write('LISEZ-MOI.md', 'Les migrations de ce projet.');
        $this->write('20261004_153000_creer_notes.sql', 'CREATE TABLE notes (id INTEGER PRIMARY KEY);');

        self::assertCount(1, $this->migrator()->migrate());
    }

    /**
     * SQLite sait défaire un changement de structure : une migration qui
     * échoue ne laisse rien derrière elle.
     */
    public function testAFailedMigrationLeavesNothingBehind(): void
    {
        $this->write('20261004_153000_creer_notes.sql', 'CREATE TABLE notes (id INTEGER PRIMARY KEY);');
        $this->write('20261005_100000_deux_tables.sql', "CREATE TABLE auteurs (id INTEGER PRIMARY KEY);\nCREATE TABLEAU livres (id INTEGER);\nCREATE TABLE editeurs (id INTEGER);");

        try {
            $this->migrator()->migrate();
            self::fail('La migration aurait dû échouer.');
        } catch (DatabaseException $error) {
            self::assertStringContainsString('« 20261005_100000_deux_tables.sql » a échoué à sa requête n° 2 sur 3', $error->getMessage());
            self::assertStringContainsString('Rien n\'a été gardé', $error->getMessage());
            self::assertStringContainsString('CREATE TABLEAU livres', $error->getMessage());
        }

        // La première migration est faite, la seconde entièrement défaite.
        self::assertSame(['notes', 'wazi_migrations'], $this->tables());
        self::assertSame(
            [Migrator::APPLIED, Migrator::PENDING],
            array_column($this->migrator()->status(), 'state'),
        );

        // Une fois corrigée, elle passe.
        $this->write('20261005_100000_deux_tables.sql', "CREATE TABLE auteurs (id INTEGER PRIMARY KEY);\nCREATE TABLE livres (id INTEGER);");
        self::assertCount(1, $this->migrator()->migrate());
    }

    public function testAMigrationWithoutAnyQueryIsRefused(): void
    {
        $this->write('20261004_153000_creer_notes.sql', "-- À remplir.\n");

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('ne contient aucune requête');

        $this->migrator()->migrate();
    }

    // --- Où en est-on ------------------------------------------------------------------

    public function testStatusTellsWhereEachMigrationStands(): void
    {
        $this->write('20261004_153000_creer_notes.sql', 'CREATE TABLE notes (id INTEGER PRIMARY KEY);');
        $this->write('20261005_100000_creer_auteurs.sql', 'CREATE TABLE auteurs (id INTEGER PRIMARY KEY);');
        $this->migrator()->migrate();

        // Une troisième, pas encore appliquée ; la première modifiée ; la deuxième supprimée.
        $this->write('20261006_100000_creer_livres.sql', 'CREATE TABLE livres (id INTEGER PRIMARY KEY);');
        $this->write('20261004_153000_creer_notes.sql', 'CREATE TABLE notes (id INTEGER PRIMARY KEY, texte TEXT);');
        unlink($this->directory . '/20261005_100000_creer_auteurs.sql');

        $status = $this->migrator()->status();

        self::assertSame(
            [
                '20261004_153000_creer_notes.sql' => Migrator::MODIFIED,
                '20261005_100000_creer_auteurs.sql' => Migrator::MISSING,
                '20261006_100000_creer_livres.sql' => Migrator::PENDING,
            ],
            array_column($status, 'state', 'name'),
        );
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $status[0]['appliedAt']);
        self::assertNull($status[2]['appliedAt']);
    }

    /**
     * Git convertit parfois les fins de ligne d'un ordinateur à l'autre : le
     * SQL n'a pas changé pour autant.
     */
    public function testLineEndingsAloneDoNotMakeAMigrationModified(): void
    {
        $this->write('20261004_153000_creer_notes.sql', "CREATE TABLE notes (\n    id INTEGER PRIMARY KEY\n);\n");
        $this->migrator()->migrate();

        $this->write('20261004_153000_creer_notes.sql', "CREATE TABLE notes (\r\n    id INTEGER PRIMARY KEY\r\n);\r\n");

        self::assertSame([Migrator::APPLIED], array_column($this->migrator()->status(), 'state'));
    }

    public function testWithoutAnyMigrationThereIsNothingToDo(): void
    {
        self::assertSame([], $this->migrator()->migrate());
        self::assertSame([], $this->migrator()->status());
    }

    // --- Ce qui est refusé --------------------------------------------------------------

    public function testAMissingDirectoryIsExplained(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('wazi make:migration');

        new Migrator($this->db, $this->directory . '/absent')->migrate();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badFileNames(): iterable
    {
        yield 'sans date' => ['creer_notes.sql'];
        yield 'date seule' => ['20261004_153000.sql'];
        yield 'majuscules' => ['20261004_153000_Creer_Notes.sql'];
        yield 'espace' => ['20261004_153000_creer notes.sql'];
        yield 'date trop courte' => ['2026104_153000_creer_notes.sql'];
    }

    /**
     * Un fichier .sql mal nommé n'est pas ignoré en silence : on croirait
     * la migration faite.
     */
    #[DataProvider('badFileNames')]
    public function testAMisnamedSqlFileIsNotSilentlySkipped(string $file): void
    {
        $this->write($file, 'CREATE TABLE notes (id INTEGER PRIMARY KEY);');

        try {
            $this->migrator()->migrate();
            self::fail('Le nom aurait dû être refusé.');
        } catch (DatabaseException $error) {
            self::assertStringContainsString('n\'a pas un nom de migration', $error->getMessage());
        }

        self::assertNotContains('notes', $this->tables());
    }

    public function testAHugeFileIsRefused(): void
    {
        $this->write('20261004_153000_creer_notes.sql', 'CREATE TABLE notes (id INTEGER); -- ' . str_repeat('x', 1_100_000));

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('dépasse 1 Mo');

        $this->migrator()->migrate();
    }

    // --- Outils -----------------------------------------------------------------------------

    private function migrator(): Migrator
    {
        return new Migrator($this->db, $this->directory);
    }

    private function write(string $file, string $content): void
    {
        file_put_contents($this->directory . '/' . $file, $content);
    }

    /**
     * @return list<mixed>
     */
    private function tables(): array
    {
        return array_column($this->db->select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite!_%' ESCAPE '!' ORDER BY name"), 'name');
    }
}
