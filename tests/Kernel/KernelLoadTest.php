<?php

declare(strict_types=1);

namespace Wazi\Tests\Kernel;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wazi\Http\ServerRequest;
use Wazi\Kernel\Exception\KernelException;
use Wazi\Kernel\Kernel;

/**
 * Kernel::load() : charger l'application construite par le fichier app.php d'un projet.
 */
final class KernelLoadTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wazi-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);
    }

    public function testItLoadsTheApplicationBuiltByTheFile(): void
    {
        $file = $this->write(<<<'PHP'
            <?php

            use Wazi\Http\Response;
            use Wazi\Kernel\Kernel;

            $app = new Kernel();
            $app->router->get('/', static fn () => new Response(200, [], 'Bonjour'));

            return $app;
            PHP);

        $app = Kernel::load($file);

        self::assertSame('Bonjour', (string) $app->handle(new ServerRequest('GET', '/'))->getBody());
    }

    /**
     * Le site et la console chargent chacun le fichier : chaque chargement
     * construit une application neuve.
     */
    public function testEachLoadBuildsAFreshApplication(): void
    {
        $file = $this->write('<?php return new Wazi\Kernel\Kernel();');

        self::assertNotSame(Kernel::load($file), Kernel::load($file));
    }

    public function testTheVariablesOfTheFileDoNotLeak(): void
    {
        $app = 'valeur du test';
        $file = $this->write('<?php $app = new Wazi\Kernel\Kernel(); return $app;');

        Kernel::load($file);

        self::assertSame('valeur du test', $app, 'La variable $app de app.php n\'a pas remplacé celle d\'ici.');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function filesThatDoNotReturnTheApplication(): iterable
    {
        yield 'return oublié' => ['<?php $app = new Wazi\Kernel\Kernel();', 'int'];
        yield 'autre chose qu\'un noyau' => ['<?php return ["app"];', 'array'];
        yield 'null' => ['<?php return null;', 'null'];
        yield 'le routeur au lieu du noyau' => ['<?php return new Wazi\Kernel\Kernel()->router;', 'Wazi\Routing\Router'];
    }

    #[DataProvider('filesThatDoNotReturnTheApplication')]
    public function testAFileThatDoesNotReturnTheApplicationIsExplained(string $content, string $givenType): void
    {
        $file = $this->write($content);

        try {
            Kernel::load($file);
            self::fail('Une exception était attendue.');
        } catch (KernelException $exception) {
            self::assertStringContainsString('return $app;', $exception->getMessage());
            self::assertStringContainsString('de type ' . $givenType, $exception->getMessage());
        }
    }

    public function testAMissingFileIsExplained(): void
    {
        $this->expectException(KernelException::class);
        $this->expectExceptionMessage('introuvable');

        Kernel::load($this->directory . DIRECTORY_SEPARATOR . 'app.php');
    }

    public function testADirectoryIsNotAFile(): void
    {
        $this->expectException(KernelException::class);

        Kernel::load($this->directory);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function addressesThatAreNotLocalFiles(): iterable
    {
        yield 'http' => ['http://pirate.example/app.php'];
        yield 'données en ligne' => ['data://text/plain;base64,PD9waHAgcGhwaW5mbygpOw=='];
        yield 'php://input' => ['php://input'];
        yield 'archive' => ['phar://archive.phar/app.php'];
        yield 'vide' => [''];
        yield 'octet nul' => ["app.php\0.txt"];
    }

    /**
     * Sécurité : seul un fichier ordinaire peut être chargé comme du code.
     * Une adresse à protocole ferait exécuter un contenu venu d'ailleurs.
     */
    #[DataProvider('addressesThatAreNotLocalFiles')]
    public function testOnlyAPlainLocalFileIsLoaded(string $address): void
    {
        $this->expectException(KernelException::class);

        Kernel::load($address);
    }

    /**
     * Une erreur née dans app.php (un réglage manquant, une route mal écrite)
     * remonte telle quelle, avec son propre message.
     */
    public function testAnErrorInsideTheFileKeepsItsOwnMessage(): void
    {
        $file = $this->write('<?php throw new RuntimeException("Le réglage DATABASE_URL est absent.");');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Le réglage DATABASE_URL est absent.');

        Kernel::load($file);
    }

    private function write(string $content): string
    {
        $file = $this->directory . DIRECTORY_SEPARATOR . 'app-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($file, $content);

        return $file;
    }
}
