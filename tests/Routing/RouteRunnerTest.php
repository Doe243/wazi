<?php

declare(strict_types=1);

namespace Wazi\Tests\Routing;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Wazi\Container\Container;
use Wazi\Http\Exception\InvalidMiddlewareException;
use Wazi\Http\Response;
use Wazi\Http\ServerRequest;
use Wazi\Routing\Exception\InvalidRouteException;
use Wazi\Routing\Exception\RouteNotFoundException;
use Wazi\Routing\Exception\RoutingException;
use Wazi\Routing\Router;
use Wazi\Tests\Routing\Fixtures\ArticleController;
use Wazi\Tests\Routing\Fixtures\Greeter;
use Wazi\Tests\Routing\Fixtures\MagicController;
use Wazi\Tests\Routing\Fixtures\RequireToken;

/**
 * RouteRunner exécute le code d'une route. Il est testé à travers le routeur,
 * comme il est utilisé : contrôleurs, arguments, middlewares de route.
 */
final class RouteRunnerTest extends TestCase
{
    protected function setUp(): void
    {
        ArticleController::$created = 0;
        RequireToken::$created = 0;
    }

    // --- Contrôleurs -------------------------------------------------------

    public function testARouteCanPointToAControllerMethod(): void
    {
        $router = new Router();
        $router->get('/articles', [ArticleController::class, 'index']);

        self::assertSame('liste', $this->body($router, '/articles'));
    }

    public function testTheControllerIsBuiltByTheContainerWithItsDependencies(): void
    {
        $container = new Container();
        $router = new Router($container);
        $router->get('/bonjour/{name}', [ArticleController::class, 'greet']);

        self::assertSame('Bonjour René !', $this->body($router, '/bonjour/Ren%C3%A9'));
        self::assertInstanceOf(Greeter::class, $container->get(Greeter::class));
    }

    /**
     * Déclarer une route ne charge ni ne fabrique rien : seul le contrôleur
     * de la route demandée est créé.
     */
    public function testAControllerIsOnlyBuiltWhenItsRouteIsRequested(): void
    {
        $router = new Router();
        $router->get('/articles', [ArticleController::class, 'index']);
        $router->get('/autre', static fn(): ResponseInterface => new Response(200, [], 'autre'));
        $router->add(['GET'], '/inexistant', ['App\Controller\QuiNExistePas', 'index']);

        $afterDeclaring = self::controllersBuilt();

        $this->body($router, '/autre');
        $afterAnotherRoute = self::controllersBuilt();

        $this->body($router, '/articles');
        $this->body($router, '/articles');
        $afterTwoRequests = self::controllersBuilt();

        self::assertSame([0, 0, 1], [$afterDeclaring, $afterAnotherRoute, $afterTwoRequests]);
    }

    public function testAStaticMethodWorksToo(): void
    {
        $router = new Router();
        $router->get('/', [ArticleController::class, 'fromStatic']);

        self::assertSame('statique', $this->body($router, '/'));
    }

    // --- Arguments ---------------------------------------------------------

    public function testAMethodReceivesTheRequestAndTheRouteParametersByName(): void
    {
        $router = new Router();
        $router->get('/articles/{id:int}', [ArticleController::class, 'show']);

        self::assertSame('GET article 42 (int)', $this->body($router, '/articles/42'));
    }

    public function testAFunctionReceivesItsArgumentsTheSameWay(): void
    {
        $router = new Router();
        $router->get('/blog/{year:int}/{slug:slug}', static fn(string $slug, ServerRequestInterface $request, int $year): ResponseInterface => new Response(
            200,
            [],
            $request->getUri()->getPath() . ' → ' . $year . ' / ' . $slug,
        ));

        self::assertSame('/blog/2026/mon-article → 2026 / mon-article', $this->body($router, '/blog/2026/mon-article'));
    }

