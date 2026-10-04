<?php

declare(strict_types=1);

namespace Wazi\Tests\Console\Command;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wazi\Console\Application;
use Wazi\Console\Command\ServeCommand;
use Wazi\Console\Output;

final class ServeCommandTest extends TestCase
{
    /** Un dossier de projet temporaire, avec son dossier public/. */
    private string $project;

    /** @var list<list<string>> Les programmes que la commande a voulu lancer. */
    private array $launched = [];

    /** @var resource */
    private mixed $standard;

    /** @var resource */
    private mixed $errors;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wazi-' . bin2hex(random_bytes(8));
        mkdir($this->project . DIRECTORY_SEPARATOR . 'public', 0o777, true);
        $this->launched = [];
        $this->standard = self::memory();
        $this->errors = self::memory();
    }

    protected function tearDown(): void
    {
        rmdir($this->project . DIRECTORY_SEPARATOR . 'public');
        rmdir($this->project);
    }

    // --- Ce qui est lancé ---------------------------------------------------------

    public function testItStartsThePhpServerOnThePublicDirectory(): void
    {
        $code = $this->serve([]);

        self::assertSame(0, $code);
        self::assertSame([[PHP_BINARY, '-S', 'localhost:8000', '-t', $this->project . DIRECTORY_SEPARATOR . 'public']], $this->launched);
        self::assertStringContainsString('http://localhost:8000', $this->written($this->standard));
        self::assertStringContainsString('Ctrl+C', $this->written($this->standard));
    }

    public function testThePortCanBeChosen(): void
    {
        $this->serve(['--port=8080']);

        self::assertSame('localhost:8080', $this->launched[0][2]);
    }

    public function testTheExitCodeOfTheServerIsReturned(): void
    {
        self::assertSame(7, $this->serve([], 7));
    }

    // --- Sécurité : visible du seul ordinateur, par défaut ----------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function localHosts(): iterable
    {
        yield 'localhost' => ['localhost'];
        yield '127.0.0.1' => ['127.0.0.1'];
        yield 'majuscules' => ['LOCALHOST'];
    }

    #[DataProvider('localHosts')]
    public function testOnALocalAddressThereIsNoWarning(string $host): void
    {
        $this->serve(['--host=' . $host]);

        self::assertStringNotContainsString('Attention', $this->written($this->standard));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function networkHosts(): iterable
    {
        yield 'toutes les interfaces' => ['0.0.0.0'];
        yield 'adresse du réseau local' => ['192.168.1.20'];
        yield 'nom de machine' => ['mon-ordinateur.local'];
    }

    /**
     * Sécurité (ADR-026) : exposer le serveur de développement au réseau se
     * demande explicitement, et s'accompagne d'un avertissement.
     */
    #[DataProvider('networkHosts')]
    public function testListeningOnTheNetworkIsAllowedButWarned(string $host): void
    {
        $code = $this->serve(['--host=' . $host]);

        self::assertSame(0, $code);
        self::assertSame($host . ':8000', $this->launched[0][2]);
        self::assertStringContainsString('Attention  Avec cette adresse, le site est visible depuis les autres appareils du réseau', $this->written($this->standard));
    }

    // --- Sécurité : rien de ce qui est tapé ne devient une commande ----------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedHosts(): iterable
    {
        yield 'vide' => [''];
        yield 'commande enchaînée' => ['localhost; rm -rf /'];
        yield 'commande enchaînée, sans espace' => ['localhost&&calc'];
        yield 'tube' => ['localhost|calc'];
        yield 'substitution' => ['$(calc)'];
        yield 'accent grave' => ['`calc`'];
        yield 'espace' => ['local host'];
        yield 'option glissée' => ['-t'];
        yield 'port glissé dans l\'adresse' => ['localhost:9000'];
        yield 'chemin' => ['../public'];
        yield 'guillemet' => ['local"host'];
        yield 'retour à la ligne' => ["localhost\n-d auto_prepend_file=piege.php"];
        yield 'tiret final' => ['localhost-'];
        yield 'adresse IPv6' => ['::1'];
    }

    #[DataProvider('refusedHosts')]
    public function testAHostThatIsNotAPlainNameIsRefused(string $host): void
    {
        $code = $this->serve(['--host=' . $host]);

        self::assertSame(Application::USAGE_ERROR, $code);
        self::assertSame([], $this->launched, 'Aucun programme n\'a été lancé.');
        self::assertStringContainsString('--host', $this->written($this->errors));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedPorts(): iterable
    {
        yield 'vide' => [''];
        yield 'zéro' => ['0'];
        yield 'trop grand' => ['65536'];
        yield 'démesuré' => ['99999999999999999999'];
        yield 'négatif' => ['-80'];
        yield 'décimal' => ['80.5'];
        yield 'texte' => ['http'];
        yield 'commande enchaînée' => ['8000; calc'];
        yield 'espace' => ['80 80'];
        yield 'option glissée' => ['8000 -d display_errors=1'];
        yield 'signe plus' => ['+8000'];
        yield 'notation hexadécimale' => ['0x50'];
    }

    #[DataProvider('refusedPorts')]
    public function testAPortThatIsNotANumberInRangeIsRefused(string $port): void
    {
        $code = $this->serve(['--port=' . $port]);

        self::assertSame(Application::USAGE_ERROR, $code);
        self::assertSame([], $this->launched, 'Aucun programme n\'a été lancé.');
        self::assertStringContainsString('entre 1 et 65535', $this->written($this->errors));
    }

    /**
     * Sécurité : ce qui est tapé arrive au programme comme UN argument d'une
     * liste, jamais comme un morceau d'une ligne de commande.
     */
    public function testWhatIsTypedIsAlwaysASingleArgumentOfAList(): void
    {
        $this->serve(['--host=mon-ordinateur.local', '--port=65535']);

        self::assertCount(1, $this->launched);
        self::assertCount(5, $this->launched[0]);
        self::assertSame('mon-ordinateur.local:65535', $this->launched[0][2]);
    }

    // --- Dossier public -------------------------------------------------------------------

    public function testWithoutAPublicDirectoryItExplainsWhereToRunIt(): void
    {
        $console = new Application('wazi', 'cli');
        $console->add(new ServeCommand($this->project . DIRECTORY_SEPARATOR . 'ailleurs', fn(array $command): int => 0));

        $code = $console->run(['wazi', 'serve'], new Output($this->standard, $this->errors, false));

        self::assertSame(Application::FAILURE, $code);
        self::assertStringContainsString('Le dossier public/ est introuvable ici', $this->written($this->errors));
    }

    // --- Outils -------------------------------------------------------------------------------

    /**
     * @param list<string> $typed ce qui est tapé après « wazi serve »
     */
    private function serve(array $typed, int $serverExitCode = 0): int
    {
        $launcher = function (array $command) use ($serverExitCode): int {
            $arguments = [];

            foreach ($command as $argument) {
                if (is_string($argument)) {
                    $arguments[] = $argument;
                }
            }

            $this->launched[] = $arguments;

            return $serverExitCode;
        };

        $console = new Application('wazi', 'cli');
        $console->add(new ServeCommand($this->project, $launcher));

        return $console->run(['wazi', 'serve', ...$typed], new Output($this->standard, $this->errors, false));
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
