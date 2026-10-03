<?php

declare(strict_types=1);

namespace Wazi\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wazi\Http\Exception\InvalidMessageException;
use Wazi\Http\Response;
use Wazi\Http\Stream;

/**
 * MessageTrait est partagé par Request, ServerRequest et Response.
 * Il est testé ici à travers Response, le message le plus simple.
 */
final class MessageTraitTest extends TestCase
{
    // --- Version du protocole ---------------------------------------------

    public function testTheDefaultProtocolVersionIs11(): void
    {
        self::assertSame('1.1', new Response()->getProtocolVersion());
    }

    public function testWithProtocolVersionChangesTheVersion(): void
    {
        self::assertSame('2', new Response()->withProtocolVersion('2')->getProtocolVersion());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidProtocolVersions(): iterable
    {
        yield 'vide' => [''];
        yield 'avec le préfixe' => ['HTTP/1.1'];
        yield 'texte' => ['deux'];
        yield 'injection' => ["1.1\r\nSet-Cookie: session=piege"];
        yield 'retour à la ligne final' => ["1.1\n"];
    }

    #[DataProvider('invalidProtocolVersions')]
    public function testItRejectsAnInvalidProtocolVersion(string $version): void
    {
        $this->expectException(InvalidMessageException::class);

        (void) new Response()->withProtocolVersion($version);
    }

    public function testTheConstructorRejectsAnInvalidProtocolVersion(): void
    {
        $this->expectException(InvalidMessageException::class);

        new Response(200, [], null, 'HTTP/1.1');
    }

    // --- Lecture des en-têtes ---------------------------------------------

    public function testHeaderNamesAreCaseInsensitive(): void
    {
        $response = new Response(200, ['Content-Type' => 'text/html']);

        self::assertTrue($response->hasHeader('content-type'));
        self::assertSame(['text/html'], $response->getHeader('CONTENT-TYPE'));
        self::assertSame('text/html', $response->getHeaderLine('Content-type'));
    }

    public function testGetHeadersKeepsTheNamesAsWritten(): void
    {
        $response = new Response(200, ['X-Mon-Entete' => 'a', 'content-type' => 'text/html']);

        self::assertSame(['X-Mon-Entete' => ['a'], 'content-type' => ['text/html']], $response->getHeaders());
    }

    public function testAMissingHeaderGivesEmptyValues(): void
    {
        $response = new Response();

        self::assertFalse($response->hasHeader('X-Absent'));
        self::assertSame([], $response->getHeader('X-Absent'));
        self::assertSame('', $response->getHeaderLine('X-Absent'));
    }

    public function testGetHeaderLineJoinsValuesWithCommas(): void
    {
        $response = new Response(200, ['Accept' => ['text/html', 'application/json']]);

        self::assertSame('text/html, application/json', $response->getHeaderLine('Accept'));
    }

    public function testTheConstructorMergesNamesThatOnlyDifferByCase(): void
    {
        $response = new Response(200, ['Accept' => 'text/html', 'accept' => 'application/json']);

        self::assertSame(['Accept' => ['text/html', 'application/json']], $response->getHeaders());
    }

    public function testAnIntegerValueIsAcceptedAsText(): void
    {
        self::assertSame(['42'], new Response(200, ['Content-Length' => 42])->getHeader('Content-Length'));
    }

    public function testSpacesAroundAValueAreRemoved(): void
    {
        self::assertSame('text/html', new Response(200, ['Content-Type' => "  text/html\t"])->getHeaderLine('Content-Type'));
    }

    // --- Modification des en-têtes ----------------------------------------

    public function testWithHeaderReplacesTheValuesWhateverTheCase(): void
    {
        $response = new Response(200, ['Content-Type' => 'text/html'])
            ->withHeader('content-type', 'application/json');

        self::assertSame(['content-type' => ['application/json']], $response->getHeaders());
    }

    public function testWithAddedHeaderKeepsTheExistingValues(): void
    {
        $response = new Response(200, ['Accept' => 'text/html'])
            ->withAddedHeader('accept', ['application/json', 'text/plain']);

        self::assertSame(['Accept' => ['text/html', 'application/json', 'text/plain']], $response->getHeaders());
    }

    public function testWithAddedHeaderCreatesTheHeaderWhenMissing(): void
    {
        self::assertSame(['a'], new Response()->withAddedHeader('X-Nouveau', 'a')->getHeader('X-Nouveau'));
    }

    public function testWithoutHeaderRemovesTheHeaderWhateverTheCase(): void
    {
        $response = new Response(200, ['Content-Type' => 'text/html', 'X-Autre' => 'a'])
            ->withoutHeader('CONTENT-TYPE');

        self::assertSame(['X-Autre' => ['a']], $response->getHeaders());
        self::assertFalse($response->hasHeader('Content-Type'));
    }

    public function testWithoutHeaderOnAMissingHeaderChangesNothing(): void
    {
        $response = new Response(200, ['X-Autre' => 'a'])->withoutHeader('X-Absent');

        self::assertSame(['X-Autre' => ['a']], $response->getHeaders());
    }

    // --- Sécurité : injection d'en-têtes -----------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidHeaderNames(): iterable
    {
        yield 'vide' => [''];
        yield 'espace' => ['Mon Entete'];
        yield 'deux-points' => ['Content-Type:'];
        yield 'injection' => ["X-Test\r\nSet-Cookie"];
        yield 'retour à la ligne final' => ["X-Test\n"];
        yield 'octet nul' => ["X-Test\0"];
        yield 'accent' => ['X-Entête'];
    }

    #[DataProvider('invalidHeaderNames')]
    public function testItRejectsAnInvalidHeaderName(string $name): void
    {
        $this->expectException(InvalidMessageException::class);

        (void) new Response()->withHeader($name, 'valeur');
    }

    #[DataProvider('invalidHeaderNames')]
    public function testTheConstructorRejectsAnInvalidHeaderName(string $name): void
    {
        $this->expectException(InvalidMessageException::class);

        new Response(200, [$name => 'valeur']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function headerValuesThatWouldInjectContent(): iterable
    {
        yield 'faux en-tête' => ["text/html\r\nSet-Cookie: session=piege"];
        yield 'faux corps' => ["text/html\r\n\r\n<script>alert(1)</script>"];
        yield 'saut de ligne seul' => ["a\nb"];
        yield 'retour chariot seul' => ["a\rb"];
        yield 'retour à la ligne final' => ["valeur\n"];
        yield 'octet nul' => ["a\0b"];
        yield 'caractère de contrôle' => ["a\x7Fb"];
    }

    #[DataProvider('headerValuesThatWouldInjectContent')]
    public function testWithHeaderRejectsAValueThatWouldInjectContent(string $value): void
    {
        $this->expectException(InvalidMessageException::class);

        (void) new Response()->withHeader('X-Test', $value);
    }

    #[DataProvider('headerValuesThatWouldInjectContent')]
    public function testWithAddedHeaderRejectsAValueThatWouldInjectContent(string $value): void
    {
        $this->expectException(InvalidMessageException::class);

        (void) new Response(200, ['X-Test' => 'a'])->withAddedHeader('X-Test', $value);
    }

    #[DataProvider('headerValuesThatWouldInjectContent')]
    public function testTheConstructorRejectsAValueThatWouldInjectContent(string $value): void
    {
        $this->expectException(InvalidMessageException::class);

        new Response(200, ['X-Test' => ['valide', $value]]);
    }

    /**
     * La valeur d'un en-tête peut être un secret (jeton, cookie) : elle ne
     * doit pas se retrouver dans un message d'erreur, donc dans un journal.
     */
    public function testTheErrorMessageNeverContainsTheHeaderValue(): void
    {
        try {
            (void) new Response()->withHeader('Authorization', "Bearer jeton-secret\n");
            self::fail('Une exception était attendue.');
        } catch (InvalidMessageException $exception) {
            self::assertStringContainsString('Authorization', $exception->getMessage());
            self::assertStringNotContainsString('jeton-secret', $exception->getMessage());
        }
    }

    public function testTheErrorMessageCannotBeUsedToForgeLogLines(): void
    {
        try {
            (void) new Response()->withHeader("X-Test\nFAUSSE LIGNE DE JOURNAL", 'valeur');
            self::fail('Une exception était attendue.');
        } catch (InvalidMessageException $exception) {
            self::assertStringNotContainsString("\n", $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function headerValuesOfTheWrongType(): iterable
    {
        yield 'null' => [null];
        yield 'nombre à virgule' => [1.5];
        yield 'booléen' => [true];
        yield 'objet' => [new \stdClass()];
        yield 'liste vide' => [[]];
        yield 'liste contenant null' => [['valide', null]];
        yield 'liste imbriquée' => [[['a']]];
    }

    #[DataProvider('headerValuesOfTheWrongType')]
    public function testItRejectsAHeaderValueOfTheWrongType(mixed $value): void
    {
        $this->expectException(InvalidMessageException::class);

        self::callUnchecked(new Response(), 'withHeader', 'X-Test', $value);
    }

    /**
     * Appelle une méthode comme le ferait un code sans analyse statique :
     * PHPStan refuserait, à raison, de laisser passer ces mauvais arguments.
     */
    private static function callUnchecked(object $object, string $method, mixed ...$arguments): mixed
    {
        return $object->{$method}(...$arguments);
    }

    public function testTheExceptionIsAnInvalidArgumentExceptionAsPsr7Requires(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (void) new Response()->withHeader('Mon Entete', 'valeur');
    }

    // --- Corps -------------------------------------------------------------

    public function testTheDefaultBodyIsEmpty(): void
    {
        self::assertSame('', (string) new Response()->getBody());
    }

    public function testATextBodyIsWrappedInAStream(): void
    {
        self::assertSame('Bonjour', (string) new Response(200, [], 'Bonjour')->getBody());
    }

    public function testAStreamBodyIsKeptAsIs(): void
    {
        $body = Stream::fromString('Bonjour');

        self::assertSame($body, new Response(200, [], $body)->getBody());
    }

    public function testWithBodyReplacesTheBody(): void
    {
        $body = Stream::fromString('Nouveau');

        self::assertSame($body, new Response(200, [], 'Ancien')->withBody($body)->getBody());
    }

    // --- Immuabilité -------------------------------------------------------

    public function testWithersReturnANewMessageAndLeaveTheOriginalUntouched(): void
    {
        $original = new Response(200, ['X-Test' => 'a']);

        $copy = $original->withHeader('X-Test', 'b');

        self::assertNotSame($original, $copy);
        self::assertSame('a', $original->getHeaderLine('X-Test'));
        self::assertSame('b', $copy->getHeaderLine('X-Test'));
    }

    /**
     * @return iterable<string, array{\Closure(Response): mixed}>
     */
    public static function witherCalls(): iterable
    {
        yield 'withProtocolVersion' => [static fn(Response $response) => $response->withProtocolVersion('2')];
        yield 'withHeader' => [static fn(Response $response) => $response->withHeader('X-Test', 'a')];
        yield 'withAddedHeader' => [static fn(Response $response) => $response->withAddedHeader('X-Test', 'a')];
        yield 'withoutHeader' => [static fn(Response $response) => $response->withoutHeader('X-Test')];
        yield 'withBody' => [static fn(Response $response) => $response->withBody(Stream::fromString())];
    }

    /**
     * @param \Closure(Response): mixed $wither
     */
    #[DataProvider('witherCalls')]
    public function testEveryWitherReturnsANewInstance(\Closure $wither): void
    {
        $original = new Response();

        self::assertNotSame($original, $wither($original));
    }

    /**
     * L'erreur la plus fréquente avec un objet immuable : appeler
     * $response->withHeader(...) sans récupérer le résultat.
     * Grâce à #[\NoDiscard], PHP avertit le développeur.
     */
    public function testIgnoringTheResultOfAWitherTriggersAWarning(): void
    {
        $response = new Response();
        $method = 'withHeader';
        $warning = '';

        set_error_handler(static function (int $level, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            $response->{$method}('X-Test', 'a');
        } finally {
            restore_error_handler();
        }

        self::assertStringContainsString('immuable', $warning);
    }
}
