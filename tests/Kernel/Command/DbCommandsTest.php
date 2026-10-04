<?php

declare(strict_types=1);

namespace Wazi\Tests\Kernel\Command;

use PHPUnit\Framework\TestCase;
use Wazi\Console\Application;
use Wazi\Console\Output;
use Wazi\Database\Database;
use Wazi\Database\Migrator;
use Wazi\Kernel\Command\DbMigrateCommand;
use Wazi\Kernel\Command\DbStatusCommand;

/**
 * « wazi db:migrate » et « wazi db:status ».
 */
final class DbCommandsTest extends TestCase
{
    /** Le dossier des migrations, temporaire, supprimé après chaque test. */
    private string $directory;

    private Database $db;

    /** @var resource */
    private mixed $standard;

    /** @var resource */
    private mixed $errors;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wazi-migrations-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        $this->db = Database::sqlite(':memory:');
        $this->openOutput();
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

    public function testMigrateAppliesWhatIsPendingAndSaysSo(): void
    {
        $this->write('20261004_153000_creer_notes.sql', 'CREATE TABLE notes (id INTEGER PRIMARY KEY, texte TEXT);');
        $this->write('20261005_100000_creer_auteurs.sql', 'CREATE TABLE auteurs (id INTEGER PRIMARY KEY);');

        $code = $this->type('db:migrate');

        self::assertSame(0, $code);
        self::assertSame(
            "OK  20261004_153000_creer_notes.sql\nOK  20261005_100000_creer_auteurs.sql\n\n2 migration(s) appliquée(s).\n",
            $this->written($this->standard),
        );
        self::assertSame(1, $this->db->insert('notes', ['texte' => 'Pain']));
    }

    public function testMigrateTwiceDoesNothingMore(): void
    {
        $this->write('20261004_153000_creer_notes.sql', 'CREATE TABLE notes (id INTEGER PRIMARY KEY);');
        $this->type('db:migrate');
        $this->openOutput();

        $code = $this->type('db:migrate');

        self::assertSame(0, $code);
        self::assertSame("La base est à jour : aucune migration en attente.\n", $this->written($this->standard));
    }

    /**
     * L'erreur classique : modifier une migration déjà appliquée, et
     * s'étonner que rien ne change.
     */
    public function testMigrateWarnsAboutAMigrationEditedAfterBeingApplied(): void
    {
        $this->write('20261004_153000_creer_notes.sql', 'CREATE TABLE notes (id INTEGER PRIMARY KEY);');
        $this->type('db:migrate');
        $this->openOutput();
        $this->write('20261004_153000_creer_notes.sql', 'CREATE TABLE notes (id INTEGER PRIMARY KEY, texte TEXT);');

        $code = $this->type('db:migrate');

        self::assertSame(0, $code);
        self::assertStringContainsString('Attention  20261004_153000_creer_notes.sql a été modifiée après avoir été appliquée', $this->written($this->standard));
        self::assertStringContainsString('wazi make:migration', $this->written($this->standard));
    }

    public function testMigrateReportsAFailureAndStops(): void
    {
        $this->write('20261004_153000_creer_notes.sql', 'CREATE TABLE notes (id INTEGER PRIMARY KEY);');
        $this->write('20261005_100000_cassee.sql', 'CREER TABLE auteurs (id INTEGER);');
        $this->write('20261006_100000_creer_livres.sql', 'CREATE TABLE livres (id INTEGER PRIMARY KEY);');

        $code = $this->type('db:migrate');

        self::assertSame(Application::FAILURE, $code);
        // La première est annoncée, la troisième n'est pas tentée.
        self::assertSame("OK  20261004_153000_creer_notes.sql\n", $this->written($this->standard));
        self::assertStringContainsString('Erreur  La migration « 20261005_100000_cassee.sql » a échoué à sa requête n° 1 sur 1', $this->written($this->errors));
        self::assertNull($this->db->selectValue("SELECT name FROM sqlite_master WHERE name = 'livres'"));
    }

    public function testStatusListsEveryMigrationWithItsState(): void
    {
        $this->write('20261004_153000_creer_notes.sql', 'CREATE TABLE notes (id INTEGER PRIMARY KEY);');
        $this->type('db:migrate');
        $this->openOutput();
        $this->write('20261012_091500_ajouter_couleur_aux_notes.sql', 'ALTER TABLE notes ADD COLUMN couleur TEXT;');

        $code = $this->type('db:status');
        $lines = explode("\n", $this->written($this->standard));

        self::assertSame(0, $code);
        self::assertMatchesRegularExpression('/^20261004_153000_creer_notes\.sql {4,}faite {4,}\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $lines[0]);
        self::assertSame('20261012_091500_ajouter_couleur_aux_notes.sql    à faire', $lines[1]);
        self::assertSame('1 migration(s) à faire : wazi db:migrate', $lines[3]);
        // Les états sont alignés.
        self::assertSame(mb_strpos($lines[0], 'faite'), mb_strpos($lines[1], 'à faire'));
    }

    public function testStatusOfAnUpToDateOrEmptyProject(): void
    {
        $this->type('db:status');
        self::assertStringContainsString('Aucune migration pour l\'instant', $this->written($this->standard));

        $this->openOutput();
        $this->write('20261004_153000_creer_notes.sql', 'CREATE TABLE notes (id INTEGER PRIMARY KEY);');
        $this->type('db:migrate');
        $this->openOutput();
        $this->type('db:status');

        self::assertStringContainsString('La base est à jour.', $this->written($this->standard));
    }

    public function testAMissingDirectoryIsReportedNotThrown(): void
    {
        rmdir($this->directory);

        foreach (['db:migrate', 'db:status'] as $command) {
            $this->openOutput();

            self::assertSame(Application::FAILURE, $this->type($command));
            self::assertStringContainsString('Le dossier des migrations', $this->written($this->errors));
        }

        mkdir($this->directory);
    }

    /**
     * Sécurité : ce que contient un nom de fichier ou une réponse de la base
     * est affiché nettoyé, sans séquence qui piloterait le terminal.
     */
    public function testWhatIsDisplayedCarriesNoControlCharacter(): void
    {
        $this->write('20261004_153000_cassee.sql', "CREER \e[2J TABLE x;");

        $this->type('db:migrate');

        self::assertDoesNotMatchRegularExpression('/[\x00-\x09\x0B-\x1F]/', $this->written($this->errors));
    }

    private function type(string $command): int
    {
        $migrator = new Migrator($this->db, $this->directory);
        $console = new Application('wazi', 'cli');
        $console->add(new DbMigrateCommand($migrator));
        $console->add(new DbStatusCommand($migrator));

        return $console->run(['wazi', $command], new Output($this->standard, $this->errors, false));
    }

    private function openOutput(): void
    {
        $standard = fopen('php://memory', 'w+');
        $errors = fopen('php://memory', 'w+');
        self::assertIsResource($standard);
        self::assertIsResource($errors);
        $this->standard = $standard;
        $this->errors = $errors;
    }

    private function write(string $file, string $content): void
    {
        file_put_contents($this->directory . '/' . $file, $content);
    }

    private function written(mixed $stream): string
    {
        self::assertIsResource($stream);
        rewind($stream);

        return str_replace("\r\n", "\n", (string) stream_get_contents($stream));
    }
}
