<?php

declare(strict_types=1);

namespace Wazi\Tests\Kernel\Command;

use PHPUnit\Framework\TestCase;
use Wazi\Console\Application;
use Wazi\Console\Output;
use Wazi\Http\ServerRequest;
use Wazi\Kernel\Command\ViewsCompileCommand;
use Wazi\Kernel\Kernel;
use Wazi\View\Kioo;

final class ViewsCompileCommandTest extends TestCase
{
    private string $directory;

    /** @var resource */
    private mixed $standard;

    /** @var resource */
    private mixed $errors;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wazi-' . bin2hex(random_bytes(8));
        mkdir($this->directory . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'notes', 0o777, true);

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

    public function testItPreparesEveryTemplateAndSaysWhich(): void
    {
        file_put_contents($this->directory . '/views/accueil.kioo', '<h1>{titre}</h1>');
        file_put_contents($this->directory . '/views/notes/liste.kioo', '<li k:for="n in notes">{n}</li>');
        file_put_contents($this->directory . '/views/lisez-moi.txt', 'pas un template');

        $code = $this->compile($this->kioo());
        $written = $this->written($this->standard);

        self::assertSame(0, $code);
        self::assertStringContainsString("  accueil\n  notes/liste\n", $written);
        self::assertStringContainsString('OK  2 template(s) préparé(s).', $written);
        self::assertStringContainsString('ne doit pas être inscriptible par le serveur web', $written);
        self::assertCount(2, glob($this->directory . '/build/views/*.php') ?: []);
    }

    public function testItSaysHowManyStaleFilesWereRemoved(): void
    {
        file_put_contents($this->directory . '/views/accueil.kioo', '<h1>a</h1>');
        file_put_contents($this->directory . '/views/ancienne.kioo', '<h1>b</h1>');
        $this->compile($this->kioo());

        unlink($this->directory . '/views/ancienne.kioo');
        $this->compile($this->kioo());

        self::assertStringContainsString('1 fichier(s) périmé(s) supprimé(s)', $this->written($this->standard));
    }

    /**
     * Une faute dans un template arrête la commande, avec un code d'échec :
     * un script de déploiement s'arrête donc avant de mettre le site en ligne.
     */
    public function testABrokenTemplateFailsTheCommand(): void
    {
        file_put_contents($this->directory . '/views/accueil.kioo', "<h1>a</h1>\n<p>{titre | }</p>");

        $code = $this->compile($this->kioo());

        self::assertSame(Application::FAILURE, $code);
        self::assertStringContainsString('« accueil », ligne 2', $this->written($this->errors));
        self::assertStringNotContainsString('préparé(s)', $this->written($this->standard));
    }

    public function testWithoutADirectoryItExplainsWhatToAddToAppPhp(): void
    {
        file_put_contents($this->directory . '/views/accueil.kioo', '<h1>a</h1>');

        $code = $this->compile(new Kioo($this->directory . '/views'));

        self::assertSame(Application::FAILURE, $code);
        self::assertStringContainsString('compiledViews:', $this->written($this->errors));
    }

    /**
     * De bout en bout : le noyau reçoit le dossier, la commande prépare, et
     * une page servie ensuite vient du fichier préparé.
     */
    public function testTheKernelServesPagesFromPreparedTemplates(): void
    {
        file_put_contents($this->directory . '/views/accueil.kioo', '<p>fixe</p><h1>{titre}</h1>');

        $app = fn(): Kernel => new Kernel(
            views: $this->directory . '/views',
            compiledViews: $this->directory . '/build/views',
            unsafeAllowWritableCompiledViews: true,
        );

        $first = $app();
        self::assertSame(0, $this->compile($first->container->get(Kioo::class)));

        // On marque le fichier préparé, pour prouver que c'est lui qui sert.
        $file = (glob($this->directory . '/build/views/*.php') ?: [''])[0];
        file_put_contents($file, str_replace("'<p>fixe</p>'", "'<p>préparé</p>'", (string) file_get_contents($file)));

        $second = $app();
        $second->router->get('/', static fn() => $second->container->get(Kioo::class)->page('accueil', ['titre' => 'Notes']));

        self::assertSame('<p>préparé</p><h1>Notes</h1>', (string) $second->handle(new ServerRequest('GET', '/'))->getBody());
    }

    private function kioo(): Kioo
    {
        return new Kioo(
            $this->directory . '/views',
            compiledDirectory: $this->directory . '/build/views',
            unsafeAllowWritableCompiledDirectory: true,
        );
    }

    private function compile(Kioo $kioo): int
    {
        $console = new Application('wazi', 'cli');
        $console->add(new ViewsCompileCommand($kioo));

        return $console->run(['wazi', 'views:compile'], new Output($this->standard, $this->errors, false));
    }

    /**
     * @param resource $stream
     */
    private function written(mixed $stream): string
    {
        rewind($stream);

        return str_replace("\r\n", "\n", (string) stream_get_contents($stream));
    }
}
