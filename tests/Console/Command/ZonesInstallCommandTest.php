<?php

declare(strict_types=1);

namespace Wazi\Tests\Console\Command;

use PHPUnit\Framework\TestCase;
use Wazi\Console\Application;
use Wazi\Console\Command\ZonesInstallCommand;
use Wazi\Console\Output;

final class ZonesInstallCommandTest extends TestCase
{
    /** Le script que Wazi fournit. */
    private const string SCRIPT = __DIR__ . '/../../../resources/wazi.js';

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
        mkdir($this->project . '/public');

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
                $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
        }

        rmdir($this->project);
    }

    public function testItCopiesTheScriptIntoThePublicDirectory(): void
    {
        $code = $this->install();

        self::assertSame(Application::SUCCESS, $code);
        self::assertFileEquals(self::SCRIPT, $this->project . '/public/wazi.js');
        self::assertStringContainsString('Créé : public/wazi.js', $this->written($this->standard));
    }

    public function testItTellsWhichLineToAddToTheLayout(): void
    {
        $this->install();

        $written = $this->written($this->standard);

        self::assertStringContainsString('<script src="/wazi.js" defer></script>', $written);
        self::assertStringContainsString('k:zone="liste"', $written);
        self::assertStringContainsString('k:update="liste"', $written);
    }

    public function testASecondRunSaysTheFileIsUpToDate(): void
    {
        $this->install();
        $code = $this->install();

        self::assertSame(Application::SUCCESS, $code);
        self::assertStringContainsString('déjà à jour', $this->written($this->standard));
    }

    public function testAnOlderScriptOfWaziIsReplaced(): void
    {
        file_put_contents($this->project . '/public/wazi.js', "/*! wazi.js : les zones mises à jour de Wazi. Une version plus ancienne. */\nancien();\n");

        $code = $this->install();

        self::assertSame(Application::SUCCESS, $code);
        self::assertFileEquals(self::SCRIPT, $this->project . '/public/wazi.js');
        self::assertStringContainsString('Mis à jour : public/wazi.js', $this->written($this->standard));
        // Aucun fichier de travail n'est laissé dans le dossier public.
        self::assertSame(['wazi.js'], array_values(array_diff((array) scandir($this->project . '/public'), ['.', '..'])));
    }

    public function testAFileThatIsNotFromWaziIsNeverReplaced(): void
    {
        file_put_contents($this->project . '/public/wazi.js', 'console.log("mon script");');

        $code = $this->install();

        self::assertSame(Application::FAILURE, $code);
        self::assertStringContainsString('ce n\'est pas celui de Wazi', $this->written($this->errors));
        self::assertSame('console.log("mon script");', file_get_contents($this->project . '/public/wazi.js'));
    }

    public function testADirectoryWithThatNameIsLeftAlone(): void
    {
        mkdir($this->project . '/public/wazi.js');

        $code = $this->install();

        self::assertSame(Application::FAILURE, $code);
        self::assertDirectoryExists($this->project . '/public/wazi.js');
    }

    public function testItNeverWritesThroughASymbolicLink(): void
    {
        $elsewhere = $this->project . '/ailleurs.js';
        file_put_contents($elsewhere, "/*! wazi.js : les zones mises à jour de Wazi. */\n");

        if (!@symlink($elsewhere, $this->project . '/public/wazi.js')) {
            self::markTestSkipped('Ce système ne permet pas de créer un lien symbolique (Windows sans droits d\'administration).');
        }

        $code = $this->install();

        self::assertSame(Application::FAILURE, $code);
        self::assertStringContainsString('est un lien', $this->written($this->errors));
        self::assertSame("/*! wazi.js : les zones mises à jour de Wazi. */\n", file_get_contents($elsewhere));
    }

    public function testItRefusesToRunOutsideAProject(): void
    {
        rmdir($this->project . '/public');

        $code = $this->install();

        self::assertSame(Application::FAILURE, $code);
        self::assertStringContainsString('Le dossier public/ est introuvable', $this->written($this->errors));

        mkdir($this->project . '/public');
    }

    public function testAMissingOrForeignSourceIsRefused(): void
    {
        $source = $this->project . '/source.js';
        file_put_contents($source, 'alert(1)');

        $code = $this->install($source);

        self::assertSame(Application::FAILURE, $code);
        self::assertStringContainsString('introuvable dans le dossier du framework', $this->written($this->errors));
        self::assertFileDoesNotExist($this->project . '/public/wazi.js');
    }

    public function testItTakesNoArgument(): void
    {
        $console = new Application('wazi', 'cli');
        $console->add(new ZonesInstallCommand($this->project));

        $code = $console->run(['wazi', 'zones:install', '../ailleurs.js'], new Output($this->standard, $this->errors, false));

        self::assertSame(Application::USAGE_ERROR, $code);
        self::assertFileDoesNotExist($this->project . '/public/wazi.js');
    }

    public function testTheScriptOfWaziStartsWithTheLineThatIdentifiesIt(): void
    {
        $script = (string) file_get_contents(self::SCRIPT);

        self::assertStringStartsWith('/*! wazi.js : les zones mises à jour de Wazi.', $script);
        // Sécurité : le script ne construit jamais de code à partir d'un texte.
        self::assertStringNotContainsString('eval(', $script);
        self::assertStringNotContainsString('innerHTML', $script);
        self::assertStringNotContainsString('document.write', $script);
    }

    /**
     * La démonstration garde une copie du script, pour se lancer sans étape
     * de plus. Elle doit rester celle que Wazi fournit.
     */
    public function testTheCopyKeptByTheDemonstrationIsUpToDate(): void
    {
        self::assertFileEquals(self::SCRIPT, __DIR__ . '/../../../examples/demo/public/wazi.js', 'Lancez « php wazi zones:install » dans examples/demo.');
    }

    private function install(?string $source = null): int
    {
        $console = new Application('wazi', 'cli');
        $console->add(new ZonesInstallCommand($this->project, $source));

        return $console->run(['wazi', 'zones:install'], new Output($this->standard, $this->errors, false));
    }

    private function written(mixed $stream): string
    {
        self::assertIsResource($stream);
        rewind($stream);

        return str_replace("\r\n", "\n", (string) stream_get_contents($stream));
    }
}