    public function testAnUntypedParameterNamedRequestReceivesTheRequest(): void
    {
        $router = new Router();
        $router->add(['GET'], '/', static fn($request): ResponseInterface => new Response(
            200,
            [],
            $request instanceof ServerRequestInterface ? 'requête reçue' : 'rien',
        ));

        self::assertSame('requête reçue', $this->body($router, '/'));
    }

    public function testAnArgumentWithADefaultValueKeepsIt(): void
    {
        $router = new Router();
        $router->get('/bonjour/{name}', [ArticleController::class, 'greet']);

        self::assertStringEndsWith(' !', $this->body($router, '/bonjour/Alice'));
    }

    public function testAFunctionWithoutParametersStillWorks(): void
    {
        $router = new Router();
        $router->get('/articles/{id:int}', static fn(): ResponseInterface => new Response(200, [], 'ok'));

        self::assertSame('ok', $this->body($router, '/articles/42'));
    }

    public function testRouteParametersAreStillRequestAttributes(): void
    {
        $router = new Router();
        $router->get('/articles/{id:int}', static fn(ServerRequestInterface $request): ResponseInterface => new Response(
            200,
            [],
            json_encode($request->getAttribute('id'), JSON_THROW_ON_ERROR),
        ));

        self::assertSame('42', $this->body($router, '/articles/42'));
    }

    /**
     * Sécurité : un argument n'est rempli que par un paramètre de la ROUTE.
     * Un attribut posé sur la requête, ou un champ de l'adresse, ne peut pas
     * s'y glisser.
     */
    public function testAnArgumentIsNeverFilledFromAttributesOrQueryString(): void
    {
        $router = new Router();
        $router->get('/profil', static fn(string $role = 'visiteur'): ResponseInterface => new Response(200, [], $role));

        $request = new ServerRequest('GET', '/profil?role=admin')
            ->withQueryParams(['role' => 'admin'])
            ->withAttribute('role', 'admin')
            ->withParsedBody(['role' => 'admin']);

        self::assertSame('visiteur', (string) $router->handle($request)->getBody());
    }

    // --- Erreurs pédagogiques ----------------------------------------------

    public function testAnArgumentThatCannotBeProvidedIsExplained(): void
    {
        $router = new Router();
        $router->get('/articles', [ArticleController::class, 'needsService']);

        try {
            $router->handle(new ServerRequest('GET', '/articles'));
            self::fail('Une exception était attendue.');
        } catch (RoutingException $exception) {
            self::assertStringContainsString('$greeter', $exception->getMessage());
            self::assertStringContainsString('constructeur du contrôleur', $exception->getMessage());
        }
    }

    public function testATypeMismatchIsExplainedInsteadOfAPhpTypeError(): void
    {
        $router = new Router();
        $router->get('/articles/{id:int}', [ArticleController::class, 'wrongType']);

        try {
            $router->handle(new ServerRequest('GET', '/articles/42'));
            self::fail('Une exception était attendue.');
        } catch (RoutingException $exception) {
            self::assertStringContainsString('$id de type string', $exception->getMessage());
            self::assertStringContainsString('{id:int}', $exception->getMessage());
        }
    }

    public function testAnIntExpectedFromAnUnconstrainedParameterIsExplained(): void
    {
        $router = new Router();
        $router->get('/articles/{id}', [ArticleController::class, 'show']);

        try {
            $router->handle(new ServerRequest('GET', '/articles/42'));
            self::fail('Une exception était attendue.');
        } catch (RoutingException $exception) {
            self::assertStringContainsString('$id de type int', $exception->getMessage());
        }
    }

    public function testAnUnknownControllerIsExplained(): void
    {
        $router = new Router();
        $router->add(['GET'], '/', ['App\Controller\QuiNExistePas', 'index']);

        try {
            $router->handle(new ServerRequest('GET', '/'));
            self::fail('Une exception était attendue.');
        } catch (RoutingException $exception) {
            self::assertStringContainsString('App\Controller\QuiNExistePas', $exception->getMessage());
            self::assertNotNull($exception->getPrevious());
        }
    }

