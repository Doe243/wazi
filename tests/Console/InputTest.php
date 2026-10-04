<?php

declare(strict_types=1);

namespace Wazi\Tests\Console;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wazi\Console\Exception\ConsoleException;
use Wazi\Console\Input;
use Wazi\Tests\Console\Fixtures\GreetCommand;

final class InputTest extends TestCase
{
    // --- Ce qui est accepté --------------------------------------------------

    public function testItReadsArgumentsOptionsAndFlags(): void
    {
        $input = Input::parse(new GreetCommand(), ['Alice', 'Salut', '--fort', '--fois=3']);

        self::assertSame('Alice', $input->argument('nom'));
        self::assertSame('Salut', $input->argument('formule'));
        self::assertTrue($input->flag('fort'));
        self::assertSame('3', $input->option('fois'));
    }

    public function testWhatIsNotWrittenTakesItsDefault(): void
    {
        $input = Input::parse(new GreetCommand(), ['Alice']);

        self::assertSame('Bonjour', $input->argument('formule'));
        self::assertFalse($input->flag('fort'));
        self::assertSame('1', $input->option('fois'));
    }

    public function testOptionsCanBeWrittenAnywhere(): void
    {
        $input = Input::parse(new GreetCommand(), ['--fois=2', 'Alice', '--fort', 'Salut']);

        self::assertSame('Alice', $input->argument('nom'));
        self::assertSame('Salut', $input->argument('formule'));
        self::assertTrue($input->flag('fort'));
    }

    /**
     * @return iterable<string, array{list<string>, string, string}>
     */
    public static function unusualValues(): iterable
    {
        yield 'valeur vide' => [['Alice', '--fois='], 'fois', ''];
        yield 'signe égal dans la valeur' => [['Alice', '--fois=a=b'], 'fois', 'a=b'];
        yield 'espaces dans la valeur' => [['Alice', '--fois=deux fois'], 'fois', 'deux fois'];
        yield 'la dernière écriture l\'emporte' => [['Alice', '--fois=2', '--fois=5'], 'fois', '5'];
    }

    /**
     * @param list<string> $tokens
     */
    #[DataProvider('unusualValues')]
    public function testAnOptionValueIsTakenAsWritten(array $tokens, string $option, string $expected): void
    {
        self::assertSame($expected, Input::parse(new GreetCommand(), $tokens)->option($option));
    }

    public function testAfterADoubleDashEverythingIsAnArgument(): void
    {
        $input = Input::parse(new GreetCommand(), ['--', '--fort', '--fois=9']);

        self::assertSame('--fort', $input->argument('nom'));
        self::assertSame('--fois=9', $input->argument('formule'));
        self::assertFalse($input->flag('fort'));
        self::assertSame('1', $input->option('fois'));
    }

    public function testALoneDashIsAnArgument(): void
    {
        self::assertSame('-', Input::parse(new GreetCommand(), ['-'])->argument('nom'));
    }

    // --- Ce qui est refusé ---------------------------------------------------

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function refusedLines(): iterable
    {
        yield 'argument obligatoire manquant' => [[], 'Il manque l\'argument « nom »'];
        yield 'argument en trop' => [['Alice', 'Salut', 'encore'], 'attend 2 argument(s), et en a reçu 3'];
        yield 'option inconnue' => [['Alice', '--couleur=rouge'], 'n\'a pas d\'option « --couleur »'];
        yield 'option inconnue proche d\'une vraie' => [['Alice', '--foix=2'], 'Vouliez-vous écrire « --fois » ?'];
        yield 'drapeau inconnu' => [['Alice', '--force'], 'Vouliez-vous écrire « --fort » ?'];
        yield 'option courte' => [['Alice', '-f'], 'deux tirets'];
        yield 'un seul tiret' => [['Alice', '-fois=2'], 'deux tirets'];
        yield 'valeur donnée à un drapeau' => [['Alice', '--fort=oui'], 'ne prend pas de valeur'];
        yield 'option à valeur sans valeur' => [['Alice', '--fois'], '--fois=1'];
        yield 'valeur séparée par un espace' => [['Alice', '--fois', '2'], '--fois=1'];
        yield 'option sans nom' => [['Alice', '--=2'], 'n\'a pas d\'option'];
    }

    /**
     * Tout ce que la commande n'a pas déclaré est refusé, avec ce qu'il faut écrire.
     *
     * @param list<string> $tokens
     */
    #[DataProvider('refusedLines')]
    public function testWhatTheCommandDidNotDeclareIsRefused(array $tokens, string $expectedHint): void
    {
        $this->expectException(ConsoleException::class);
        $this->expectExceptionMessage($expectedHint);

        Input::parse(new GreetCommand(), $tokens);
    }

    /**
     * Sécurité : ce qui a été tapé est nettoyé avant d'entrer dans un message.
     */
    public function testAnErrorMessageCannotCarryControlCharacters(): void
    {
        try {
            Input::parse(new GreetCommand(), ['Alice', "--\e[2Jpiège\nFAUSSE LIGNE=1"]);
            self::fail('Une exception était attendue.');
        } catch (ConsoleException $exception) {
            self::assertStringNotContainsString("\e", $exception->getMessage());
            self::assertStringNotContainsString("\n", $exception->getMessage());
        }
    }

    // --- Erreurs de celui qui écrit la commande ----------------------------------

    /**
     * @return iterable<string, array{\Closure(Input): mixed, string}>
     */
    public static function undeclaredReads(): iterable
    {
        yield 'argument' => [static fn(Input $input): string => $input->argument('prenom'), 'arguments()'];
        yield 'option' => [static fn(Input $input): string => $input->option('couleur'), 'options()'];
        yield 'drapeau' => [static fn(Input $input): bool => $input->flag('force'), 'options()'];
        yield 'un drapeau lu comme une option' => [static fn(Input $input): string => $input->option('fort'), 'options()'];
        yield 'une option lue comme un drapeau' => [static fn(Input $input): bool => $input->flag('fois'), 'options()'];
    }

    /**
     * @param \Closure(Input): mixed $read
     */
    #[DataProvider('undeclaredReads')]
    public function testReadingSomethingUndeclaredExplainsWhereToDeclareIt(\Closure $read, string $expectedMethod): void
    {
        $input = Input::parse(new GreetCommand(), ['Alice']);

        $this->expectException(ConsoleException::class);
        $this->expectExceptionMessage($expectedMethod);

        $read($input);
    }
}
