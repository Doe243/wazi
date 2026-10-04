<?php

declare(strict_types=1);

namespace Wazi\Tests\Console\Command;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wazi\Console\Application;
use Wazi\Console\Command\MakeMigrationCommand;
use Wazi\Console\Output;
use Wazi\Database\Database;
use Wazi\Database\Migrator;

final class MakeMigrationCommandTest extends TestCase
{
    /** Un dossier de projet temporaire, supprimé après chaque test. */
    private string $project;

    /** @var resource */
    private mixed $standard;

    /** @var resource */
    private mixed $errors;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wazi-' . bin2hex(random_bytes(8));
        mkdir($this->project);
        file_put_contents($this->project . '/composer.json', '{}');

        $standard = fopen('php://memory', 'w+');
        $errors = fopen('php://memory', 'w+');
        self::assertIsResource($standard);
        self::assertIsResource($errors);
        $this->standard = $standard;
        $this->errors = $errors;
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->project, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
        }

        rmdir($this->project);
    }

    public function testItCreatesAFileNamedAfterTheDateAndTheChange(): void
    {
        $code = $this->make(['creer_notes']);

        self::assertSame(0, $code);
        self::assertFileExists($this->project . '/migrations/20261004_153000_creer_notes.sql');
        self::assertStringContainsString('Créé : migrations/20261004_153000_creer_notes.sql', $this->written($this->standard));
        self::assertStringContainsString('wazi db:migrate', $this->written($this->standard));
    }

    /**
     * Le nom créé est un nom que le Migrator accepte, et le fichier, tant
     * qu'il n'est pas rempli, ne contient aucune requête.
     */
    public function testTheCreatedFileIsAValidMigrationWaitingForItsSql(): void
    {
        $this->make(['ajouter_couleur_aux_notes2']);

        $file = '20261004_153000_ajouter_couleur_aux_notes2.sql';
        $content = (string) file_get_contents($this->project . '/migrations/' . $file);

        self::assertMatchesRegularExpression(Migrator::FILE_NAME, $file);
        self::assertStringContainsString('-- Migration : ajouter_couleur_aux_notes2', $content);
        self::assertStringContainsString('AUTOINCREMENT', $content);
        self::assertStringContainsString('SERIAL', $content);

        try {
            new Migrator(Database::sqlite(':memory:'), $this->project . '/migrations')->migrate();
            self::fail('Une migration vide aurait dû être refusée.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('ne contient aucune requête', $error->getMessage());
        }
    }

    public function testCommentsCanBeLeftOut(): void
    {
        $this->make(['creer_notes', '--no-comments']);

        self::assertSame('', file_get_contents($this->project . '/migrations/20261004_153000_creer_notes.sql'));
    }

    public function testAnExistingFileIsNeverReplaced(): void
    {
        $this->make(['creer_notes']);
        file_put_contents($this->project . '/migrations/20261004_153000_creer_notes.sql', 'CREATE TABLE notes (id INTEGER);');

        $code = $this->make(['creer_notes']);

        self::assertSame(Application::FAILURE, $code);
        self::assertStringContainsString('existe déjà', $this->written($this->errors));
        self::assertSame('CREATE TABLE notes (id INTEGER);', file_get_contents($this->project . '/migrations/20261004_153000_creer_notes.sql'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badNames(): iterable
    {
        yield 'dossier parent' => ['../../etc/passwd'];
        yield 'sous-dossier' => ['a/b'];
        yield 'barre inversée' => ['a\\b'];
        yield 'majuscules' => ['CreerNotes'];
        yield 'espace' => ['creer notes'];
        yield 'point' => ['creer.notes'];
        yield 'accent' => ['créer_notes'];
        yield 'tiret' => ['creer-notes'];
        yield 'chiffre en tête' => ['2_notes'];
        yield 'souligné final' => ['creer_'];
        yield 'trop long' => [str_repeat('a', 61)];
        yield 'octet nul' => ["creer\0notes"];
    }

    /**
     * Sécurité : le nom tapé devient un nom de fichier.
     */
    #[DataProvider('badNames')]
    public function testANameThatIsNotAPlainNameIsRefused(string $name): void
    {
        $code = $this->make([$name]);

        self::assertSame(Application::USAGE_ERROR, $code);
        self::assertStringContainsString('Ce nom ne convient pas', $this->written($this->errors));
        self::assertDirectoryDoesNotExist($this->project . '/migrations');
    }

    public function testItMustBeRunFromAProject(): void
    {
        unlink($this->project . '/composer.json');

        $code = $this->make(['creer_notes']);

        self::assertSame(Application::FAILURE, $code);
        self::assertStringContainsString('composer.json est introuvable', $this->written($this->errors));
        self::assertDirectoryDoesNotExist($this->project . '/migrations');
    }

    /**
     * @param list<string> $typed ce qui est tapé après « wazi make:migration »
     */
    private function make(array $typed): int
    {
        $console = new Application('wazi', 'cli');
        $console->add(new MakeMigrationCommand($this->project, new \DateTimeImmutable('2026-10-04 15:30:00')));

        return $console->run(['wazi', 'make:migration', ...$typed], new Output($this->standard, $this->errors, false));
    }

    private function written(mixed $stream): string
    {
        self::assertIsResource($stream);
        rewind($stream);

        return str_replace("\r\n", "\n", (string) stream_get_contents($stream));
    }
}
