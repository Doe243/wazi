<?php

declare(strict_types=1);

namespace Wazi\Tests\Middleware;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Wazi\Http\Response;
use Wazi\Http\ServerRequest;
use Wazi\Middleware\Exception\InvalidMiddlewareException;
use Wazi\Middleware\Pipeline;

final class PipelineTest extends TestCase
{
    public function testItIsAPsr15RequestHandler(): void
    {
        self::assertInstanceOf(RequestHandlerInterface::class, new Pipeline([], self::finalHandler()));
    }

    public function testWithoutMiddlewareTheFinalHandlerAnswers(): void
    {
        $response = new Pipeline([], self::finalHandler())->handle(new ServerRequest('GET', '/'));

        self::assertSame('[fin]', (string) $response->getBody());
    }

    /**
     * Les couches de l'oignon : le premier middleware de la liste est le plus
     * à l'extérieur. Il agit en premier à l'aller, en dernier au retour.
     */
    public function testMiddlewaresWrapEachOtherInTheOrderOfTheList(): void
    {
        $pipeline = new Pipeline([self::wrap('A'), self::wrap('B'), self::wrap('C')], self::finalHandler());

        $response = $pipeline->handle(new ServerRequest('GET', '/'));

        self::assertSame('A(B(C([fin])))', (string) $response->getBody());
    }

    public function testAMiddlewareCanChangeTheRequestSeenByTheNextOnes(): void
    {
        $addUser = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request->withAttribute('utilisateur', 'alice'));
            }
        };
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, [], json_encode($request->getAttribute('utilisateur'), JSON_THROW_ON_ERROR));
            }
        };

        self::assertSame('"alice"', (string) new Pipeline([$addUser], $handler)->handle(new ServerRequest('GET', '/'))->getBody());
    }

    /**
     * Un middleware qui n'appelle pas la suite répond lui-même : rien de ce
     * qui se trouve après lui n'est exécuté. C'est ainsi qu'on protège une page.
     */
    public function testAMiddlewareCanAnswerItselfAndStopTheChain(): void
    {
        $refuse = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return new Response(403, [], 'accès refusé');
            }
        };
        $after = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                throw new \LogicException('Ce middleware ne devait pas être atteint.');
            }
        };

        $response = new Pipeline([self::wrap('A'), $refuse, $after], self::finalHandler())->handle(new ServerRequest('GET', '/'));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('A(accès refusé)', (string) $response->getBody(), 'Les middlewares placés avant voient quand même la réponse.');
    }

    public function testAPipelineCanBeUsedSeveralTimes(): void
    {
        $pipeline = new Pipeline([self::wrap('A'), self::wrap('B')], self::finalHandler());

        $first = (string) $pipeline->handle(new ServerRequest('GET', '/'))->getBody();
        $second = (string) $pipeline->handle(new ServerRequest('GET', '/articles'))->getBody();

        self::assertSame('A(B([fin]))', $first);
        self::assertSame('A(B([fin]))', $second);
    }

    public function testAnExceptionCrossesThePipelineUntouched(): void
    {
        $failing = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \DomainException('panne');
            }
        };

        $this->expectException(\DomainException::class);

        new Pipeline([self::wrap('A')], $failing)->handle(new ServerRequest('GET', '/'));
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function valuesThatAreNotMiddlewares(): iterable
    {
        yield 'nom de classe' => ['App\Middleware\Auth', 'string'];
        yield 'fonction' => [static fn(): null => null, 'Closure'];
        yield 'null' => [null, 'null'];
        yield 'gestionnaire' => [self::finalHandler(), 'RequestHandlerInterface@anonymous'];
    }

    #[DataProvider('valuesThatAreNotMiddlewares')]
    public function testItRejectsAnythingThatIsNotAMiddleware(mixed $value, string $type): void
    {
        try {
            new Pipeline([self::wrap('A'), $value], self::finalHandler());
            self::fail('Une exception était attendue.');
        } catch (InvalidMiddlewareException $exception) {
            self::assertStringContainsString('n° 1', $exception->getMessage());
            self::assertStringContainsString($type, $exception->getMessage());
            self::assertStringContainsString('MiddlewareInterface', $exception->getMessage());
        }
    }

    // --- Outils ------------------------------------------------------------

    private static function finalHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, [], '[fin]');
            }
        };
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
