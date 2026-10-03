<?php

declare(strict_types=1);

namespace Wazi\Tests\Routing;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wazi\Http\ServerRequest;
use Wazi\Routing\Attribute\Delete;
use Wazi\Routing\Attribute\Get;
use Wazi\Routing\Attribute\Patch;
use Wazi\Routing\Attribute\Post;
use Wazi\Routing\Attribute\Put;
use Wazi\Routing\Exception\InvalidRouteException;
use Wazi\Routing\Exception\MethodNotAllowedException;
use Wazi\Routing\Exception\RouteNotFoundException;
use Wazi\Routing\Router;
use Wazi\Tests\Routing\Fixtures\BrokenRouteController;
use Wazi\Tests\Routing\Fixtures\Greeter;
use Wazi\Tests\Routing\Fixtures\NoteController;
use Wazi\Tests\Routing\Fixtures\PrivateRouteController;

/**
 * Les routes écrites en attributs (#[Get], #[Post]...) sur les méthodes d'un contrôleur.
 */
final class AttributeRoutesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, array<string, string>, int, string}>
     */
    public static function routesOfTheNoteController(): iterable
    {
        $token = ['Authorization' => 'Bearer bon-jeton'];

        yield 'GET liste' => ['GET', '/notes', [], 200, 'liste des notes'];
        yield 'GET avec paramètre' => ['GET', '/notes/7', [], 200, 'note 7'];
        yield 'POST gardé, avec jeton' => ['POST', '/notes', $token, 201, 'note créée'];
        yield 'PUT' => ['PUT', '/notes/7', [], 200, 'note 7 modifiée'];
        yield 'PATCH, même méthode que PUT' => ['PATCH', '/notes/7', [], 200, 'note 7 modifiée'];
        yield 'DELETE gardé, avec jeton' => ['DELETE', '/notes/7', $token, 200, 'note 7 supprimée'];
        yield 'deux chemins pour une méthode (1)' => ['GET', '/salut/Alice', [], 200, 'Bonjour Alice'];
        yield 'deux chemins pour une méthode (2)' => ['GET', '/bonjour/Alice', [], 200, 'Bonjour Alice'];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('routesOfTheNoteController')]
    public function testAttributesDeclareTheRoutesOfAController(string $method, string $uri, array $headers, int $status, string $body): void
    {
        $router = new Router();
        $router->addController(NoteController::class);

        $response = $router->handle(new ServerRequest($method, $uri, $headers));

        self::assertSame($status, $response->getStatusCode());
        self::assertSame($body, (string) $response->getBody());
    }

    public function testTheMiddlewaresOfAnAttributeGuardItsRoute(): void
    {
        $router = new Router();
        $router->addController(NoteController::class);

        self::assertSame(401, $router->handle(new ServerRequest('POST', '/notes'))->getStatusCode());
        self::assertSame(401, $router->handle(new ServerRequest('DELETE', '/notes/7'))->getStatusCode());
        self::assertSame(200, $router->handle(new ServerRequest('GET', '/notes'))->getStatusCode(), 'La route voisine n\'est pas gardée.');
    }

    public function testThe405ListsTheMethodsDeclaredByAttributes(): void
    {
        $router = new Router();
        $router->addController(NoteController::class);

        try {
            $router->handle(new ServerRequest('POST', '/notes/7'));
            self::fail('Une exception était attendue.');
        } catch (MethodNotAllowedException $exception) {
            self::assertEqualsCanonicalizing(['GET', 'HEAD', 'PUT', 'PATCH', 'DELETE'], $exception->getAllowedMethods());
        }
    }

    /**
     * Sécurité : une méthode publique sans attribut n'est pas une route.
     * Écrire une méthode dans un contrôleur ne l'expose pas au visiteur.
     */
    public function testAPublicMethodWithoutAttributeIsNotARoute(): void
    {
        $router = new Router();
        $router->addController(NoteController::class);

        foreach (['/notARoute', '/notes/notARoute', '/NoteController/notARoute'] as $uri) {
            try {
                $router->handle(new ServerRequest('GET', $uri));
                self::fail('Une exception était attendue pour ' . $uri);
            } catch (RouteNotFoundException $exception) {
                self::assertSame(404, $exception->getStatusCode());
            }
        }
    }

    public function testAttributeRoutesAndClosureRoutesLiveTogether(): void
    {
        $router = new Router();
        $router->get('/', static fn() => new \Wazi\Http\Response(200, [], 'accueil'));
        $router->addController(NoteController::class);

        self::assertSame('accueil', (string) $router->handle(new ServerRequest('GET', '/'))->getBody());
        self::assertSame('note 7', (string) $router->handle(new ServerRequest('GET', '/notes/7'))->getBody());
    }

    // --- Erreurs de déclaration -------------------------------------------

    public function testTheSameControllerCannotBeDeclaredTwice(): void
    {
        $router = new Router();
        $router->addController(NoteController::class);

        $this->expectException(InvalidRouteException::class);
        $this->expectExceptionMessage('déclarée deux fois');

        $router->addController(NoteController::class);
    }

    public function testAControllerWithoutAnyRouteIsExplained(): void
    {
        try {
            new Router()->addController(Greeter::class);
            self::fail('Une exception était attendue.');
        } catch (InvalidRouteException $exception) {
            self::assertStringContainsString(Greeter::class, $exception->getMessage());
            self::assertStringContainsString('use Wazi\Routing\Attribute\Get;', $exception->getMessage());
        }
    }

    public function testARouteOnANonPublicMethodIsRejected(): void
    {
        try {
            new Router()->addController(PrivateRouteController::class);
            self::fail('Une exception était attendue.');
        } catch (InvalidRouteException $exception) {
            self::assertStringContainsString('secret', $exception->getMessage());
            self::assertStringContainsString('public function', $exception->getMessage());
        }
    }

    public function testAnInvalidPathInAnAttributeIsRejectedWhenTheControllerIsDeclared(): void
    {
        $this->expectException(InvalidRouteException::class);
        $this->expectExceptionMessage('notes-sans-barre');

        new Router()->addController(BrokenRouteController::class);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function namesThatAreNotControllers(): iterable
    {
        yield 'classe inexistante' => ['App\Controller\QuiNExistePas'];
        yield 'chemin' => ['../../etc/passwd'];
        yield 'retour à la ligne' => ["Classe\nFAUSSE LIGNE"];
    }

    #[DataProvider('namesThatAreNotControllers')]
    public function testAnUnknownControllerIsRejectedWithoutForgingLogLines(string $class): void
    {
        try {
            self::callUnchecked(new Router(), 'addController', $class);
            self::fail('Une exception était attendue.');
        } catch (InvalidRouteException $exception) {
            self::assertStringContainsString('introuvable', $exception->getMessage());
            self::assertStringNotContainsString("\n", $exception->getMessage());
            self::assertStringNotContainsString('/', $exception->getMessage());
        }
    }

    // --- Les attributs eux-mêmes -------------------------------------------

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function attributeClasses(): iterable
    {
        yield 'Get' => [Get::class];
        yield 'Post' => [Post::class];
        yield 'Put' => [Put::class];
        yield 'Patch' => [Patch::class];
        yield 'Delete' => [Delete::class];
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('attributeClasses')]
    public function testEachAttributeIsRepeatableAndOnlyAllowedOnMethods(string $class): void
    {
        $attributes = new \ReflectionClass($class)->getAttributes(\Attribute::class);

        self::assertCount(1, $attributes);
        self::assertSame(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE, $attributes[0]->newInstance()->flags);
    }

    /**
     * Appelle une méthode comme le ferait un code sans analyse statique :
     * PHPStan refuserait, à raison, de laisser passer ces mauvais arguments.
     */
    private static function callUnchecked(object $object, string $method, mixed ...$arguments): mixed
    {
        return $object->{$method}(...$arguments);
    }
}
