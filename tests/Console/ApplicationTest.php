<?php

declare(strict_types=1);

namespace Wazi\Tests\Console;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wazi\Console\Application;
use Wazi\Console\Exception\ConsoleException;
use Wazi\Console\Output;
use Wazi\Tests\Console\Fixtures\GreetCommand;

final class ApplicationTest extends TestCase
{
    /** @var resource */
    private mixed $standard;

    /** @var resource */
    private mixed $errors;

    protected function setUp(): void
    {
        $this->standard = self::memory();
        $this->errors = self::memory();
    }

    // --- Exécuter une commande -------------------------------------------------

    public function testItRunsTheCommandThatWasTyped(): void
    {
        $code = $this->run_(['bonjour', 'Alice', '--fort']);

        self::assertSame(0, $code);
        self::assertSame("BONJOUR ALICE !\n", $this->written($this->standard));
        self::assertSame('', $this->written($this->errors));
    }

    public function testTheExitCodeOfTheCommandIsReturned(): void
    {
        self::assertSame(3, $this->run_(['bonjour', 'personne']));
    }

    // --- Aide ----------------------------------------------------------------------

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function waysToAskForTheList(): iterable
    {
        yield 'rien' => [[]];
        yield 'list' => [['list']];
        yield '--help' => [['--help']];
    }

    /**
     * @param list<string> $typed
     */
    #[DataProvider('waysToAskForTheList')]
    public function testWithoutACommandItListsThem(array $typed): void
    {
        $code = $this->run_($typed);
        $written = $this->written($this->standard);

        self::assertSame(0, $code);
        self::assertStringContainsString('wazi <commande>', $written);
        self::assertStringContainsString("  au-revoir  Salue quelqu'un.\n  bonjour    Salue quelqu'un.\n", $written, 'Les commandes sont classées par nom.');
    }

    public function testHelpExplainsACommand(): void
    {
        $code = $this->run_(['bonjour', '--help']);
        $written = $this->written($this->standard);

        self::assertSame(0, $code);
        self::assertStringContainsString('Utilisation : wazi bonjour <nom> [formule] [--options]', $written);
        self::assertStringContainsString('formule  Le mot d\'accueil (par défaut : Bonjour)', $written);
        self::assertStringContainsString('--fort    Écrire en majuscules', $written);
        self::assertStringContainsString('--fois=…  Combien de fois (par défaut : 1)', $written);
    }

    public function testHelpDoesNotRunTheCommand(): void
    {
        $this->run_(['bonjour', 'Alice', '--help']);

        self::assertStringNotContainsString('Bonjour Alice', $this->written($this->standard));
    }

    // --- Erreurs de celui qui tape ------------------------------------------------------

    public function testAnUnknownCommandIsExplained(): void
    {
        $code = $this->run_(['bonjur', 'Alice']);

        self::assertSame(Application::USAGE_ERROR, $code);
        self::assertStringContainsString('La commande « bonjur » n\'existe pas. Vouliez-vous écrire « bonjour » ?', $this->written($this->errors));
        self::assertSame('', $this->written($this->standard));
    }

    public function testAMisspelledLineIsAUsageError(): void
    {
        $code = $this->run_(['bonjour', 'Alice', '--couleur=rouge']);

        self::assertSame(Application::USAGE_ERROR, $code);
        self::assertStringContainsString('n\'a pas d\'option « --couleur »', $this->written($this->errors));
        self::assertSame('', $this->written($this->standard), 'La commande n\'a pas été exécutée.');
    }

    /**
     * Sécurité : un nom de commande piégé ne peut rien glisser dans le terminal.
     */
    public function testAnUnknownCommandNameIsCleanedBeforeBeingShown(): void
    {
        $this->run_(["\e[2J\e]0;piège\x07"]);

        self::assertDoesNotMatchRegularExpression('/[\x00-\x08\x0B-\x1F\x7F]/', $this->written($this->errors));
    }

    // --- Erreurs de la commande ----------------------------------------------------------

