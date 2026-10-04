<?php

declare(strict_types=1);

namespace Wazi\Tests\Kernel\Command;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Wazi\Console\Application;
use Wazi\Console\Output;
use Wazi\Http\Response;
use Wazi\Kernel\Command\RoutesCommand;
use Wazi\Kernel\Kernel;
use Wazi\Middleware\WithoutCsrf;
use Wazi\Routing\Router;
use Wazi\Tests\Routing\Fixtures\NoteController;

final class RoutesCommandTest extends TestCase
{
    /** @var resource */
    private mixed $standard;

    /** @var resource */
    private mixed $errors;

    protected function setUp(): void
    {
        $standard = fopen('php://memory', 'w+');
        $errors = fopen('php://memory', 'w+');
        self::assertIsResource($standard);
        self::assertIsResource($errors);

        $this->standard = $standard;
        $this->errors = $errors;
    }

    public function testItListsTheRoutesInTheOrderTheRouterTriesThem(): void
    {
        $router = new Router();
        $router->get('/', static fn(): ResponseInterface => new Response());
        $router->addController(NoteController::class);
        $router->post('/webhooks/paiement', static fn(): ResponseInterface => new Response(), [WithoutCsrf::class, new WithoutCsrf()]);
        $router->add(['GET', 'POST'], '/contact', static fn(): ResponseInterface => new Response());

        $code = $this->console($router)->run(['wazi', 'routes'], $this->newOutput());
        $lines = explode("\n", trim($this->written()));

        self::assertSame(0, $code);
        // Une route écrite ici, les huit du contrôleur, puis deux autres.
        self::assertSame('11 route(s), dans l\'ordre où le routeur les essaie', $lines[0]);
        self::assertMatchesRegularExpression('#^GET +/ +fonction \(RoutesCommandTest\.php, ligne \d+\)$#', $lines[2]);
        self::assertMatchesRegularExpression('#^GET +/notes +' . preg_quote(NoteController::class, '#') . '::index$#', $lines[3]);
        self::assertMatchesRegularExpression('#^GET +/notes/\{id:int\} +' . preg_quote(NoteController::class, '#') . '::show$#', $lines[4]);
        self::assertMatchesRegularExpression('#^POST +/notes +' . preg_quote(NoteController::class, '#') . '::create +\[RequireToken\]$#', $lines[5]);
        self::assertMatchesRegularExpression('#^POST +/webhooks/paiement +fonction \(.*\) +\[WithoutCsrf, WithoutCsrf\]$#', $lines[11]);
        self::assertMatchesRegularExpression('#^GET\|POST +/contact +fonction#', $lines[12]);
    }

    public function testColumnsAreAligned(): void
    {
        $router = new Router();
        $router->get('/', static fn(): ResponseInterface => new Response());
        $router->delete('/une/adresse/bien/plus/longue', static fn(): ResponseInterface => new Response());

        $this->console($router)->run(['wazi', 'routes'], $this->newOutput());
        $lines = explode("\n", trim($this->written()));

        self::assertSame(strpos($lines[2], 'fonction'), strpos($lines[3], 'fonction'));
        self::assertStringStartsWith('GET     /', $lines[2]);
        self::assertStringStartsWith('DELETE  /une/adresse', $lines[3]);
    }

    public function testWithoutRoutesItSaysWhereToAddOne(): void
    {
        $code = $this->console(new Router())->run(['wazi', 'routes'], $this->newOutput());

        self::assertSame(0, $code);
        self::assertStringContainsString('Aucune route n\'est déclarée', $this->written());
        self::assertStringContainsString('app.php', $this->written());
    }

    /**
     * La commande lit les routes de l'application telle que le site la sert :
     * celles du noyau, déclarées dans app.php.
     */
    public function testItReadsTheRoutesOfTheKernel(): void
    {
        $app = new Kernel();
        $app->router->get('/articles/{slug:slug}', static fn(): ResponseInterface => new Response());

        $this->console($app->router)->run(['wazi', 'routes'], $this->newOutput());

        self::assertStringContainsString('/articles/{slug:slug}', $this->written());
    }

    public function testItAcceptsNoArgument(): void
    {
        $code = $this->console(new Router())->run(['wazi', 'routes', 'tout'], $this->newOutput());

        self::assertSame(Application::USAGE_ERROR, $code);
    }

    public function testTheListOfRoutesCannotBeChangedFromOutside(): void
    {
        $router = new Router();
        $router->get('/', static fn(): ResponseInterface => new Response());

        $routes = $router->routes();
        $routes[] = $routes[0];

        self::assertCount(1, $router->routes());
    }

    private function console(Router $router): Application
    {
        $console = new Application('wazi', 'cli');
        $console->add(new RoutesCommand($router));

        return $console;
    }

    private function newOutput(): Output
    {
        return new Output($this->standard, $this->errors, false);
    }

    private function written(): string
    {
        rewind($this->standard);

        return str_replace("\r\n", "\n", (string) stream_get_contents($this->standard));
    }
}
