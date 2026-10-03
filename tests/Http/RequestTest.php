<?php

declare(strict_types=1);

namespace Wazi\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Wazi\Http\Exception\InvalidMessageException;
use Wazi\Http\Exception\InvalidUriException;
use Wazi\Http\Request;
use Wazi\Http\Uri;

/**
 * Les en-têtes, le corps et la version du protocole sont testés dans MessageTraitTest.
 */
final class RequestTest extends TestCase
{
    public function testItImplementsPsr7(): void
    {
        self::assertInstanceOf(RequestInterface::class, new Request('GET', '/'));
    }

    public function testItIsBuiltFromAMethodAndAnAddress(): void
    {
        $request = new Request('POST', 'https://exemple.com/articles', ['Content-Type' => 'application/json'], '{}');

        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://exemple.com/articles', (string) $request->getUri());
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('{}', (string) $request->getBody());
    }

    public function testItAcceptsAUriObject(): void
    {
        $uri = new Uri('https://exemple.com/');

        self::assertSame($uri, new Request('GET', $uri)->getUri());
    }

    public function testAnInvalidAddressIsRejected(): void
    {
        $this->expectException(InvalidUriException::class);

        new Request('GET', 'https://exemple.com/mon article');
    }

    // --- Méthode -----------------------------------------------------------

    public function testTheMethodCaseIsKept(): void
    {
        self::assertSame('get', new Request('get', '/')->getMethod());
    }

