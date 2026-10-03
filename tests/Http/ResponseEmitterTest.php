<?php

declare(strict_types=1);

namespace Wazi\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Wazi\Http\Exception\EmitterException;
use Wazi\Http\Response;
use Wazi\Http\ResponseEmitter;
use Wazi\Http\Stream;
use Wazi\Tests\Http\Fixtures\FakeSapi;

require_once __DIR__ . '/Fixtures/sapi_functions.php';

/**
 * header() et headers_sent() sont remplacés pendant ces tests : voir FakeSapi.
 * Le corps, lui, est réellement affiché par echo, et capturé par PHPUnit.
 */
final class ResponseEmitterTest extends TestCase
{
    protected function setUp(): void
    {
        FakeSapi::reset();
    }

    protected function tearDown(): void
    {
        FakeSapi::reset();
    }

    // --- Envoi -------------------------------------------------------------

    public function testItSendsHeadersStatusLineAndBody(): void
    {
        $this->expectOutputString('<h1>Bonjour</h1>');

        new ResponseEmitter()->emit(new Response(200, ['Content-Type' => 'text/html'], '<h1>Bonjour</h1>'));

        self::assertSame(['Content-Type: text/html', 'HTTP/1.1 200 OK'], FakeSapi::headerLines());
    }

    public function testTheStatusLineCarriesTheCodeAndIsSentLast(): void
    {
        new ResponseEmitter()->emit(new Response(302, ['Location' => '/connexion']));

        self::assertSame(
            [['Location: /connexion', true, 0], ['HTTP/1.1 302 Found', true, 302]],
            FakeSapi::$headers,
        );
    }

    public function testTheStatusLineUsesTheProtocolVersionAndACustomPhrase(): void
    {
        new ResponseEmitter()->emit(new Response(404, [], null, '2', 'Introuvable'));

        self::assertSame(['HTTP/2 404 Introuvable'], FakeSapi::headerLines());
    }

    public function testAnUnknownCodeHasNoReasonPhrase(): void
    {
        new ResponseEmitter()->emit(new Response(599));

        self::assertSame(['HTTP/1.1 599'], FakeSapi::headerLines());
    }

    public function testTheFirstValueOfAHeaderReplacesAndTheOthersAreAdded(): void
    {
        new ResponseEmitter()->emit(new Response(200, ['Vary' => ['Accept', 'Cookie']]));

        self::assertSame(['Vary: Accept', true, 0], FakeSapi::$headers[0]);
        self::assertSame(['Vary: Cookie', false, 0], FakeSapi::$headers[1]);
    }

    /**
     * Sécurité : remplacer un Set-Cookie effacerait le cookie de session
     * que PHP a peut-être déjà préparé.
     */
    public function testSetCookieNeverReplacesAnExistingCookie(): void
    {
        new ResponseEmitter()->emit(new Response(200, ['set-cookie' => ['a=1', 'b=2']]));

        self::assertSame(['set-cookie: a=1', false, 0], FakeSapi::$headers[0]);
        self::assertSame(['set-cookie: b=2', false, 0], FakeSapi::$headers[1]);
    }

    // --- Corps -------------------------------------------------------------

    public function testTheBodyIsSentFromItsBeginning(): void
    {
        $body = Stream::fromString('Bonjour');
        $body->seek(3);

        $this->expectOutputString('Bonjour');

        new ResponseEmitter()->emit(new Response(200, [], $body));
    }

    public function testABodyLargerThanOneChunkIsSentEntirely(): void
    {
        $content = str_repeat('0123456789', 5000);

        $this->expectOutputString($content);

        new ResponseEmitter()->emit(new Response(200, [], $content));
    }

