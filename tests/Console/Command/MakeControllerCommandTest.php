<?php

declare(strict_types=1);

namespace Wazi\Tests\Console\Command;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wazi\Console\Application;
use Wazi\Console\Command\MakeControllerCommand;
use Wazi\Console\Output;
use Wazi\Http\ServerRequest;
use Wazi\Kernel\Kernel;

final class MakeControllerCommandTest extends TestCase
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
        mkdir($this->project . DIRECTORY_SEPARATOR . 'public', 0o777, true);
        file_put_contents($this->project . '/composer.json', '{"autoload": {"psr-4": {"App\\\\": "src/"}}}');

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

    // --- Ce qui est créé --------------------------------------------------------

    public function testItCreatesAControllerAndItsView(): void
    {
        $code = $this->make(['Article']);

        self::assertSame(0, $code);
        self::assertFileExists($this->project . '/src/ArticleController.php');
        self::assertFileExists($this->project . '/views/article.kioo');

        $controller = (string) file_get_contents($this->project . '/src/ArticleController.php');

        self::assertStringContainsString('namespace App;', $controller);
        self::assertStringContainsString('final readonly class ArticleController', $controller);
        self::assertStringContainsString("#[Get('/article')]", $controller);
        self::assertStringContainsString("\$this->kioo->page('article', ['titre' => 'Article'])", $controller);
    }

    public function testItTellsWhichLineToAddToAppPhp(): void
    {
        $this->make(['Article']);
        $written = $this->written($this->standard);

        self::assertStringContainsString('OK  Créé : src/ArticleController.php', $written);
        self::assertStringContainsString('OK  Créé : views/article.kioo', $written);
        self::assertStringContainsString('$app->router->addController(\App\ArticleController::class);', $written);
        self::assertStringContainsString('ouvrez /article', $written);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function names(): iterable
    {
        yield 'un mot' => ['Article', 'ArticleController', 'article'];
        yield 'deux mots' => ['BlogPost', 'BlogPostController', 'blog-post'];
        yield 'avec le suffixe' => ['ArticleController', 'ArticleController', 'article'];
        yield 'avec un chiffre' => ['Page2', 'Page2Controller', 'page2'];
        yield 'sigle' => ['ApiClient', 'ApiClientController', 'api-client'];
    }

    #[DataProvider('names')]
    public function testTheAddressAndTheViewAreNamedAfterTheController(string $typed, string $class, string $slug): void
    {
        self::assertSame(0, $this->make([$typed]));
        self::assertFileExists($this->project . '/src/' . $class . '.php');
        self::assertFileExists($this->project . '/views/' . $slug . '.kioo');
        self::assertStringContainsString("#[Get('/" . $slug . "')]", (string) file_get_contents($this->project . '/src/' . $class . '.php'));
    }

    public function testTheNamespaceAndTheDirectoryAreReadFromComposerJson(): void
    {
        file_put_contents($this->project . '/composer.json', '{"autoload": {"psr-4": {"Boutique\\\\Web\\\\": "code/web/"}}}');

        $this->make(['Article']);

        self::assertFileExists($this->project . '/code/web/ArticleController.php');
        self::assertStringContainsString('namespace Boutique\Web;', (string) file_get_contents($this->project . '/code/web/ArticleController.php'));
        self::assertStringContainsString('\Boutique\Web\ArticleController::class', $this->written($this->standard));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableComposerFiles(): iterable
    {
        yield 'pas de psr-4' => ['{"name": "mon/projet"}'];
        yield 'JSON abîmé' => ['{"autoload": '];
        yield 'dossier qui sort du projet' => ['{"autoload": {"psr-4": {"App\\\\": "../ailleurs/"}}}'];
        yield 'dossier absolu' => ['{"autoload": {"psr-4": {"App\\\\": "/etc/"}}}'];
        yield 'espace de noms piégé' => ['{"autoload": {"psr-4": {"App; system(1); //": "src/"}}}'];
        yield 'liste de dossiers' => ['{"autoload": {"psr-4": {"App\\\\": ["src/", "lib/"]}}}'];
    }

    /**
     * Sécurité : ce qui est lu dans composer.json se vérifie. Une valeur
     * inattendue ne sert ni de dossier ni d'espace de noms : on retombe sur App et src.
     */
    #[DataProvider('unusableComposerFiles')]
    public function testAnUnusableComposerFileFallsBackToTheDefaults(string $composer): void
    {
        file_put_contents($this->project . '/composer.json', $composer);

        self::assertSame(0, $this->make(['Article']));
        self::assertFileExists($this->project . '/src/ArticleController.php');
        self::assertStringContainsString('namespace App;', (string) file_get_contents($this->project . '/src/ArticleController.php'));
    }

    // --- Commentaires -------------------------------------------------------------

    public function testTheGeneratedCodeIsCommentedByDefault(): void
    {
        $this->make(['Article']);

        self::assertStringContainsString('// Ce dont le contrôleur a besoin se demande ici.', (string) file_get_contents($this->project . '/src/ArticleController.php'));
        self::assertStringContainsString('<!-- La page de ArticleController', (string) file_get_contents($this->project . '/views/article.kioo'));
    }

    public function testNoCommentsWritesTheSameCodeWithoutExplanations(): void
    {
        $this->make(['Article', '--no-comments']);

        $controller = (string) file_get_contents($this->project . '/src/ArticleController.php');
        $view = (string) file_get_contents($this->project . '/views/article.kioo');

        self::assertStringNotContainsString('//', $controller);
        self::assertStringNotContainsString('/**', $controller);
        self::assertStringNotContainsString('<!--', $view);
        self::assertStringContainsString("#[Get('/article')]", $controller);
        self::assertStringContainsString('<h1>{titre}</h1>', $view);
    }

    // --- Le code créé fonctionne ---------------------------------------------------

    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function variants(): iterable
    {
        yield 'commenté, sans mise en page' => [true, false];
        yield 'sans commentaires, sans mise en page' => [false, false];
        yield 'commenté, dans la mise en page' => [true, true];
        yield 'sans commentaires, dans la mise en page' => [false, true];
    }

    /**
     * Le vrai test : le contrôleur créé est chargé, déclaré, et sa page demandée.
     */
    #[DataProvider('variants')]
    public function testTheGeneratedControllerAnswersItsRoute(bool $withComments, bool $withLayout): void
    {
        // Un nom unique : une classe PHP ne se déclare qu'une fois par exécution des tests.
        $name = 'Essai' . bin2hex(random_bytes(4));
        $slug = strtolower($name);

        if ($withLayout) {
            mkdir($this->project . '/views');
            file_put_contents($this->project . '/views/base.kioo', '<title><k:block name="titre">Site</k:block></title><main><k:block name="content"></k:block></main>');
        }

        self::assertSame(0, $this->make($withComments ? [$name] : [$name, '--no-comments']));

        require $this->project . '/src/' . $name . 'Controller.php';

        $class = 'App\\' . $name . 'Controller';
        self::assertTrue(class_exists($class, false));

        $app = new Kernel(development: true, views: $this->project . '/views');
        $app->router->addController($class);

        $response = $app->handle(new ServerRequest('GET', '/' . $slug));
        $html = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode(), $html);
        self::assertStringContainsString('<h1>' . $name . '</h1>', $html);
        self::assertStringNotContainsString('<!--', $html, 'Les commentaires du template ne sont pas envoyés au visiteur.');

        if ($withLayout) {
            self::assertStringContainsString('<title>' . $name . '</title><main>', $html);
        }
    }

    // --- Sécurité : le nom tapé ---------------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedNames(): iterable
    {
        yield 'remontée de dossier' => ['../../public/Porte'];
        yield 'sous-dossier' => ['Admin/Article'];
        yield 'barre inversée' => ['Admin\\Article'];
        yield 'extension' => ['Article.php'];
        yield 'minuscule en tête' => ['article'];
        yield 'chiffre en tête' => ['2Pages'];
        yield 'espace' => ['Mon Article'];
        yield 'tiret' => ['Mon-Article'];
        yield 'accent' => ['Éditeur'];
        yield 'code PHP' => ['A{}system($_GET[0]);class B'];
        yield 'guillemet' => ["A'B"];
        yield 'octet nul' => ["Article\0"];
        yield 'retour à la ligne' => ["Article\n"];
        yield 'démesuré' => ['A' . str_repeat('a', 200)];
        yield 'le suffixe seul' => ['Controller'];
        yield 'point seul' => ['.'];
    }

    /**
     * Sécurité (ADR-028) : le nom devient un chemin de fichier et un nom de
     * classe. Il n'est accepté que sous la forme d'un nom de classe.
     */
    #[DataProvider('refusedNames')]
    public function testANameThatIsNotAClassNameCreatesNothing(string $name): void
    {
        $code = $this->make(['--', $name]);

        self::assertContains($code, [Application::USAGE_ERROR, Application::FAILURE]);
        self::assertSame(['composer.json', 'public'], $this->entries(), 'Aucun fichier, aucun dossier n\'a été créé.');
        self::assertSame([], glob($this->project . '/public/*') ?: []);
    }

    // --- Sécurité : rien n'est remplacé ----------------------------------------------------

    public function testAnExistingControllerIsNeverReplaced(): void
    {
        mkdir($this->project . '/src');
        file_put_contents($this->project . '/src/ArticleController.php', '<?php // mon travail');

        $code = $this->make(['Article']);

        self::assertSame(Application::FAILURE, $code);
        self::assertSame('<?php // mon travail', file_get_contents($this->project . '/src/ArticleController.php'));
        self::assertFileDoesNotExist($this->project . '/views/article.kioo', 'Ou tout est créé, ou rien.');
        self::assertStringContainsString('existe déjà', $this->written($this->errors));
    }

    public function testAnExistingViewIsNeverReplaced(): void
    {
        mkdir($this->project . '/views');
        file_put_contents($this->project . '/views/article.kioo', '<p>ma page</p>');

        $code = $this->make(['Article']);

        self::assertSame(Application::FAILURE, $code);
        self::assertSame('<p>ma page</p>', file_get_contents($this->project . '/views/article.kioo'));
        self::assertFileDoesNotExist($this->project . '/src/ArticleController.php', 'Ou tout est créé, ou rien.');
    }

    public function testRunningItTwiceCreatesNothingTheSecondTime(): void
    {
        self::assertSame(0, $this->make(['Article']));
        $first = file_get_contents($this->project . '/src/ArticleController.php');

        self::assertSame(Application::FAILURE, $this->make(['Article', '--no-comments']));
        self::assertSame($first, file_get_contents($this->project . '/src/ArticleController.php'));
    }

    // --- Hors d'un projet -------------------------------------------------------------------

    public function testOutsideAProjectItExplainsWhereToRunIt(): void
    {
        unlink($this->project . '/composer.json');

        $code = $this->make(['Article']);

        self::assertSame(Application::FAILURE, $code);
        self::assertStringContainsString('composer.json est introuvable', $this->written($this->errors));
        self::assertSame(['public'], $this->entries());
    }

    public function testTheNameIsRequired(): void
    {
        self::assertSame(Application::USAGE_ERROR, $this->make([]));
        self::assertStringContainsString('Il manque l\'argument « nom »', $this->written($this->errors));
    }

    // --- Outils --------------------------------------------------------------------------------

    /**
     * @param list<string> $typed ce qui est tapé après « wazi make:controller »
     */
    private function make(array $typed): int
    {
        $console = new Application('wazi', 'cli');
        $console->add(new MakeControllerCommand($this->project));

        return $console->run(['wazi', 'make:controller', ...$typed], new Output($this->standard, $this->errors, false));
    }

    /**
     * @return list<string> ce que contient le dossier du projet, au premier niveau
     */
    private function entries(): array
    {
        $entries = array_values(array_diff(scandir($this->project) ?: [], ['.', '..']));
        sort($entries);

        return $entries;
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
