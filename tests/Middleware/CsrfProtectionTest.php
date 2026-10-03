<?php

declare(strict_types=1);

namespace Wazi\Tests\Middleware;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Wazi\Contracts\HttpError;
use Wazi\Http\CsrfToken;
use Wazi\Http\Pipeline;
use Wazi\Http\Response;
use Wazi\Http\ServerRequest;
use Wazi\Middleware\CsrfProtection;
use Wazi\Middleware\Exception\CsrfException;
use Wazi\Middleware\WithoutCsrf;

final class CsrfProtectionTest extends TestCase
{
    private CsrfToken $token;

    /** Vrai dès que la requête a atteint le code protégé. */
    private bool $reached = false;

    protected function setUp(): void
    {
        // Le visiteur a déjà reçu son jeton, dans un cookie.
        $this->token = new CsrfToken();
        $this->token->start(bin2hex(random_bytes(32)));
        $this->reached = false;
    }

    // --- Ce qui passe ------------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function safeMethods(): iterable
    {
        yield 'GET' => ['GET'];
        yield 'HEAD' => ['HEAD'];
        yield 'OPTIONS' => ['OPTIONS'];
    }

    #[DataProvider('safeMethods')]
    public function testARequestThatOnlyReadsNeedsNoToken(string $method): void
    {
        $this->handle(new ServerRequest($method, '/notes'));

        self::assertTrue($this->reached);
        self::assertNull($this->token->toSend(), 'Une simple lecture ne crée pas de jeton.');
    }