    public function testWithMethodChangesTheMethod(): void
    {
        $original = new Request('GET', '/');

        $copy = $original->withMethod('DELETE');

        self::assertSame('GET', $original->getMethod());
        self::assertSame('DELETE', $copy->getMethod());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidMethods(): iterable
    {
        yield 'vide' => [''];
        yield 'espace' => ['GET POST'];
        yield 'injection' => ["GET / HTTP/1.1\r\nHost: piege.com\r\n\r\nGET"];
        yield 'retour à la ligne final' => ["GET\n"];
    }

    #[DataProvider('invalidMethods')]
    public function testItRejectsAnInvalidMethod(string $method): void
    {
        $this->expectException(InvalidMessageException::class);

        new Request($method, '/');
    }

    #[DataProvider('invalidMethods')]
    public function testWithMethodRejectsAnInvalidMethod(string $method): void
    {
        $this->expectException(InvalidMessageException::class);

        (void) new Request('GET', '/')->withMethod($method);
    }

    // --- Cible de la requête ----------------------------------------------

    public function testTheRequestTargetIsThePathAndQueryOfTheUri(): void
    {
        self::assertSame('/articles?page=2', new Request('GET', 'https://exemple.com/articles?page=2#haut')->getRequestTarget());
    }

    public function testTheRequestTargetIsASlashWhenThePathIsEmpty(): void
    {
        self::assertSame('/', new Request('GET', 'https://exemple.com')->getRequestTarget());
        self::assertSame('/', new Request('GET', '')->getRequestTarget());
    }

    public function testWithRequestTargetOverridesTheUri(): void
    {
        $request = new Request('OPTIONS', 'https://exemple.com/articles')->withRequestTarget('*');

        self::assertSame('*', $request->getRequestTarget());
        self::assertSame('/articles', $request->getUri()->getPath(), "L'URI, elle, ne change pas.");
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidRequestTargets(): iterable
    {
        yield 'vide' => [''];
        yield 'espace' => ['/mon article'];
        yield 'injection' => ["/ HTTP/1.1\r\nHost: piege.com"];
        yield 'retour à la ligne final' => ["/articles\n"];
        yield 'tabulation' => ["/a\tb"];
    }

    #[DataProvider('invalidRequestTargets')]
    public function testWithRequestTargetRejectsAnInvalidTarget(string $requestTarget): void
    {
        $this->expectException(InvalidMessageException::class);

        (void) new Request('GET', '/')->withRequestTarget($requestTarget);
    }

    // --- En-tête Host ------------------------------------------------------

    public function testTheHostHeaderComesFromTheUri(): void
    {
        self::assertSame('exemple.com', new Request('GET', 'https://exemple.com/articles')->getHeaderLine('Host'));
    }

    public function testTheHostHeaderIncludesANonStandardPort(): void
    {
        self::assertSame('exemple.com:8443', new Request('GET', 'https://exemple.com:8443/')->getHeaderLine('Host'));
        self::assertSame('exemple.com', new Request('GET', 'https://exemple.com:443/')->getHeaderLine('Host'));
    }

    public function testNoHostHeaderIsAddedWithoutAHostInTheUri(): void
    {
        self::assertFalse(new Request('GET', '/articles')->hasHeader('Host'));
    }

    public function testAHostHeaderGivenExplicitlyIsKept(): void
    {
        $request = new Request('GET', 'https://exemple.com/', ['host' => 'autre.com']);

        self::assertSame(['host' => ['autre.com']], $request->getHeaders());
    }

    public function testTheHostHeaderIsTheFirstHeader(): void
    {
        $request = new Request('GET', 'https://exemple.com/', ['Accept' => 'text/html']);

        self::assertSame(['Host', 'Accept'], array_keys($request->getHeaders()));
    }

    public function testWithUriUpdatesTheHostHeader(): void
    {
        $request = new Request('GET', 'https://exemple.com/', ['Accept' => 'text/html'])
            ->withUri(new Uri('https://autre.com:8080/contact'));

        self::assertSame('autre.com:8080', $request->getHeaderLine('Host'));
        self::assertSame(['Host', 'Accept'], array_keys($request->getHeaders()));
        self::assertSame('/contact', $request->getRequestTarget());
    }

    public function testWithUriReplacesAHostHeaderWrittenInAnotherCase(): void
    {
        $request = new Request('GET', '/', ['host' => 'ancien.com'])->withUri(new Uri('https://nouveau.com/'));

        self::assertSame(['Host' => ['nouveau.com']], $request->getHeaders());
    }

    public function testWithUriKeepsTheHostHeaderWhenAskedTo(): void
    {
        $request = new Request('GET', 'https://exemple.com/')->withUri(new Uri('https://autre.com/'), true);

        self::assertSame('exemple.com', $request->getHeaderLine('Host'));
        self::assertSame('autre.com', $request->getUri()->getHost());
    }

    public function testWithUriSetsTheHostHeaderWhenThereIsNoneToPreserve(): void
    {
        $request = new Request('GET', '/')->withUri(new Uri('https://autre.com/'), true);

        self::assertSame('autre.com', $request->getHeaderLine('Host'));
    }

    public function testWithUriWithoutHostLeavesTheHostHeaderAlone(): void
    {
        $request = new Request('GET', 'https://exemple.com/')->withUri(new Uri('/contact'));

        self::assertSame('exemple.com', $request->getHeaderLine('Host'));
    }

    // --- Immuabilité -------------------------------------------------------

    /**
     * @return iterable<string, array{\Closure(Request): mixed}>
     */
    public static function witherCalls(): iterable
    {
        yield 'withMethod' => [static fn(Request $request) => $request->withMethod('POST')];
        yield 'withUri' => [static fn(Request $request) => $request->withUri(new Uri('/autre'))];
        yield 'withRequestTarget' => [static fn(Request $request) => $request->withRequestTarget('*')];
        yield 'withHeader' => [static fn(Request $request) => $request->withHeader('X-Test', 'a')];
    }

    /**
     * @param \Closure(Request): mixed $wither
     */
    #[DataProvider('witherCalls')]
    public function testEveryWitherReturnsANewInstance(\Closure $wither): void
    {
        $original = new Request('GET', 'https://exemple.com/');

        self::assertNotSame($original, $wither($original));
        self::assertSame('GET', $original->getMethod());
        self::assertSame('/', $original->getRequestTarget());
    }
}
