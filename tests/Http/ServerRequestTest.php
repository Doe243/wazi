<?php

declare(strict_types=1);

namespace Wazi\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Wazi\Http\Exception\InvalidMessageException;
use Wazi\Http\ServerRequest;

/**
 * La méthode, l'URI et l'en-tête Host sont testés dans RequestTest ;
 * les en-têtes, le corps et la version du protocole dans MessageTraitTest.
 */
final class ServerRequestTest extends TestCase
{
    public function testItImplementsPsr7(): void
    {
        self::assertInstanceOf(ServerRequestInterface::class, new ServerRequest('GET', '/'));
    }

    public function testItBehavesLikeARequest(): void
    {
        $request = new ServerRequest('POST', 'https://exemple.com/articles?page=2', ['Accept' => 'text/html'], 'corps');

        self::assertSame('POST', $request->getMethod());
        self::assertSame('/articles?page=2', $request->getRequestTarget());
        self::assertSame(['Host', 'Accept'], array_keys($request->getHeaders()));
        self::assertSame('corps', (string) $request->getBody());
    }

    public function testItRejectsAnInvalidMethodLikeARequest(): void
    {
        $this->expectException(InvalidMessageException::class);

        new ServerRequest("GET\r\n", '/');
    }

    public function testEverythingIsEmptyByDefault(): void
    {
        $request = new ServerRequest('GET', '/');

        self::assertSame([], $request->getServerParams());
        self::assertSame([], $request->getCookieParams());
        self::assertSame([], $request->getQueryParams());
        self::assertSame([], $request->getUploadedFiles());
        self::assertNull($request->getParsedBody());
        self::assertSame([], $request->getAttributes());
    }

    /**
     * Sécurité : la classe ne va rien chercher d'elle-même dans les variables
     * globales de PHP. Elle ne contient que ce qu'on lui a donné.
     */
    public function testItNeverReadsThePhpGlobals(): void
    {
        $previousGet = $_GET;
        $previousCookie = $_COOKIE;
        $_GET = ['page' => '2'];
        $_COOKIE = ['session' => 'abc'];

        try {
            $request = new ServerRequest('GET', '/?page=2');

            self::assertSame([], $request->getQueryParams());
            self::assertSame([], $request->getCookieParams());
            self::assertSame([], $request->getServerParams());
        } finally {
            $_GET = $previousGet;
            $_COOKIE = $previousCookie;
        }
    }

    // --- Ce que le navigateur a envoyé ------------------------------------

    public function testServerParamsComeFromTheConstructor(): void
    {
        $request = new ServerRequest('GET', '/', [], null, '1.1', ['REMOTE_ADDR' => '127.0.0.1']);

        self::assertSame(['REMOTE_ADDR' => '127.0.0.1'], $request->getServerParams());
    }

    public function testWithCookieParams(): void
    {
        $original = new ServerRequest('GET', '/');

        $copy = $original->withCookieParams(['session' => 'abc']);

        self::assertSame([], $original->getCookieParams());
        self::assertSame(['session' => 'abc'], $copy->getCookieParams());
    }

    public function testWithQueryParams(): void
    {
        $original = new ServerRequest('GET', '/');

        $copy = $original->withQueryParams(['page' => '2', 'tri' => ['date', 'titre']]);

        self::assertSame([], $original->getQueryParams());
        self::assertSame(['page' => '2', 'tri' => ['date', 'titre']], $copy->getQueryParams());
    }

    public function testWithQueryParamsDoesNotChangeTheUri(): void
    {
        $request = new ServerRequest('GET', '/articles?page=1')->withQueryParams(['page' => '2']);

        self::assertSame('page=1', $request->getUri()->getQuery());
    }

