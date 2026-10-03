<?php

declare(strict_types=1);

namespace Wazi\Tests\Middleware;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Wazi\Http\Exception\InvalidMessageException;
use Wazi\Http\Response;
use Wazi\Http\ServerRequest;
use Wazi\Middleware\SecurityHeaders;

final class SecurityHeadersTest extends TestCase
{
    public function testItIsAPsr15Middleware(): void
    {
        self::assertInstanceOf(MiddlewareInterface::class, new SecurityHeaders());
    }

    public function testItAddsTheProtectiveHeadersToTheResponse(): void
    {
        $response = $this->process(new SecurityHeaders(), new Response(200, ['Content-Type' => 'text/html'], 'page'));

        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        self::assertSame('strict-origin-when-cross-origin', $response->getHeaderLine('Referrer-Policy'));
        self::assertSame(SecurityHeaders::DEFAULT_CONTENT_SECURITY_POLICY, $response->getHeaderLine('Content-Security-Policy'));
    }

    public function testItLeavesTheRestOfTheResponseUntouched(): void
    {
        $response = $this->process(new SecurityHeaders(), new Response(201, ['Content-Type' => 'text/html'], 'page'));

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('text/html', $response->getHeaderLine('Content-Type'));
        self::assertSame('page', (string) $response->getBody());
    }

    /**
     * La politique par défaut doit refuser les scripts injectés dans la page :
     * c'est sa raison d'être.
     */
    public function testTheDefaultPolicyForbidsInlineAndForeignScripts(): void
    {
        $policy = SecurityHeaders::DEFAULT_CONTENT_SECURITY_POLICY;

        self::assertStringContainsString("default-src 'self'", $policy);
        self::assertStringContainsString("object-src 'none'", $policy);
        self::assertStringContainsString("frame-ancestors 'none'", $policy);
        self::assertStringNotContainsString('script-src', $policy, 'Les scripts suivent default-src : votre site uniquement.');
        self::assertStringNotContainsString('unsafe-eval', $policy);
        self::assertSame(1, substr_count($policy, 'unsafe-inline'), 'Seuls les styles écrits dans la page sont permis.');
        self::assertStringContainsString("style-src 'self' 'unsafe-inline'", $policy);
        self::assertStringNotContainsString('*', $policy);
    }

    /**
     * Un en-tête posé par le contrôleur est une exception locale et voulue :
     * il n'est pas remplacé.
     */
    public function testAHeaderAlreadySetByTheControllerIsKept(): void
    {
        $fromController = new Response(200, [
            'content-security-policy' => "default-src 'self'; script-src 'self' https://cdn.exemple.com",
            'X-Frame-Options' => 'SAMEORIGIN',
        ]);

        $response = $this->process(new SecurityHeaders(), $fromController);

        self::assertSame("default-src 'self'; script-src 'self' https://cdn.exemple.com", $response->getHeaderLine('Content-Security-Policy'));
        self::assertSame('SAMEORIGIN', $response->getHeaderLine('X-Frame-Options'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'), 'Les autres sont ajoutés.');
    }

    public function testACustomPolicyReplacesTheDefaultOne(): void
    {
        $response = $this->process(new SecurityHeaders("default-src 'none'"), new Response());

        self::assertSame("default-src 'none'", $response->getHeaderLine('Content-Security-Policy'));
    }

    public function testNullRemovesThePolicyButKeepsTheOtherHeaders(): void
    {
        $response = $this->process(new SecurityHeaders(null), new Response());

        self::assertFalse($response->hasHeader('Content-Security-Policy'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    public function testAnInvalidPolicyIsRejectedWhenTheMiddlewareIsCreated(): void
    {
        $this->expectException(InvalidMessageException::class);

        new SecurityHeaders("default-src 'self'\r\nSet-Cookie: session=piege");
    }

    private function process(SecurityHeaders $middleware, ResponseInterface $response): ResponseInterface
    {
        $handler = new class ($response) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseInterface $response) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };

        return $middleware->process(new ServerRequest('GET', '/'), $handler);
    }
}