    public function testAMethodThatDoesNotReturnAResponseIsExplained(): void
    {
        $router = new Router();
        $router->get('/', [ArticleController::class, 'returnsText']);

        $this->expectException(RoutingException::class);
        $this->expectExceptionMessage('doit retourner une réponse');

        $router->handle(new ServerRequest('GET', '/'));
    }

    // --- Sécurité : ce qui peut être appelé --------------------------------

    /**
     * @return iterable<string, array{class-string, string}>
     */
    public static function methodsThatMustNotBeCallable(): iterable
    {
        yield 'méthode protégée' => [ArticleController::class, 'hidden'];
        yield 'méthode inexistante' => [ArticleController::class, 'absente'];
        yield 'méthode magique __call' => [MagicController::class, 'nimporteQuoi'];
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('methodsThatMustNotBeCallable')]
    public function testOnlyARealPublicMethodCanBeCalled(string $class, string $method): void
    {
        $router = new Router();
        $router->get('/', [$class, $method]);

        try {
            $router->handle(new ServerRequest('GET', '/'));
            self::fail('Une exception était attendue.');
        } catch (RoutingException $exception) {
            self::assertStringContainsString('n\'existe pas ou n\'est pas publique', $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function invalidHandlers(): iterable
    {
        yield 'un seul élément' => [[ArticleController::class]];
        yield 'trois éléments' => [[ArticleController::class, 'index', 'extra']];
        yield 'objet au lieu du nom de classe' => [[new \stdClass(), 'index']];
        yield 'classe qui n\'a pas la forme d\'un nom' => [['../../etc/passwd', 'index']];
        yield 'méthode avec des caractères interdits' => [[ArticleController::class, 'index(); system("id")']];
        yield 'syntaxe Classe::méthode' => [[ArticleController::class, 'Autre::index']];
        yield 'tableau vide' => [[]];
    }

    /**
     * @param array<mixed> $handler
     */
    #[DataProvider('invalidHandlers')]
    public function testAMalformedHandlerIsRejectedWhenTheRouteIsDeclared(array $handler): void
    {
        $this->expectException(InvalidRouteException::class);
        $this->expectExceptionMessage('[ArticleController::class, \'show\']');

        new Router()->add(['GET'], '/', $handler);
    }

    /**
     * Sécurité : le contrôleur exécuté est celui de la déclaration. Rien dans
     * la requête ne permet d'en désigner un autre.
     */
    public function testTheRequestCannotChooseAnotherControllerOrMethod(): void
    {
        $router = new Router();
        $router->get('/articles/{action}', [ArticleController::class, 'index']);

        $request = new ServerRequest('GET', '/articles/hidden?_controller=' . rawurlencode(MagicController::class) . '&_method=hidden')
            ->withQueryParams(['_controller' => MagicController::class, 'action' => 'hidden']);

        self::assertSame('liste', (string) $router->handle($request)->getBody());
    }

    // --- Middlewares d'une route -------------------------------------------

    public function testARouteCanBeGuardedByItsOwnMiddleware(): void
    {
        $router = new Router();
        $router->get('/admin', [ArticleController::class, 'index'], [RequireToken::class]);
        $router->get('/public', [ArticleController::class, 'index']);

        $refused = $router->handle(new ServerRequest('GET', '/admin'));
        $accepted = $router->handle(new ServerRequest('GET', '/admin', ['Authorization' => 'Bearer bon-jeton']));
        $public = $router->handle(new ServerRequest('GET', '/public'));

        self::assertSame(401, $refused->getStatusCode());
        self::assertSame('Bonjour inconnu, connexion requise', (string) $refused->getBody());
        self::assertSame('liste', (string) $accepted->getBody());
        self::assertSame('passé', $accepted->getHeaderLine('X-Garde'));
        self::assertFalse($public->hasHeader('X-Garde'), 'Le garde ne concerne que sa route.');
    }

    /**
     * Sécurité : quand le garde refuse, le contrôleur n'est même pas fabriqué.
     */
    public function testTheControllerIsNotBuiltWhenTheGuardRefuses(): void
    {
        $router = new Router();
        $router->get('/admin', [ArticleController::class, 'index'], [RequireToken::class]);

        $router->handle(new ServerRequest('GET', '/admin'));

        self::assertSame(0, ArticleController::$created);
    }

    public function testAMiddlewareNamedByItsClassIsOnlyBuiltForItsRoute(): void
    {
        $router = new Router();
        $router->get('/admin', [ArticleController::class, 'index'], [RequireToken::class]);
        $router->get('/public', [ArticleController::class, 'index']);

        $this->body($router, '/public');

        self::assertSame(0, RequireToken::$created);
    }

    public function testRouteMiddlewaresRunInTheOrderOfTheList(): void
    {
        $router = new Router();
        $router->get('/', static fn(): ResponseInterface => new Response(200, [], '[route]'), [self::wrap('A'), self::wrap('B')]);

        self::assertSame('A(B([route]))', $this->body($router, '/'));
    }

    public function testARouteMiddlewareSeesTheRouteParameters(): void
    {
        $seen = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request)->withHeader('X-Vu', json_encode($request->getAttribute('id'), JSON_THROW_ON_ERROR));
            }
        };
        $router = new Router();
        $router->get('/articles/{id:int}', [ArticleController::class, 'show'], [$seen]);

        self::assertSame('42', $router->handle(new ServerRequest('GET', '/articles/42'))->getHeaderLine('X-Vu'));
    }

