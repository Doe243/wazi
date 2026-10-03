<?php

declare(strict_types=1);

namespace Wazi\Tests\Middleware;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Wazi\Http\CsrfToken;
use Wazi\Http\Response;
use Wazi\Http\ServerRequest;
use Wazi\Middleware\CsrfCookie;

final class CsrfCookieTest extends TestCase
{
    private const string KNOWN = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    private CsrfToken $token;

    protected function setUp(): void
    {
        $this->token = new CsrfToken();
    }

    // --- Lecture du cookie ----------------------------------------------------

    public function testTheCookieOfTheVisitorBecomesTheTokenOfTheRequest(): void
    {
        $response = $this->handle(
            new ServerRequest('GET', 'http://exemple.com/notes')->withCookieParams(['csrf' => self::KNOWN]),
            fn(): string => $this->token->value(),
        );

        self::assertSame(self::KNOWN, (string) $response->getBody());
        self::assertFalse($response->hasHeader('Set-Cookie'), 'Le navigateur a déjà ce jeton.');
    }

    public function testAVisitorWhoSeesNoFormGetsNoCookie(): void
    {
        $response = $this->handle(new ServerRequest('GET', 'http://exemple.com/'), static fn(): string => 'Bonjour');

        self::assertFalse($response->hasHeader('Set-Cookie'));
        self::assertTrue($this->token->isStarted());
    }

    // --- Envoi du cookie ------------------------------------------------------

    public function testAPageThatNeedsATokenSendsTheCookie(): void
    {
        $response = $this->handle(new ServerRequest('GET', 'http://exemple.com/notes'), fn(): string => $this->token->value());

        self::assertSame(
            'csrf=' . $response->getBody() . '; Path=/; HttpOnly; SameSite=Lax',
            $response->getHeaderLine('Set-Cookie'),
        );
    }

    /**
     * Sécurité : en HTTPS, le préfixe « __Host- » interdit à un sous-domaine
     * ou à une page non chiffrée de fixer ce cookie à la place du site.
     */
    public function testOverHttpsTheCookieIsLockedToTheSite(): void
    {
        $response = $this->handle(new ServerRequest('GET', 'https://exemple.com/notes'), fn(): string => $this->token->value());

        self::assertSame(
            '__Host-csrf=' . $response->getBody() . '; Path=/; HttpOnly; SameSite=Lax; Secure',
            $response->getHeaderLine('Set-Cookie'),
        );
    }

    /**
     * Sécurité : en HTTPS, seul le cookie « __Host-csrf » compte. Un cookie
     * « csrf » sans préfixe a pu être posé par un sous-domaine : il est ignoré.
     */
    public function testOverHttpsTheUnprefixedCookieIsIgnored(): void
    {
        $response = $this->handle(
            new ServerRequest('POST', 'https://exemple.com/notes')->withCookieParams(['csrf' => self::KNOWN]),
            fn(): string => $this->token->matches(self::KNOWN) ? 'accepté' : 'refusé',
        );

        self::assertSame('refusé', (string) $response->getBody());
    }

    public function testOverHttpsThePrefixedCookieIsRead(): void
    {
        $response = $this->handle(
            new ServerRequest('POST', 'https://exemple.com/notes')->withCookieParams(['__Host-csrf' => self::KNOWN]),
            fn(): string => $this->token->matches(self::KNOWN) ? 'accepté' : 'refusé',
        );

        self::assertSame('accepté', (string) $response->getBody());
    }

    public function testACookieThatIsNotATextIsTreatedAsAbsent(): void
    {
        $response = $this->handle(
            new ServerRequest('GET', 'http://exemple.com/notes')->withCookieParams(['csrf' => [self::KNOWN]]),
            fn(): string => $this->token->value(),
        );

        self::assertNotSame(self::KNOWN, (string) $response->getBody());
        self::assertTrue($response->hasHeader('Set-Cookie'));
    }

    public function testAMalformedCookieIsReplaced(): void
    {
        $response = $this->handle(
            new ServerRequest('GET', 'http://exemple.com/notes')->withCookieParams(['csrf' => "abc\r\nSet-Cookie: session=pirate"]),
            fn(): string => $this->token->value(),
        );

        self::assertMatchesRegularExpression('/^csrf=[a-f0-9]{64}; Path=\/; HttpOnly; SameSite=Lax$/D', $response->getHeaderLine('Set-Cookie'));
    }

    public function testTheOtherCookiesOfTheResponseAreKept(): void
    {
        $handler = new class ($this->token) implements RequestHandlerInterface {
            public function __construct(private readonly CsrfToken $token) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, ['Set-Cookie' => 'theme=sombre; Path=/'], $this->token->value());
            }
        };

        $response = new CsrfCookie($this->token)->process(new ServerRequest('GET', 'http://exemple.com/notes'), $handler);

        self::assertCount(2, $response->getHeader('Set-Cookie'));
        self::assertSame('theme=sombre; Path=/', $response->getHeader('Set-Cookie')[0]);
    }

    /**
     * Le même objet sert à chaque requête d'un serveur qui reste en mémoire :
     * le jeton d'un visiteur ne doit pas passer au suivant.
     */
    public function testTheTokenOfOneRequestDoesNotLeakIntoTheNext(): void
    {
        $first = $this->handle(new ServerRequest('GET', 'http://exemple.com/notes'), fn(): string => $this->token->value());
        $second = $this->handle(new ServerRequest('GET', 'http://exemple.com/'), static fn(): string => 'Bonjour');

        self::assertTrue($first->hasHeader('Set-Cookie'));
        self::assertFalse($second->hasHeader('Set-Cookie'));
        self::assertFalse($this->token->matches((string) $first->getBody()));
    }

    // --- Outils ---------------------------------------------------------------

    /**
     * @param \Closure(): string $page ce que la page écrit
     */
    private function handle(ServerRequestInterface $request, \Closure $page): ResponseInterface
    {
        $handler = new class ($page) implements RequestHandlerInterface {
            /**
             * @param \Closure(): string $page
             */
            public function __construct(private readonly \Closure $page) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, [], ($this->page)());
            }
        };

        return new CsrfCookie($this->token)->process($request, $handler);
    }
}