    public function testACommandThatFailsDoesNotCrashTheConsole(): void
    {
        $code = $this->run_(['bonjour', 'panne']);
        $errors = $this->written($this->errors);

        self::assertSame(Application::FAILURE, $code);
        self::assertStringContainsString('Erreur  La commande est tombée en panne.', $errors);
        self::assertStringContainsString('RuntimeException, dans ', $this->written($this->standard));
        self::assertStringContainsString('GreetCommand.php, ligne ', $this->written($this->standard));
    }

    /**
     * Sécurité (ADR-006) : la trace contient les valeurs passées aux fonctions.
     * Elle n'est jamais affichée.
     */
    public function testAFailureNeverShowsATrace(): void
    {
        $this->run_(['bonjour', 'panne', '--fois=4242']);
        $all = $this->written($this->standard) . $this->written($this->errors);

        self::assertStringNotContainsString('#0', $all);
        self::assertStringNotContainsString('4242', $all);
    }

    // --- Sécurité : seulement dans un terminal ----------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function webSapis(): iterable
    {
        yield 'serveur de développement' => ['cli-server'];
        yield 'PHP-FPM' => ['fpm-fcgi'];
        yield 'CGI' => ['cgi-fcgi'];
        yield 'module Apache' => ['apache2handler'];
    }

    /**
     * Sécurité (ADR-026) : appelée par un serveur web, la console donnerait à
     * un visiteur les pouvoirs du développeur. Elle refuse, sans rien exécuter.
     */
    #[DataProvider('webSapis')]
    public function testItRefusesToRunOutsideATerminal(string $sapi): void
    {
        $console = new Application('wazi', $sapi);
        $console->add(new GreetCommand());

        $code = $console->run(['wazi', 'bonjour', 'Alice'], new Output($this->standard, $this->errors, false));

        self::assertSame(Application::USAGE_ERROR, $code);
        self::assertStringContainsString('ne s\'exécute que dans un terminal', $this->written($this->errors));
        self::assertSame('', $this->written($this->standard));
    }

    // --- Déclarer des commandes -----------------------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidCommandNames(): iterable
    {
        yield 'vide' => [''];
        yield 'majuscules' => ['Bonjour'];
        yield 'espace' => ['bon jour'];
        yield 'commence par un tiret : serait pris pour une option' => ['--bonjour'];
        yield 'deux-points en tête' => [':bonjour'];
        yield 'deux-points en fin' => ['make:'];
        yield 'accent' => ['été'];
        yield 'retour à la ligne' => ["bonjour\n"];
    }

    #[DataProvider('invalidCommandNames')]
    public function testACommandNameHasAStrictForm(string $name): void
    {
        $this->expectException(ConsoleException::class);
        $this->expectExceptionMessage('ne convient pas pour une commande');

        new Application()->add(new GreetCommand($name));
    }

    public function testTwoCommandsCannotShareAName(): void
    {
        $console = new Application();
        $console->add(new GreetCommand());

        $this->expectException(ConsoleException::class);
        $this->expectExceptionMessage('déjà déclarée');

        $console->add(new GreetCommand());
    }

    public function testACommandCanBelongToAGroup(): void
    {
        $console = new Application('wazi', 'cli');
        $console->add(new GreetCommand('make:bonjour'));

        self::assertSame(0, $console->run(['wazi', 'make:bonjour', 'Alice'], new Output($this->standard, $this->errors, false)));
    }

    // --- Outils -----------------------------------------------------------------------------------

    /**
     * @param list<string> $typed ce qui est tapé après « wazi »
     */
    private function run_(array $typed): int
    {
        $console = new Application('wazi', 'cli');
        $console->add(new GreetCommand());
        $console->add(new GreetCommand('au-revoir'));

        return $console->run(['wazi', ...$typed], new Output($this->standard, $this->errors, false));
    }

    /**
     * @param resource $stream
     */
    private function written(mixed $stream): string
    {
        rewind($stream);

        return str_replace("\r\n", "\n", (string) stream_get_contents($stream));
    }

    /**
     * @return resource
     */
    private static function memory(): mixed
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);

        return $stream;
    }
}
