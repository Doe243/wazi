<?php

declare(strict_types=1);

namespace Wazi\Tests\Console;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wazi\Console\Output;

final class OutputTest extends TestCase
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

    // --- Où part chaque message ------------------------------------------------

    public function testOrdinaryMessagesGoToTheStandardOutput(): void
    {
        $output = $this->newOutput();

        $output->line('Une ligne.');
        $output->line();
        $output->success('Contrôleur créé.');
        $output->warning('Ce fichier existe déjà.');

        self::assertSame("Une ligne.\n\nOK  Contrôleur créé.\nAttention  Ce fichier existe déjà.\n", $this->written($this->standard));
        self::assertSame('', $this->written($this->errors));
    }

    public function testErrorsGoToTheErrorOutput(): void
    {
        $this->newOutput()->error('Le dossier public/ est introuvable.');

        self::assertSame("Erreur  Le dossier public/ est introuvable.\n", $this->written($this->errors));
        self::assertSame('', $this->written($this->standard));
    }

    public function testATitleIsFollowedByAnEmptyLine(): void
    {
        $this->newOutput()->title('La console de Wazi');

        self::assertSame("La console de Wazi\n\n", $this->written($this->standard));
    }

    public function testDefinitionsAreAligned(): void
    {
        $this->newOutput()->definitions(['serve' => 'Lance le site.', 'make:controller' => 'Crée un contrôleur.', 'été' => 'Accents comptés juste.']);

        self::assertSame(
            "  serve            Lance le site.\n  make:controller  Crée un contrôleur.\n  été              Accents comptés juste.\n",
            $this->written($this->standard),
        );
    }

    // --- Couleur ------------------------------------------------------------------

    public function testColorsComeFromTheOutputMethods(): void
    {
        $output = $this->newOutput(colors: true);

        $output->success('Fait.');
        $output->error('Raté.');

        self::assertSame("\e[32mOK\e[0m  Fait.\n", $this->written($this->standard));
        self::assertSame("\e[31mErreur\e[0m  Raté.\n", $this->written($this->errors));
    }

    /**
     * Dans un fichier ou dans les journaux d'un outil, les séquences de
     * couleur seraient du bruit : une sortie qui n'est pas un terminal n'en reçoit pas.
     */
    public function testAnOutputThatIsNotATerminalGetsNoColor(): void
    {
        new Output($this->standard, $this->errors)->success('Fait.');

        self::assertSame("OK  Fait.\n", $this->written($this->standard));
    }

    // --- Sécurité : ce qui est affiché est nettoyé ------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function terminalAttacks(): iterable
    {
        yield 'effacer l\'écran' => ["avant\e[2Japrès"];
        yield 'changer le titre de la fenêtre' => ["\e]0;titre piégé\x07"];
        yield 'écrire dans le presse-papiers' => ["\e]52;c;cm0gLXJmIH4=\x07"];
        yield 'retour au début de la ligne' => ["Tout va bien\rErreur fatale"];
        yield 'retour arrière' => ["mot de passe\x08\x08\x08\x08\x08"];
        yield 'séquence sur 8 bits (CSI)' => ["avant\xC2\x9B2Japrès"];
        yield 'octet nul' => ["avant\0après"];
        yield 'suppression' => ["avant\x7Faprès"];
    }

    /**
     * Sécurité (ADR-026) : une valeur venue d'ailleurs (une adresse reçue, une
     * ligne d'un journal) ne peut pas piloter le terminal.
     */
    #[DataProvider('terminalAttacks')]
    public function testControlCharactersNeverReachTheTerminal(string $attack): void
    {
        $output = $this->newOutput(colors: true);

        $output->line($attack);
        $output->title($attack);
        $output->success($attack);
        $output->warning($attack);
        $output->definitions([$attack => $attack]);
        $output->error($attack);

        foreach ([$this->written($this->standard), $this->written($this->errors)] as $written) {
            // Les seules séquences permises sont celles de couleur, écrites par Output.
            $withoutOwnColors = preg_replace('/\e\[(?:0|1|31|32|33|36)m/', '', $written);

            self::assertDoesNotMatchRegularExpression('/[\x00-\x08\x0B-\x1F\x7F]/', (string) $withoutOwnColors);
            self::assertStringNotContainsString("\xC2\x9B", $written);
        }
    }

    public function testNewLinesTabsAndAccentsAreKept(): void
    {
        $this->newOutput()->line("Première ligne\n\tdeuxième, indentée : été, ça, « guillemets », 日本");

        self::assertSame("Première ligne\n\tdeuxième, indentée : été, ça, « guillemets », 日本\n", $this->written($this->standard));
    }

    // --- Outils -----------------------------------------------------------------------

    private function newOutput(bool $colors = false): Output
    {
        return new Output($this->standard, $this->errors, $colors);
    }

    /**
     * @param resource $stream
     */
    private function written(mixed $stream): string
    {
        rewind($stream);

        // Sous Windows, une ligne se termine par deux caractères : on compare sans s'en soucier.
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