    public function testAFormWithTheTokenIsAccepted(): void
    {
        $token = $this->token->value();

        $this->handle(new ServerRequest('POST', '/notes')->withParsedBody(['texte' => 'Pain', '_csrf' => $token]));

        self::assertTrue($this->reached);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeMethods(): iterable
    {
        yield 'POST' => ['POST'];
        yield 'PUT' => ['PUT'];
        yield 'PATCH' => ['PATCH'];
        yield 'DELETE' => ['DELETE'];
    }

    #[DataProvider('unsafeMethods')]
    public function testAJavascriptRequestSendsTheTokenInAHeader(string $method): void
    {
        $token = $this->token->value();

        $this->handle(new ServerRequest($method, '/notes/1', ['X-CSRF-Token' => $token]));

        self::assertTrue($this->reached);
    }

    // --- Ce qui est refusé -------------------------------------------------

    #[DataProvider('unsafeMethods')]
    public function testARequestThatChangesSomethingWithoutTokenIsRefused(string $method): void
    {
        try {
            $this->handle(new ServerRequest($method, '/notes/1'));
            self::fail('Une exception était attendue.');
        } catch (CsrfException $exception) {
            self::assertInstanceOf(HttpError::class, $exception);
            self::assertSame(403, $exception->getStatusCode());
            self::assertStringContainsString('WithoutCsrf', $exception->getMessage());
        }

        self::assertFalse($this->reached, 'Le code protégé n\'a pas été exécuté.');
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function wrongTokens(): iterable
    {
        yield 'jeton inventé' => [str_repeat('a', 64)];
        yield 'vide' => [''];
        yield 'tableau' => [['jeton']];
        yield 'nombre' => [0];
        yield 'vrai' => [true];
        yield 'null' => [null];
    }

    #[DataProvider('wrongTokens')]
    public function testAWrongTokenIsRefused(mixed $token): void
    {
        $this->expectException(CsrfException::class);

        $this->handle(new ServerRequest('POST', '/notes')->withParsedBody(['_csrf' => $token]));
    }

    public function testTheTokenOfAnotherVisitorIsRefused(): void
    {
        $other = new CsrfToken();
        $other->start(bin2hex(random_bytes(32)));

        $this->expectException(CsrfException::class);

        $this->handle(new ServerRequest('POST', '/notes')->withParsedBody(['_csrf' => $other->value()]));
    }

    /**
     * Une méthode inconnue n'est pas « sûre » par défaut : elle demande le jeton.
     */
    public function testAnUnknownMethodIsTreatedAsUnsafe(): void
    {
        $this->expectException(CsrfException::class);

        $this->handle(new ServerRequest('PURGE', '/cache'));
    }

    public function testALowercaseGetIsNotASafeMethod(): void
    {
        $this->expectException(CsrfException::class);

        $this->handle(new ServerRequest('get', '/notes'));
    }

    /**
     * Le jeton ne se lit pas dans l'adresse : il y serait visible dans
     * l'historique et dans les journaux.
     */
    public function testTheTokenIsNotReadFromTheQueryString(): void
    {
        $token = $this->token->value();

        $this->expectException(CsrfException::class);

        $this->handle(new ServerRequest('POST', '/notes?_csrf=' . $token)->withQueryParams(['_csrf' => $token]));
    }

    public function testTheErrorMessageDoesNotRevealTheExpectedToken(): void
    {
        $token = $this->token->value();

        try {
            $this->handle(new ServerRequest("PO\nST", '/notes')->withParsedBody(['_csrf' => 'faux']));
            self::fail('Une exception était attendue.');
        } catch (\Throwable $exception) {
            self::assertStringNotContainsString($token, $exception->getMessage());
            self::assertStringNotContainsString('faux', $exception->getMessage());
        }
    }

    /**
     * Sécurité : sans cookie, rien ne prouve que le formulaire vient d'une
     * page du site. Un jeton créé pendant cette même requête ne compte pas.
     */
    public function testWithoutACookieEvenTheTokenOfThisRequestIsRefused(): void
    {
        $this->token->start(null);
        $created = $this->token->value();

        $this->expectException(CsrfException::class);

        $this->handle(new ServerRequest('POST', '/notes')->withParsedBody(['_csrf' => $created]));
    }

    public function testBeforeTheCookieWasReadEverythingIsRefused(): void
    {
        $this->expectException(CsrfException::class);

        new CsrfProtection(new CsrfToken())->process(new ServerRequest('POST', '/notes'), $this->finalHandler());
    }

    // --- Sortie explicite et locale ----------------------------------------

    public function testARouteCanBeExemptedExplicitly(): void
    {
        $pipeline = new Pipeline([new WithoutCsrf(), new CsrfProtection($this->token)], $this->finalHandler());

        $pipeline->handle(new ServerRequest('POST', '/webhooks/paiement'));

        self::assertTrue($this->reached);
    }

    /**
     * Sécurité : la dispense est une marque posée par le serveur. Rien de ce
     * qu'envoie le client (en-tête, champ, paramètre) ne peut la créer.
     */
    public function testTheClientCannotExemptItself(): void
    {
        $request = new ServerRequest('POST', '/notes?' . rawurlencode(WithoutCsrf::class) . '=1', ['WithoutCsrf' => 'true', 'X-Without-Csrf' => '1'])
            ->withQueryParams([WithoutCsrf::class => true])
            ->withParsedBody([WithoutCsrf::class => true, 'WithoutCsrf' => true]);

        $this->expectException(CsrfException::class);

        $this->handle($request);
    }

    public function testOnlyTheExactMarkExempts(): void
    {
        $this->expectException(CsrfException::class);

        $this->handle(new ServerRequest('POST', '/notes')->withAttribute(WithoutCsrf::class, 'true'));
    }

    // --- Outils ------------------------------------------------------------

    private function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new CsrfProtection($this->token)->process($request, $this->finalHandler());
    }

    private function finalHandler(): RequestHandlerInterface
    {
        $markReached = function (): void {
            $this->reached = true;
        };

        return new class ($markReached) implements RequestHandlerInterface {
            /**
             * @param \Closure(): void $markReached
             */
            public function __construct(private readonly \Closure $markReached) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ($this->markReached)();

                return new Response();
            }
        };
    }
}