    public function testNoBodyIsSentForAHeadRequest(): void
    {
        $this->expectOutputString('');

        new ResponseEmitter()->emit(new Response(200, ['Content-Length' => 7], 'Bonjour'), false);

        self::assertSame(['Content-Length: 7', 'HTTP/1.1 200 OK'], FakeSapi::headerLines());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function statusCodesWithoutBody(): iterable
    {
        yield '100' => [100];
        yield '103' => [103];
        yield '204' => [204];
        yield '304' => [304];
    }

    #[DataProvider('statusCodesWithoutBody')]
    public function testNoBodyIsSentForAStatusThatForbidsIt(int $statusCode): void
    {
        $this->expectOutputString('');

        new ResponseEmitter()->emit(new Response($statusCode, [], 'ne doit pas partir'));
    }

    public function testAnUnreadableBodyIsSkipped(): void
    {
        $body = Stream::fromString('Bonjour');
        $body->close();

        $this->expectOutputString('');

        new ResponseEmitter()->emit(new Response(200, [], $body));

        self::assertSame(['HTTP/1.1 200 OK'], FakeSapi::headerLines());
    }

    // --- Page déjà commencée ----------------------------------------------

    public function testItRefusesToEmitWhenHeadersWereAlreadySent(): void
    {
        FakeSapi::$sentAt = ['/var/www/src/HomeController.php', 12];

        try {
            new ResponseEmitter()->emit(new Response(200, ['X-Test' => 'a'], 'Bonjour'));
            self::fail('Une exception était attendue.');
        } catch (EmitterException $exception) {
            self::assertStringContainsString('/var/www/src/HomeController.php', $exception->getMessage());
            self::assertStringContainsString('ligne 12', $exception->getMessage());
            self::assertStringContainsString('echo', $exception->getMessage());
        }

        self::assertSame([], FakeSapi::$headers, 'Rien ne doit partir.');
    }

    public function testItRefusesToEmitWhenSomethingWaitsInTheOutputBuffer(): void
    {
        $this->expectOutputString('déjà affiché');

        echo 'déjà affiché';

        try {
            new ResponseEmitter()->emit(new Response(200, [], 'Bonjour'));
            self::fail('Une exception était attendue.');
        } catch (EmitterException $exception) {
            self::assertStringContainsString('tampon de sortie', $exception->getMessage());
        }

        self::assertSame([], FakeSapi::$headers, 'Rien ne doit partir.');
    }

    // --- Sécurité : réponse venue d'une autre bibliothèque ------------------

    /**
     * @return iterable<string, array{array<string, list<string>>}>
     */
    public static function unsafeHeaders(): iterable
    {
        yield 'valeur avec faux en-tête' => [['X-Test' => ["a\r\nSet-Cookie: session=piege"]]];
        yield 'seconde valeur avec saut de ligne' => [['X-Test' => ['a', "b\nc"]]];
        yield 'valeur avec octet nul' => [['X-Test' => ["a\0b"]]];
        yield 'nom avec retour à la ligne' => [["X-Test\r\nSet-Cookie" => ['a']]];
        yield 'nom avec deux-points' => [['X-Test: a' => ['b']]];
        yield 'nom vide' => [['' => ['a']]];
    }

    /**
     * @param array<string, list<string>> $headers
     */
    #[DataProvider('unsafeHeaders')]
    public function testItRefusesAForeignResponseWithAnUnsafeHeader(array $headers): void
    {
        $response = $this->foreignResponse(['Content-Type' => ['text/html']] + $headers);

        $this->expectOutputString('');

        try {
            new ResponseEmitter()->emit($response);
            self::fail('Une exception était attendue.');
        } catch (EmitterException $exception) {
            self::assertStringNotContainsString("\n", $exception->getMessage());
            self::assertStringNotContainsString('piege', $exception->getMessage());
        }

        self::assertSame([], FakeSapi::$headers, 'Aucun en-tête ne doit partir, pas même les valides.');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unsafeStatusLines(): iterable
    {
        yield 'phrase de statut' => ['1.1', "OK\r\nSet-Cookie: session=piege"];
        yield 'version du protocole' => ["1.1\r\nSet-Cookie: session=piege", 'OK'];
    }

    #[DataProvider('unsafeStatusLines')]
    public function testItRefusesAForeignResponseWithAnUnsafeStatusLine(string $protocolVersion, string $reasonPhrase): void
    {
        $response = $this->foreignResponse(['Content-Type' => ['text/html']], $protocolVersion, $reasonPhrase);

        $this->expectException(EmitterException::class);

        new ResponseEmitter()->emit($response);
    }

    public function testItEmitsAValidForeignResponse(): void
    {
        new ResponseEmitter()->emit($this->foreignResponse(['Content-Type' => ['text/html']]));

        self::assertSame(['Content-Type: text/html', 'HTTP/1.1 200 OK'], FakeSapi::headerLines());
    }

    // --- Outils ------------------------------------------------------------

    /**
     * Une réponse PSR-7 qui ne vient pas de Wazi, et n'a donc pas été validée par lui.
     *
     * @param array<string, list<string>> $headers
     */
    private function foreignResponse(array $headers, string $protocolVersion = '1.1', string $reasonPhrase = 'OK'): ResponseInterface
    {
        $response = self::createStub(ResponseInterface::class);
        $response->method('getHeaders')->willReturn($headers);
        $response->method('getProtocolVersion')->willReturn($protocolVersion);
        $response->method('getReasonPhrase')->willReturn($reasonPhrase);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn(Stream::fromString());

        return $response;
    }
}
