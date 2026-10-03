<?php

declare(strict_types=1);

namespace Wazi\Tests\Kernel;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Wazi\Errors\ErrorHandler;
use Wazi\Http\Response;
use Wazi\Http\ServerRequest;
use Wazi\Http\ServerRequestCreator;
use Wazi\Kernel\Kernel;
use Wazi\Routing\Router;
use Wazi\Tests\Errors\Fixtures\MemoryErrorLog;
use Wazi\Tests\Http\Fixtures\FakeSapi;

require_once __DIR__ . '/../Http/Fixtures/sapi_functions.php';

/**
 * handle() se teste directement : une requête entre, une réponse sort.
 * run() lit les variables globales et envoie la réponse : header() est
 * remplacé pendant ces tests (voir FakeSapi), et le corps est capturé.
 */
final class KernelTest extends TestCase
{
    private MemoryErrorLog $log;

    protected function setUp(): void
    {
        $this->log = new MemoryErrorLog();
        FakeSapi::reset();
    }

    protected function tearDown(): void
    {
        FakeSapi::reset();
    }

    // --- handle() ----------------------------------------------------------

    public function testItIsAPsr15RequestHandler(): void
    {
        self::assertInstanceOf(RequestHandlerInterface::class, new Kernel());
    }

    public function testItAnswersARouteWithItsParameters(): void
    {
        $app = $this->kernel();
        $app->router->get('/articles/{id:int}', static fn(ServerRequestInterface $request): ResponseInterface => new Response(
            200,
            [],
            'Article ' . json_encode($request->getAttribute('id')),
        ));

        $response = $app->handle(new ServerRequest('GET', '/articles/42'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Article 42', (string) $response->getBody());
    }

    public function testAnUnknownAddressGivesA404Page(): void
    {
        $response = $this->kernel()->handle(new ServerRequest('GET', '/inconnu'));

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('Page introuvable', (string) $response->getBody());
    }

    public function testAWrongMethodGivesA405WithTheAllowedMethods(): void
    {
        $app = $this->kernel();
        $app->router->get('/contact', static fn(): ResponseInterface => new Response());

        $response = $app->handle(new ServerRequest('POST', '/contact'));

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET, HEAD', $response->getHeaderLine('Allow'));
    }

    public function testAnExceptionInARouteGivesA500PageAndNeverEscapes(): void
    {
        $app = $this->kernel();
        $app->router->get('/', static fn(): ResponseInterface => throw new \RuntimeException('mot de passe de la base : secret'));

        $response = $app->handle(new ServerRequest('GET', '/'));

        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString('secret', (string) $response->getBody());
        self::assertCount(1, $this->log->entries);
    }

    public function testProductionIsTheDefaultMode(): void
    {
        $app = new Kernel(errorHandler: null, router: new Router());
        $app->router->add(['GET'], '/', static fn(): string => 'pas une réponse');

        $html = (string) $app->handle(new ServerRequest('GET', '/inconnu'))->getBody();

        self::assertStringNotContainsString('mode développement', $html);
        self::assertStringNotContainsString('Aucune route', $html);
    }

    /**
     * Le critère de sortie de la version 0.1 : une erreur pédagogique, qui
     * dit ce qui s'est passé et comment corriger.
     */
    public function testDevelopmentModeShowsThePedagogicMessage(): void
    {
        $app = $this->kernel(development: true);
        $app->router->add(['GET'], '/', static fn(): string => 'Bonjour');

        $html = (string) $app->handle(new ServerRequest('GET', '/'))->getBody();

        self::assertStringContainsString('doit retourner une réponse', $html);
        self::assertStringContainsString('new Response', $html);
        self::assertStringContainsString('mode développement', $html);
    }

    public function testEachKernelHasItsOwnRouter(): void
    {
        self::assertNotSame(new Kernel()->router, new Kernel()->router);
    }

    // --- run() -------------------------------------------------------------

    public function testRunAnswersTheRequestFoundInThePhpGlobals(): void
    {
        $app = $this->kernel();
        $app->router->get('/bonjour/{prenom}', static fn(ServerRequestInterface $request): ResponseInterface => new Response(
            200,
            ['Content-Type' => 'text/plain; charset=utf-8'],
            'Bonjour ' . json_encode($request->getAttribute('prenom'), JSON_UNESCAPED_UNICODE),
        ));

        $output = $this->runKernel($app, ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/bonjour/Ren%C3%A9', 'HTTP_HOST' => 'exemple.com']);

        self::assertSame('Bonjour "René"', $output);
        self::assertSame(['Content-Type: text/plain; charset=utf-8', 'HTTP/1.1 200 OK'], FakeSapi::headerLines());
    }

    public function testRunSendsNoBodyForAHeadRequest(): void
    {
        $app = $this->kernel();
        $app->router->get('/', static fn(): ResponseInterface => new Response(200, ['X-Test' => 'a'], 'corps'));

        $output = $this->runKernel($app, ['REQUEST_METHOD' => 'HEAD', 'REQUEST_URI' => '/']);

        self::assertSame('', $output);
        self::assertSame(['X-Test: a', 'HTTP/1.1 200 OK'], FakeSapi::headerLines());
    }

    public function testRunAnswersARejectedRequestWithItsStatus(): void
    {
        $output = $this->runKernel($this->kernel(), ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'HTTP_HOST' => 'exemple.com/@pirate.com']);

        self::assertContains('HTTP/1.1 400 Bad Request', FakeSapi::headerLines());
        self::assertStringContainsString('Requête incorrecte', $output);
    }

    public function testRunUsesTheGivenRequestCreator(): void
    {
        $app = new Kernel(
            requestCreator: new ServerRequestCreator(maxBodySize: 10),
            errorHandler: new ErrorHandler(false, $this->log),
        );

        $output = $this->runKernel($app, ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/', 'CONTENT_LENGTH' => '11']);

        self::assertContains('HTTP/1.1 413 Content Too Large', FakeSapi::headerLines());
        self::assertStringContainsString('Contenu trop volumineux', $output);
    }

    /**
     * Un echo oublié dans une route : au lieu d'une page à moitié correcte,
     * le développeur reçoit une page qui explique le problème.
     */
    public function testAStrayEchoIsReplacedByAnExplanation(): void
    {
        $app = $this->kernel(development: true);
        $app->router->get('/', static function (): ResponseInterface {
            echo 'affichage oublié';

            return new Response(200, [], 'réponse prévue');
        });

        $output = $this->runKernel($app, ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/']);

        self::assertStringNotContainsString('affichage oublié', $output);
        self::assertStringNotContainsString('réponse prévue', $output);
        self::assertStringContainsString('on retourne une Response', $output);
        self::assertContains('HTTP/1.1 500 Internal Server Error', FakeSapi::headerLines());
    }

    public function testNothingIsSentWhenThePageHasAlreadyLeft(): void
    {
        FakeSapi::$sentAt = ['/var/www/public/index.php', 3];

        $output = $this->runKernel($this->kernel(), ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/']);

        self::assertSame('', $output);
        self::assertSame([], FakeSapi::$headers);
        self::assertCount(1, $this->log->entries, 'Le problème est tout de même consigné.');
    }

    // --- run() prend en main les erreurs de PHP -----------------------------

    public function testAPhpWarningBecomesAnErrorPage(): void
    {
        $app = $this->kernel();
        $app->router->get('/', static function (): ResponseInterface {
            trigger_error('avertissement avec un chemin secret', E_USER_WARNING);

            return new Response(200, [], 'ne doit pas partir');
        });

        $output = $this->runKernel($app, ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/']);

        self::assertContains('HTTP/1.1 500 Internal Server Error', FakeSapi::headerLines());
        self::assertStringContainsString('Erreur interne', $output);
        self::assertStringNotContainsString('chemin secret', $output, 'PHP ne doit rien afficher lui-même.');
        self::assertStringNotContainsString('ne doit pas partir', $output);
        self::assertStringContainsString('ErrorException', $this->log->entries[0]);
    }

    public function testASilencedErrorStaysSilent(): void
    {
        $app = $this->kernel();
        $app->router->get('/', static function (): ResponseInterface {
            $content = @file_get_contents(__DIR__ . '/fichier-absent.txt');

            return new Response(200, [], $content === false ? 'absent' : 'présent');
        });

        self::assertSame('absent', $this->runKernel($app, ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/']));
    }

    public function testADeprecationDoesNotBreakThePage(): void
    {
        $app = $this->kernel();
        $app->router->get('/', static function (): ResponseInterface {
            trigger_error('fonction bientôt retirée', E_USER_DEPRECATED);

            return new Response(200, [], 'page intacte');
        });

        self::assertSame('page intacte', $this->runKernel($app, ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/']));
    }

    /**
     * Sécurité : PHP ne doit jamais écrire lui-même une erreur dans la page.
     */
    public function testRunForbidsPhpFromDisplayingErrors(): void
    {
        $displayErrors = null;
        $app = $this->kernel();
        $app->router->get('/', static function () use (&$displayErrors): ResponseInterface {
            $displayErrors = ini_get('display_errors');

            return new Response();
        });

        $this->runKernel($app, ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/'], '1');

        self::assertSame('0', $displayErrors);
    }

    // --- Outils ------------------------------------------------------------

    private function kernel(bool $development = false): Kernel
    {
        return new Kernel(errorHandler: new ErrorHandler($development, $this->log));
    }

    /**
     * Lance run() comme le ferait public/index.php, puis remet PHP dans
     * l'état où il était : run() change des réglages globaux.
     *
     * @param array<string, string> $server
     *
     * @return string ce qui a été envoyé comme corps de réponse
     */
    private function runKernel(Kernel $app, array $server, string $displayErrors = '0'): string
    {
        $previousServer = $_SERVER;
        $previousSettings = [
            'display_errors' => ini_set('display_errors', $displayErrors),
            'log_errors' => ini_get('log_errors'),
            'error_log' => ini_set('error_log', (string) tempnam(sys_get_temp_dir(), 'wazi')),
        ];
        $_SERVER = $server;

        // PHPUnit baisse le niveau de rapport d'erreurs pendant un test, pour
        // traiter les erreurs lui-même. On remet celui d'une vraie application.
        $previousReporting = error_reporting(E_ALL);

        ob_start();

        try {
            $app->run();
        } finally {
            $output = (string) ob_get_clean();
            restore_error_handler();
            error_reporting($previousReporting);
            $_SERVER = $previousServer;
            $logFile = (string) ini_get('error_log');

            foreach ($previousSettings as $name => $value) {
                ini_set($name, $value === false ? '' : $value);
            }

            if (is_file($logFile)) {
                unlink($logFile);
            }
        }

        return $output;
    }
}