    public function testWithParsedBodyAcceptsAnArrayAnObjectOrNull(): void
    {
        $request = new ServerRequest('POST', '/');
        $object = new \stdClass();

        self::assertSame(['titre' => 'Bonjour'], $request->withParsedBody(['titre' => 'Bonjour'])->getParsedBody());
        self::assertSame($object, $request->withParsedBody($object)->getParsedBody());
        self::assertNull($request->withParsedBody(['a' => 1])->withParsedBody(null)->getParsedBody());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidParsedBodies(): iterable
    {
        yield 'texte' => ['titre=Bonjour'];
        yield 'nombre' => [42];
        yield 'booléen' => [false];
    }

    #[DataProvider('invalidParsedBodies')]
    public function testWithParsedBodyRejectsAnythingElse(mixed $data): void
    {
        $this->expectException(InvalidMessageException::class);

        self::callUnchecked(new ServerRequest('POST', '/'), 'withParsedBody', $data);
    }

    /**
     * Appelle une méthode comme le ferait un code sans analyse statique :
     * PHPStan refuserait, à raison, de laisser passer ces mauvais arguments.
     */
    private static function callUnchecked(object $object, string $method, mixed ...$arguments): mixed
    {
        return $object->{$method}(...$arguments);
    }

    // --- Fichiers envoyés --------------------------------------------------

    public function testWithUploadedFilesAcceptsNestedFiles(): void
    {
        $avatar = self::createStub(UploadedFileInterface::class);
        $photo = self::createStub(UploadedFileInterface::class);
        $files = ['avatar' => $avatar, 'photos' => [$photo, ['encore' => $photo]]];

        $request = new ServerRequest('POST', '/')->withUploadedFiles($files);

        self::assertSame($files, $request->getUploadedFiles());
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function invalidUploadedFiles(): iterable
    {
        yield 'chemin en texte' => [['avatar' => '/tmp/php123']];
        yield 'tableau brut de $_FILES' => [['avatar' => ['name' => 'a.png', 'tmp_name' => '/tmp/php123', 'error' => 0]]];
        yield 'null' => [['avatar' => null]];
        yield 'objet quelconque' => [['avatar' => new \stdClass()]];
    }

    /**
     * Sécurité : un chemin de fichier en texte, ou le tableau brut de $_FILES,
     * ne peuvent pas se faire passer pour un fichier envoyé.
     *
     * @param array<array-key, mixed> $files
     */
    #[DataProvider('invalidUploadedFiles')]
    public function testWithUploadedFilesRejectsAnythingThatIsNotAnUploadedFile(array $files): void
    {
        $this->expectException(InvalidMessageException::class);

        (void) new ServerRequest('POST', '/')->withUploadedFiles($files);
    }

    public function testTheUploadedFileErrorDoesNotRevealTheRejectedValue(): void
    {
        try {
            (void) new ServerRequest('POST', '/')->withUploadedFiles(['avatar' => '/chemin/secret']);
            self::fail('Une exception était attendue.');
        } catch (InvalidMessageException $exception) {
            self::assertStringNotContainsString('/chemin/secret', $exception->getMessage());
            self::assertStringContainsString('string', $exception->getMessage());
        }
    }

    // --- Attributs ---------------------------------------------------------

    public function testWithAttributeAddsAnAttribute(): void
    {
        $original = new ServerRequest('GET', '/articles/42');

        $copy = $original->withAttribute('id', 42)->withAttribute('slug', 'bonjour');

        self::assertSame([], $original->getAttributes());
        self::assertSame(['id' => 42, 'slug' => 'bonjour'], $copy->getAttributes());
        self::assertSame(42, $copy->getAttribute('id'));
    }

    public function testGetAttributeReturnsTheDefaultWhenMissing(): void
    {
        $request = new ServerRequest('GET', '/');

        self::assertNull($request->getAttribute('absent'));
        self::assertSame('défaut', $request->getAttribute('absent', 'défaut'));
    }

    public function testAnAttributeSetToNullExists(): void
    {
        $request = new ServerRequest('GET', '/')->withAttribute('utilisateur', null);

        self::assertNull($request->getAttribute('utilisateur', 'défaut'));
    }

    public function testWithoutAttributeRemovesAnAttribute(): void
    {
        $request = new ServerRequest('GET', '/')
            ->withAttribute('id', 42)
            ->withAttribute('slug', 'bonjour')
            ->withoutAttribute('id')
            ->withoutAttribute('absent');

        self::assertSame(['slug' => 'bonjour'], $request->getAttributes());
    }

    // --- Immuabilité -------------------------------------------------------

    /**
     * @return iterable<string, array{\Closure(ServerRequest): mixed}>
     */
    public static function witherCalls(): iterable
    {
        yield 'withCookieParams' => [static fn(ServerRequest $request) => $request->withCookieParams([])];
        yield 'withQueryParams' => [static fn(ServerRequest $request) => $request->withQueryParams([])];
        yield 'withUploadedFiles' => [static fn(ServerRequest $request) => $request->withUploadedFiles([])];
        yield 'withParsedBody' => [static fn(ServerRequest $request) => $request->withParsedBody(null)];
        yield 'withAttribute' => [static fn(ServerRequest $request) => $request->withAttribute('a', 1)];
        yield 'withoutAttribute' => [static fn(ServerRequest $request) => $request->withoutAttribute('a')];
        yield 'withMethod' => [static fn(ServerRequest $request) => $request->withMethod('POST')];
        yield 'withHeader' => [static fn(ServerRequest $request) => $request->withHeader('X-Test', 'a')];
    }

    /**
     * @param \Closure(ServerRequest): mixed $wither
     */
    #[DataProvider('witherCalls')]
    public function testEveryWitherReturnsANewInstance(\Closure $wither): void
    {
        $original = new ServerRequest('GET', '/');

        $copy = $wither($original);

        self::assertInstanceOf(ServerRequest::class, $copy);
        self::assertNotSame($original, $copy);
    }
}
