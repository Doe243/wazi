<?php

declare(strict_types=1);

namespace Wazi\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Wazi\Http\Exception\InvalidMessageException;
use Wazi\Http\Response;

/**
 * Les en-têtes, le corps et la version du protocole sont testés dans MessageTraitTest.
 */
final class ResponseTest extends TestCase
{
    public function testItImplementsPsr7(): void
    {
        self::assertInstanceOf(ResponseInterface::class, new Response());
    }

    public function testTheDefaultResponseIs200Ok(): void
    {
        $response = new Response();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('OK', $response->getReasonPhrase());
    }

    public function testItIsBuiltInOneLine(): void
    {
        $response = new Response(404, ['Content-Type' => 'text/html'], '<h1>Introuvable</h1>');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('Not Found', $response->getReasonPhrase());
        self::assertSame('text/html', $response->getHeaderLine('Content-Type'));
        self::assertSame('<h1>Introuvable</h1>', (string) $response->getBody());
    }

    public function testWithStatusUsesTheStandardReasonPhrase(): void
    {
        $response = new Response()->withStatus(201);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('Created', $response->getReasonPhrase());
    }

    public function testWithStatusAcceptsACustomReasonPhrase(): void
    {
        self::assertSame('Introuvable', new Response()->withStatus(404, 'Introuvable')->getReasonPhrase());
    }

    public function testACustomReasonPhraseIsDroppedWhenTheStatusChanges(): void
    {
        $response = new Response(404, [], null, '1.1', 'Introuvable')->withStatus(500);

        self::assertSame('Internal Server Error', $response->getReasonPhrase());
    }

    public function testAnUnknownCodeHasAnEmptyReasonPhrase(): void
    {
        self::assertSame('', new Response(599)->getReasonPhrase());
    }

    public function testWithStatusLeavesTheOriginalUntouched(): void
    {
        $original = new Response();

        $copy = $original->withStatus(404);

        self::assertNotSame($original, $copy);
        self::assertSame(200, $original->getStatusCode());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidStatusCodes(): iterable
    {
        yield 'zéro' => [0];
        yield 'négatif' => [-1];
        yield 'juste en dessous' => [99];
        yield 'juste au-dessus' => [600];
        yield 'très grand' => [20000];
    }

    #[DataProvider('invalidStatusCodes')]
    public function testWithStatusRejectsAnInvalidCode(int $code): void
    {
        $this->expectException(InvalidMessageException::class);

        (void) new Response()->withStatus($code);
    }

    #[DataProvider('invalidStatusCodes')]
    public function testTheConstructorRejectsAnInvalidCode(int $code): void
    {
        $this->expectException(InvalidMessageException::class);

        new Response($code);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function reasonPhrasesThatWouldInjectContent(): iterable
    {
        yield 'faux en-tête' => ["OK\r\nSet-Cookie: session=piege"];
        yield 'saut de ligne seul' => ["OK\nX"];
        yield 'retour à la ligne final' => ["OK\n"];
        yield 'octet nul' => ["OK\0"];
    }

    #[DataProvider('reasonPhrasesThatWouldInjectContent')]
    public function testItRejectsAReasonPhraseThatWouldInjectContent(string $reasonPhrase): void
    {
        $this->expectException(InvalidMessageException::class);

        (void) new Response()->withStatus(200, $reasonPhrase);
    }

    public function testTheErrorMessageExplainsTheValidRange(): void
    {
        try {
            new Response(700);
            self::fail('Une exception était attendue.');
        } catch (InvalidMessageException $exception) {
            self::assertStringContainsString('700', $exception->getMessage());
            self::assertStringContainsString('100 et 599', $exception->getMessage());
        }
    }
}
