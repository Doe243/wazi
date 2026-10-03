<?php

declare(strict_types=1);

namespace Wazi\Tests\Routing;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Wazi\Contracts\HttpError;
use Wazi\Http\Response;
use Wazi\Http\ServerRequest;
use Wazi\Routing\Exception\InvalidRouteException;
use Wazi\Routing\Exception\MethodNotAllowedException;
use Wazi\Routing\Exception\RouteNotFoundException;
use Wazi\Routing\Exception\RoutingException;
use Wazi\Routing\Router;

final class RouterTest extends TestCase
{
    public function testItIsAPsr15RequestHandler(): void
    {
        self::assertInstanceOf(RequestHandlerInterface::class, new Router());
    }

    // --- Choix de la route -------------------------------------------------

    public function testItRunsTheFunctionOfTheMatchingRoute(): void
    {
        $router = new Router();
        $router->get('/', self::answer('accueil'));
        $router->get('/contact', self::answer('contact'));

        self::assertSame('accueil', $this->body($router, 'GET', '/'));
        self::assertSame('contact', $this->body($router, 'GET', '/contact'));
    }

    public function testAnAddressWithoutPathIsTheHomePage(): void
    {
        $router = new Router();
        $router->get('/', self::answer('accueil'));

        self::assertSame('accueil', $this->body($router, 'GET', 'https://exemple.com'));
    }

    public function testTheQueryStringDoesNotCount(): void
    {
        $router = new Router();
        $router->get('/articles', self::answer('liste'));

        self::assertSame('liste', $this->body($router, 'GET', '/articles?page=2'));
    }

    public function testParametersBecomeRequestAttributes(): void
    {
        $router = new Router();
        $router->get('/blog/{annee:int}/{slug:slug}', static fn(ServerRequestInterface $request): ResponseInterface => new Response(
            200,
            [],
            json_encode($request->getAttributes(), JSON_THROW_ON_ERROR),
        ));

        self::assertSame('{"annee":2026,"slug":"mon-article"}', $this->body($router, 'GET', '/blog/2026/mon-article'));
    }

    public function testAnEncodedParameterIsDecoded(): void
    {
        $router = new Router();
        $router->get('/profil/{pseudo}', self::echoAttribute('pseudo'));

        self::assertSame('René Mumba', $this->body($router, 'GET', '/profil/Ren%C3%A9%20Mumba'));
    }

    public function testAnEncodedAddressMatchesAFixedSegmentWrittenInPlainText(): void
    {
        $router = new Router();
        $router->get('/café', self::answer('café'));

        self::assertSame('café', $this->body($router, 'GET', '/caf%C3%A9'));
    }

    public function testTheFirstDeclaredRouteWins(): void
    {
        $router = new Router();
        $router->get('/articles/nouveau', self::answer('formulaire'));
        $router->get('/articles/{slug:slug}', self::answer('article'));

        self::assertSame('formulaire', $this->body($router, 'GET', '/articles/nouveau'));
        self::assertSame('article', $this->body($router, 'GET', '/articles/autre'));
    }

