<?php

declare(strict_types=1);

namespace Wazi\Tests\Middleware;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Wazi\Http\Exception\InvalidMessageException;
use Wazi\Http\Response;
use Wazi\Http\ServerRequest;
use Wazi\Middleware\Exception\InvalidSecurityPolicyException;
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

    // --- La politique par défaut : stricte pour les scripts seulement --------

    /**
     * Sécurité : c'est la raison d'être de la politique. Un script injecté
     * dans la page, ou chargé depuis un site inconnu, ne doit pas s'exécuter.
     */
    public function testTheDefaultPolicyOnlyAllowsScriptsFromYourOwnSite(): void
    {
        $directives = self::directives(SecurityHeaders::DEFAULT_CONTENT_SECURITY_POLICY);

        self::assertSame("'self'", $directives['script-src']);
        self::assertSame("'self'", $directives['default-src']);
        self::assertSame("'none'", $directives['object-src']);
        self::assertSame("'none'", $directives['frame-ancestors']);
        self::assertSame("'self'", $directives['base-uri']);
        self::assertSame("'self'", $directives['form-action']);
        self::assertStringNotContainsString('unsafe-eval', SecurityHeaders::DEFAULT_CONTENT_SECURITY_POLICY);
        self::assertStringNotContainsString('*', SecurityHeaders::DEFAULT_CONTENT_SECURITY_POLICY);
        self::assertStringNotContainsString('http:', SecurityHeaders::DEFAULT_CONTENT_SECURITY_POLICY);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function harmlessResources(): iterable
    {
        yield 'feuilles de style (Bootstrap depuis un CDN)' => ['style-src'];
        yield 'polices (Google Fonts)' => ['font-src'];
        yield 'images d\'un autre site' => ['img-src'];
        yield 'vidéos et sons' => ['media-src'];
        yield 'cadres (une vidéo YouTube)' => ['frame-src'];
        yield 'appels vers une API' => ['connect-src'];
    }

    /**
     * Ce qui ne présente presque aucun risque fonctionne sans rien régler.
     */
    #[DataProvider('harmlessResources')]
    public function testHarmlessResourcesAreAllowedFromAnyHttpsSite(string $directive): void
    {
        $sources = explode(' ', self::directives(SecurityHeaders::DEFAULT_CONTENT_SECURITY_POLICY)[$directive]);

        self::assertContains("'self'", $sources);
        self::assertContains('https:', $sources);
    }

    public function testInlineStylesAreAllowedButInlineScriptsAreNot(): void
    {
        $directives = self::directives(SecurityHeaders::DEFAULT_CONTENT_SECURITY_POLICY);

        self::assertStringContainsString("'unsafe-inline'", $directives['style-src']);
        self::assertSame(1, substr_count(SecurityHeaders::DEFAULT_CONTENT_SECURITY_POLICY, 'unsafe-inline'));
    }

    // --- Autoriser les scripts d'un autre site -----------------------------

    public function testScriptSourcesAreAddedToTheScriptDirectiveOnly(): void
    {
        $middleware = new SecurityHeaders(scripts: ['https://cdn.jsdelivr.net', 'https://unpkg.com/htmx.org@2']);

        $policy = $this->process($middleware, new Response())->getHeaderLine('Content-Security-Policy');
        $directives = self::directives($policy);
        $defaults = self::directives(SecurityHeaders::DEFAULT_CONTENT_SECURITY_POLICY);

        self::assertSame("'self' https://cdn.jsdelivr.net https://unpkg.com/htmx.org@2", $directives['script-src']);

        unset($directives['script-src'], $defaults['script-src']);

        self::assertSame($defaults, $directives, 'Le reste de la politique ne change pas.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedScriptSources(): iterable
    {
        yield 'scripts écrits dans la page' => ["'unsafe-inline'"];
        yield 'eval' => ["'unsafe-eval'"];
        yield 'joker' => ['*'];
        yield 'joker de sous-domaine' => ['https://*.exemple.com'];
        yield 'tout site en https' => ['https:'];
        yield 'site en http' => ['http://cdn.exemple.com'];
        yield 'scripts en data:' => ['data:'];
        yield 'sans protocole' => ['cdn.jsdelivr.net'];
        yield 'injection d\'une autre règle' => ["https://cdn.exemple.com; script-src 'unsafe-inline'"];
        yield 'deux sources en une' => ['https://cdn.exemple.com https://pirate.com'];
        yield 'virgule' => ['https://cdn.exemple.com,https://pirate.com'];
        yield 'retour à la ligne' => ["https://cdn.exemple.com\r\nSet-Cookie: a=1"];
        yield 'avec identifiants' => ['https://alice:secret@cdn.exemple.com'];
        yield 'vide' => [''];
    }

    /**
     * Sécurité : l'argument « scripts » ne doit pas pouvoir servir à annuler
     * la protection par mégarde.
     */
    #[DataProvider('refusedScriptSources')]
    public function testAScriptSourceThatWouldWeakenThePolicyIsRefused(string $source): void
    {
        try {
            new SecurityHeaders(scripts: ['https://cdn.jsdelivr.net', $source]);
            self::fail('Une exception était attendue.');
        } catch (InvalidSecurityPolicyException $exception) {
            self::assertStringContainsString('https://cdn.jsdelivr.net', $exception->getMessage());
            self::assertStringNotContainsString("\n", $exception->getMessage());
        }
    }

    // --- Politique écrite à la main ----------------------------------------

    public function testACustomPolicyReplacesTheDefaultOne(): void
    {
        $middleware = new SecurityHeaders(contentSecurityPolicy: "default-src 'none'");

        self::assertSame("default-src 'none'", $this->process($middleware, new Response())->getHeaderLine('Content-Security-Policy'));
    }

    public function testScriptSourcesAndACustomPolicyCannotBeGivenTogether(): void
    {
        $this->expectException(InvalidSecurityPolicyException::class);

        new SecurityHeaders(scripts: ['https://cdn.jsdelivr.net'], contentSecurityPolicy: "default-src 'self'");
    }

    /**
     * Retirer la politique demande un geste nommé.
     */
    public function testThePolicyCanOnlyBeRemovedExplicitly(): void
    {
        $response = $this->process(new SecurityHeaders(contentSecurityPolicy: SecurityHeaders::WITHOUT_POLICY), new Response());

        self::assertFalse($response->hasHeader('Content-Security-Policy'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'), 'Les autres en-têtes restent.');
    }

    public function testAnInvalidPolicyIsRejectedWhenTheMiddlewareIsCreated(): void
    {
        $this->expectException(InvalidMessageException::class);

        new SecurityHeaders(contentSecurityPolicy: "default-src 'self'\r\nSet-Cookie: session=piege");
    }

    // --- Exception locale, page par page -----------------------------------

    /**
     * Un en-tête posé par le contrôleur est une exception locale et voulue :
     * il n'est pas remplacé.
     */
    public function testAHeaderAlreadySetByTheControllerIsKept(): void
    {
        $fromController = new Response(200, [
            'content-security-policy' => "default-src 'self'; script-src 'self' 'unsafe-inline'",
            'X-Frame-Options' => 'SAMEORIGIN',
        ]);

        $response = $this->process(new SecurityHeaders(), $fromController);

        self::assertSame("default-src 'self'; script-src 'self' 'unsafe-inline'", $response->getHeaderLine('Content-Security-Policy'));
        self::assertSame('SAMEORIGIN', $response->getHeaderLine('X-Frame-Options'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'), 'Les autres sont ajoutés.');
    }

    // --- Outils ------------------------------------------------------------

    /**
     * Découpe une politique en directives : ['script-src' => "'self'", ...].
     *
     * @return array<string, string>
     */
    private static function directives(string $policy): array
    {
        $directives = [];

        foreach (explode(';', $policy) as $directive) {
            [$name, $sources] = [...explode(' ', trim($directive), 2), ''];
            $directives[$name] = $sources;
        }

        return $directives;
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