    /**
     * Sécurité : une adresse inconnue ne fait fabriquer aucun middleware ni aucun contrôleur.
     */
    public function testAnUnknownAddressBuildsNothing(): void
    {
        $router = new Router();
        $router->get('/admin', [ArticleController::class, 'index'], [RequireToken::class]);

        try {
            $router->handle(new ServerRequest('GET', '/admin/autre'));
            self::fail('Une exception était attendue.');
        } catch (RouteNotFoundException) {
            self::assertSame(0, RequireToken::$created);
            self::assertSame(0, ArticleController::$created);
        }
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidRouteMiddlewares(): iterable
    {
        yield 'fonction' => [static fn(): null => null];
        yield 'texte qui n\'est pas un nom de classe' => ['../../etc/passwd'];
        yield 'nombre' => [42];
    }

    #[DataProvider('invalidRouteMiddlewares')]
    public function testAnInvalidRouteMiddlewareIsRejectedWhenTheRouteIsDeclared(mixed $middleware): void
    {
        $this->expectException(InvalidMiddlewareException::class);

        new Router()->get('/', static fn(): ResponseInterface => new Response(), [$middleware]);
    }

    public function testAClassThatIsNotAMiddlewareIsRejectedWhenTheRouteRuns(): void
    {
        $router = new Router();
        $router->get('/', static fn(): ResponseInterface => new Response(), [Greeter::class]);

        $this->expectException(InvalidMiddlewareException::class);

        $router->handle(new ServerRequest('GET', '/'));
    }

    // --- Outils ------------------------------------------------------------

    private static function controllersBuilt(): int
    {
        return ArticleController::$created;
    }

    private function body(Router $router, string $uri): string
    {
        return (string) $router->handle(new ServerRequest('GET', $uri))->getBody();
    }

    /**
     * Un middleware qui entoure le corps de la réponse de son nom : A(...).
     */
    private static function wrap(string $name): MiddlewareInterface
    {
        return new class ($name) implements MiddlewareInterface {
            public function __construct(private readonly string $name) {}

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $response = $handler->handle($request);

                return new Response($response->getStatusCode(), [], $this->name . '(' . $response->getBody() . ')');
            }
        };
    }
}