    public function testEachMethodHasItsOwnFunction(): void
    {
        $router = new Router();
        $router->get('/articles', self::answer('GET'));
        $router->post('/articles', self::answer('POST'));
        $router->put('/articles', self::answer('PUT'));
        $router->patch('/articles', self::answer('PATCH'));
        $router->delete('/articles', self::answer('DELETE'));

        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            self::assertSame($method, $this->body($router, $method, '/articles'));
        }
    }

    public function testAddDeclaresSeveralMethodsAtOnce(): void
    {
        $router = new Router();
        $router->add(['GET', 'POST'], '/contact', self::answer('contact'));

        self::assertSame('contact', $this->body($router, 'GET', '/contact'));
        self::assertSame('contact', $this->body($router, 'POST', '/contact'));
    }

    public function testHeadIsAnsweredByTheGetRoute(): void
    {
        $router = new Router();
        $router->get('/', self::answer('accueil'));

        self::assertSame('accueil', $this->body($router, 'HEAD', '/'));
    }

    // --- 404 et 405 --------------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownPaths(): iterable
    {
        yield 'adresse inconnue' => ['/inconnu'];
        yield 'barre finale en trop' => ['/articles/'];
        yield 'segment en trop' => ['/articles/42/commentaires'];
        yield 'contrainte non respectée' => ['/articles/abc'];
        yield 'casse différente' => ['/Articles'];
        yield 'deux barres' => ['//articles'];
    }

    #[DataProvider('unknownPaths')]
    public function testAnUnknownAddressIsA404(string $path): void
    {
        $router = new Router();
        $router->get('/articles', self::answer('liste'));
        $router->get('/articles/{id:int}', self::answer('article'));

        try {
            $router->handle(new ServerRequest('GET', $path));
            self::fail('Une exception était attendue.');
        } catch (RouteNotFoundException $exception) {
            self::assertInstanceOf(HttpError::class, $exception);
            self::assertSame(404, $exception->getStatusCode());
            self::assertSame([], $exception->getResponseHeaders());
        }
    }

    public function testAnExistingAddressWithTheWrongMethodIsA405(): void
    {
        $router = new Router();
        $router->get('/articles/{id:int}', self::answer('lire'));
        $router->delete('/articles/{id:int}', self::answer('supprimer'));
        $router->post('/autre', self::answer('autre'));

        try {
            $router->handle(new ServerRequest('POST', '/articles/42'));
            self::fail('Une exception était attendue.');
        } catch (MethodNotAllowedException $exception) {
            self::assertInstanceOf(HttpError::class, $exception);
            self::assertSame(405, $exception->getStatusCode());
            self::assertSame(['GET', 'DELETE', 'HEAD'], $exception->getAllowedMethods());
            self::assertSame(['Allow' => 'GET, DELETE, HEAD'], $exception->getResponseHeaders());
            self::assertStringContainsString('$router->post', $exception->getMessage());
        }
    }

    public function testHeadIsNotOfferedWhenThereIsNoGetRoute(): void
    {
        $router = new Router();
        $router->post('/contact', self::answer('contact'));

        try {
            $router->handle(new ServerRequest('GET', '/contact'));
            self::fail('Une exception était attendue.');
        } catch (MethodNotAllowedException $exception) {
            self::assertSame(['POST'], $exception->getAllowedMethods());
        }
    }

    public function testAMethodInLowercaseIsNotTheSameMethod(): void
    {
        $router = new Router();
        $router->get('/', self::answer('accueil'));

        $this->expectException(MethodNotAllowedException::class);

        $router->handle(new ServerRequest('get', '/'));
    }

    // --- Sécurité : adresses piégées ---------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function trappedPaths(): iterable
    {
        yield 'retour au dossier parent' => ['/fichiers/..'];
        yield 'retour au dossier parent encodé' => ['/fichiers/%2e%2e'];
        yield 'retour au dossier parent à moitié encodé' => ['/fichiers/.%2E'];
        yield 'dossier courant' => ['/fichiers/.'];
        yield 'dossier courant encodé' => ['/fichiers/%2e'];
        yield 'barre oblique encodée' => ['/fichiers/..%2Fsecret'];
        yield 'barre oblique encodée en minuscules' => ['/fichiers/a%2fb'];
        yield 'barre oblique inversée encodée' => ['/fichiers/..%5Csecret'];
        yield 'octet nul' => ['/fichiers/photo.png%00.php'];
        yield 'retour à la ligne' => ['/fichiers/a%0Ab'];
        yield 'retour chariot' => ['/fichiers/a%0Db'];
        yield 'tabulation' => ['/fichiers/a%09b'];
        yield 'octets qui ne sont pas de l\'UTF-8' => ['/fichiers/%FF%FE'];
        yield 'UTF-8 tronqué' => ['/fichiers/caf%C3'];
        yield 'barre oblique en UTF-8 trop long' => ['/fichiers/..%C0%AFsecret'];
    }

    /**
     * Aucune de ces adresses ne doit atteindre la fonction de la route : un
     * paramètre ne doit jamais permettre de sortir du dossier prévu.
     */
    #[DataProvider('trappedPaths')]
    public function testATrappedAddressNeverReachesTheRoute(string $path): void
    {
        $reached = false;
        $router = new Router();
        $router->get('/fichiers/{nom}', static function () use (&$reached): ResponseInterface {
            $reached = true;

            return new Response();
        });

        try {
            $router->handle(new ServerRequest('GET', $path));
            self::fail('Une exception était attendue.');
        } catch (RouteNotFoundException $exception) {
            self::assertSame(404, $exception->getStatusCode());
        }

        self::assertFalse($reached);
    }

    /**
     * Un double encodage n'est décodé qu'une fois : « %252e%252e » reste le
     * texte « %2e%2e », sans danger, et n'est jamais redécodé en « .. ».
     */
    public function testADoubleEncodedValueIsOnlyDecodedOnce(): void
    {
        $router = new Router();
        $router->get('/fichiers/{nom}', self::echoAttribute('nom'));

        self::assertSame('%2e%2e', $this->body($router, 'GET', '/fichiers/%252e%252e'));
    }

    public function testTheNotFoundMessageCannotBeUsedToForgeLogLines(): void
    {
        try {
            new Router()->handle(new ServerRequest('GET', '/a%0AFAUSSE-LIGNE/' . str_repeat('x', 500)));
            self::fail('Une exception était attendue.');
        } catch (RouteNotFoundException $exception) {
            self::assertStringNotContainsString("\n", $exception->getMessage());
            self::assertLessThan(500, strlen($exception->getMessage()));
        }
    }

    /**
     * Un paramètre de route est écrit par le visiteur : il ne doit pas pouvoir
     * remplacer une valeur posée plus tôt sur la requête, comme l'utilisateur connecté.
     */
    public function testAParameterNeverOverwritesAnExistingAttribute(): void
    {
        $router = new Router();
        $router->get('/profil/{utilisateur}', self::echoAttribute('utilisateur'));

        $request = new ServerRequest('GET', '/profil/admin')->withAttribute('utilisateur', 'alice');

        try {
            $router->handle($request);
            self::fail('Une exception était attendue.');
        } catch (RoutingException $exception) {
            self::assertStringContainsString('{utilisateur}', $exception->getMessage());
            self::assertStringNotContainsString('admin', $exception->getMessage());
        }
    }

    public function testAnAttributeSetToNullIsProtectedToo(): void
    {
        $router = new Router();
        $router->get('/profil/{utilisateur}', self::echoAttribute('utilisateur'));

        $this->expectException(RoutingException::class);

        $router->handle(new ServerRequest('GET', '/profil/admin')->withAttribute('utilisateur', null));
    }

    public function testOtherAttributesAreKept(): void
    {
        $router = new Router();
        $router->get('/articles/{id:int}', self::echoAttribute('langue'));

        $request = new ServerRequest('GET', '/articles/42')->withAttribute('langue', 'fr');

        self::assertSame('fr', (string) $router->handle($request)->getBody());
    }

    // --- Erreurs de déclaration -------------------------------------------

    public function testTheSameRouteCannotBeDeclaredTwice(): void
    {
        $router = new Router();
        $router->add(['GET', 'POST'], '/contact', self::answer('premier'));

        try {
            $router->post('/contact', self::answer('second'));
            self::fail('Une exception était attendue.');
        } catch (InvalidRouteException $exception) {
            self::assertStringContainsString('POST /contact', $exception->getMessage());
        }
    }

    public function testAnInvalidRouteIsRejectedWhenDeclared(): void
    {
        $this->expectException(InvalidRouteException::class);

        new Router()->get('articles', self::answer('liste'));
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function invalidResults(): iterable
    {
        yield 'texte' => ['<h1>Bonjour</h1>', 'string'];
        yield 'rien' => [null, 'null'];
        yield 'tableau' => [['a' => 1], 'array'];
    }

    #[DataProvider('invalidResults')]
    public function testAFunctionThatDoesNotReturnAResponseIsExplained(mixed $result, string $type): void
    {
        $router = new Router();
        $router->add(['GET'], '/articles/{id:int}', static fn(): mixed => $result);

        try {
            $router->handle(new ServerRequest('GET', '/articles/42'));
            self::fail('Une exception était attendue.');
        } catch (RoutingException $exception) {
            self::assertStringContainsString('/articles/{id:int}', $exception->getMessage());
            self::assertStringContainsString('« ' . $type . ' »', $exception->getMessage());
            self::assertStringContainsString('new Response', $exception->getMessage());
        }
    }

    // --- Outils ------------------------------------------------------------

    /**
     * @return \Closure(): ResponseInterface
     */
    private static function answer(string $text): \Closure
    {
        return static fn(): ResponseInterface => new Response(200, [], $text);
    }

    /**
     * @return \Closure(ServerRequestInterface): ResponseInterface
     */
    private static function echoAttribute(string $name): \Closure
    {
        return static function (ServerRequestInterface $request) use ($name): ResponseInterface {
            $value = $request->getAttribute($name);

            return new Response(200, [], is_scalar($value) ? (string) $value : '');
        };
    }

    private function body(Router $router, string $method, string $uri): string
    {
        return (string) $router->handle(new ServerRequest($method, $uri))->getBody();
    }
}
