<?php

declare(strict_types=1);

namespace Wazi\Tests\Console\Command;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wazi\Console\Application;
use Wazi\Console\Command\MakePwaCommand;
use Wazi\Console\Output;

final class MakePwaCommandTest extends TestCase
{
    private const array FILES = ['manifest.webmanifest', 'service-worker.js', 'pwa.js', 'hors-ligne.html', 'icone.svg'];

    /** Un dossier de projet temporaire, supprimé après chaque test. */
    private string $project;

    /** @var resource */
    private mixed $standard;

    /** @var resource */
    private mixed $errors;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wazi-' . bin2hex(random_bytes(8)) . DIRECTORY_SEPARATOR . 'carnet';
        mkdir($this->project . '/public', 0o777, true);

        $standard = fopen('php://memory', 'w+');
        $errors = fopen('php://memory', 'w+');
        self::assertIsResource($standard);
        self::assertIsResource($errors);
        $this->standard = $standard;
        $this->errors = $errors;
    }

    protected function tearDown(): void
    {
        $root = dirname($this->project);
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo) {
                $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
        }

        rmdir($root);
    }

    public function testItCreatesTheFiveFilesOfAnInstallableApplication(): void
    {
        $code = $this->make(['Mon carnet']);

        self::assertSame(Application::SUCCESS, $code);

        foreach (self::FILES as $file) {
            self::assertFileExists($this->project . '/public/' . $file);
            self::assertStringContainsString('Créé : public/' . $file, $this->written($this->standard));
        }
    }

    public function testItTellsWhichLinesToAddToTheLayout(): void
    {
        $this->make(['Mon carnet']);

        $written = $this->written($this->standard);

        self::assertStringContainsString('<link rel="manifest" href="/manifest.webmanifest">', $written);
        self::assertStringContainsString('<meta name="theme-color" content="#0B6E70">', $written);
        self::assertStringContainsString('<script src="/pwa.js" defer></script>', $written);
    }

    public function testTheManifestDescribesTheApplication(): void
    {
        $this->make(['Mon carnet de notes personnel']);

        $manifest = json_decode($this->file('manifest.webmanifest'), true, 8, JSON_THROW_ON_ERROR);

        self::assertIsArray($manifest);
        self::assertSame('Mon carnet de notes personnel', $manifest['name']);
        self::assertSame('Mon carnet d', $manifest['short_name']);
        self::assertSame('/', $manifest['start_url']);
        self::assertSame('/', $manifest['scope']);
        self::assertSame('standalone', $manifest['display']);
        self::assertSame([['src' => '/icone.svg', 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any']], $manifest['icons']);
    }

    public function testWithoutANameTheDirectoryNameIsUsed(): void
    {
        $this->make([]);

        $manifest = json_decode($this->file('manifest.webmanifest'), true, 8, JSON_THROW_ON_ERROR);

        self::assertIsArray($manifest);
        self::assertSame('carnet', $manifest['name']);
    }

    /**
     * Sécurité : le service worker ne garde aucune page, ne touche qu'aux
     * lectures du site, et ne charge aucun script venu d'ailleurs.
     */
    public function testTheServiceWorkerIsCautious(): void
    {
        $this->make(['Mon carnet']);

        $worker = $this->file('service-worker.js');

        // Une page : le réseau, sinon la page hors ligne. Jamais de mise en réserve.
        self::assertStringContainsString("if (request.mode === 'navigate') {\n        event.respondWith(fetch(request).catch(() => caches.match(HORS_LIGNE)));", $worker);
        // Ni les envois, ni les autres sites.
        self::assertStringContainsString("if (request.method !== 'GET' || url.origin !== self.location.origin) {\n        return;", $worker);
        // Seuls les fichiers d'une liste écrite en dur sont gardés.
        self::assertStringContainsString("const FICHIERS = [HORS_LIGNE, '/icone.svg'];", $worker);
        self::assertSame(1, substr_count($worker, 'reserve.put('));
        self::assertStringContainsString('if (FICHIERS.includes(url.pathname)) {', $worker);
        // Aucun script importé, aucun code fabriqué à partir d'un texte.
        self::assertStringNotContainsString('importScripts', $worker);
        self::assertStringNotContainsString('eval(', $worker);
        self::assertStringNotContainsString('http', $worker);
    }

    public function testTheNameIsNeverWrittenIntoAScript(): void
    {
        $this->make(['Carnet-de-test']);

        self::assertStringNotContainsString('Carnet-de-test', $this->file('service-worker.js'));
        self::assertStringNotContainsString('Carnet-de-test', $this->file('pwa.js'));
    }

    /**
     * Sécurité : un nom piégé reste un texte, dans chacun des fichiers où il est écrit.
     */
    public function testAHostileNameCannotInjectAnything(): void
    {
        $name = '"></title><script>alert(1)</script><svg onload=x> \' & é';

        $code = $this->make([$name]);

        self::assertSame(Application::SUCCESS, $code);

        // Le manifeste reste du JSON, et rend le nom tel quel.
        $manifest = json_decode($this->file('manifest.webmanifest'), true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        self::assertSame($name, $manifest['name']);

        // La page hors ligne, relue comme par un navigateur : aucun script, et le nom en texte.
        $page = \Dom\HTMLDocument::createFromString($this->file('hors-ligne.html'), LIBXML_NOERROR);
        self::assertCount(0, $page->querySelectorAll('script, svg, [onload]'));
        self::assertSame('Hors ligne — ' . $name, $page->title);

        // L'icône reste un dessin bien formé, d'une seule lettre.
        $icon = \Dom\XMLDocument::createFromString($this->file('icone.svg'));
        self::assertSame('"', $icon->getElementsByTagName('text')->item(0)?->textContent);
        self::assertCount(0, $icon->getElementsByTagName('script'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedNames(): iterable
    {
        yield 'trop long' => [str_repeat('a', 61)];
        yield 'retour à la ligne' => ["Mon\ncarnet"];
        yield 'caractère de contrôle' => ["Mon\x1b[2Jcarnet"];
        yield 'octet nul' => ["Mon\0carnet"];
        yield 'texte mal encodé' => ["Mon carnet \xC3\x28"];
    }

    #[DataProvider('refusedNames')]
    public function testANameThatDoesNotFitIsRefusedAndNothingIsCreated(string $name): void
    {
        $code = $this->make([$name]);

        self::assertSame(Application::USAGE_ERROR, $code);
        self::assertStringContainsString('Ce nom ne convient pas', $this->written($this->errors));
        self::assertSame([], $this->created());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function files(): iterable
    {
        foreach (self::FILES as $file) {
            yield $file => [$file];
        }
    }

    #[DataProvider('files')]
    public function testAnExistingFileIsNeverReplacedAndNothingElseIsCreated(string $existing): void
    {
        file_put_contents($this->project . '/public/' . $existing, 'le mien');

        $code = $this->make(['Mon carnet']);

        self::assertSame(Application::FAILURE, $code);
        self::assertStringContainsString('public/' . $existing . ' existe déjà', $this->written($this->errors));
        self::assertSame('le mien', $this->file($existing));
        self::assertSame([$existing], $this->created());
    }

    public function testASecondRunCreatesNothing(): void
    {
        $this->make(['Mon carnet']);
        $before = $this->file('service-worker.js');

        $code = $this->make(['Autre nom']);

        self::assertSame(Application::FAILURE, $code);
        self::assertSame($before, $this->file('service-worker.js'));
        self::assertStringContainsString('Mon carnet', $this->file('manifest.webmanifest'));
    }

    public function testCommentsCanBeLeftOut(): void
    {
        $this->make(['Mon carnet', '--no-comments']);

        $worker = $this->file('service-worker.js');

        self::assertStringNotContainsString('//', $worker);
        self::assertStringStartsWith("const VERSION = 'v1';", $worker);
        self::assertStringNotContainsString("\n\n\n", $worker);
        self::assertStringContainsString("if (request.mode === 'navigate') {", $worker);
        self::assertStringStartsWith("if ('serviceWorker' in navigator) {", $this->file('pwa.js'));
    }

    public function testItRefusesToRunOutsideAProject(): void
    {
        rmdir($this->project . '/public');

        $code = $this->make(['Mon carnet']);

        self::assertSame(Application::FAILURE, $code);
        self::assertStringContainsString('Le dossier public/ est introuvable', $this->written($this->errors));

        mkdir($this->project . '/public');
    }

    public function testItNeverWritesThroughASymbolicLink(): void
    {
        $elsewhere = $this->project . '/ailleurs.js';

        // Un lien vers un fichier qui n'existe pas encore : file_exists() répond « non ».
        if (!@symlink($elsewhere, $this->project . '/public/service-worker.js')) {
            self::markTestSkipped('Ce système ne permet pas de créer un lien symbolique (Windows sans droits d\'administration).');
        }

        $code = $this->make(['Mon carnet']);

        self::assertSame(Application::FAILURE, $code);
        self::assertFileDoesNotExist($elsewhere);
    }

    public function testTheHelpShowsThatTheNameIsOptional(): void
    {
        $this->make(['--help']);

        $written = $this->written($this->standard);

        self::assertStringContainsString('wazi make:pwa [nom] [--options]', $written);
        // Le défaut est vide : l'aide ne montre pas de « (par défaut : ) » sans rien derrière.
        self::assertStringNotContainsString('(par défaut : )', $written);
        self::assertSame([], $this->created());
    }

    /**
     * @param list<string> $typed
     */
    private function make(array $typed): int
    {
        $console = new Application('wazi', 'cli');
        $console->add(new MakePwaCommand($this->project));

        return $console->run(['wazi', 'make:pwa', ...$typed], new Output($this->standard, $this->errors, false));
    }

    private function file(string $name): string
    {
        return (string) file_get_contents($this->project . '/public/' . $name);
    }

    /**
     * @return list<string> les fichiers présents dans public/
     */
    private function created(): array
    {
        $entries = scandir($this->project . '/public');

        return $entries === false ? [] : array_values(array_diff($entries, ['.', '..']));
    }

    private function written(mixed $stream): string
    {
        self::assertIsResource($stream);
        rewind($stream);

        return str_replace("\r\n", "\n", (string) stream_get_contents($stream));
    }
}
