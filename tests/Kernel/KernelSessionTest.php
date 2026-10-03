<?php

declare(strict_types=1);

namespace Wazi\Tests\Kernel;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Wazi\Errors\ErrorHandler;
use Wazi\Http\Response;
use Wazi\Http\ServerRequest;
use Wazi\Http\Session;
use Wazi\Kernel\Kernel;
use Wazi\Middleware\WithoutCsrf;
use Wazi\Tests\Errors\Fixtures\MemoryErrorLog;
use Wazi\View\Kioo;

/**
 * Sessions et protection des formulaires, de bout en bout : chaque test joue
 * les requêtes d'un navigateur contre une application complète.
 */
final class KernelSessionTest extends TestCase
{
    /** Un dossier temporaire propre à chaque test, supprimé ensuite. */
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wazi-' . bin2hex(random_bytes(8));
        mkdir($this->directory . DIRECTORY_SEPARATOR . 'views', 0o777, true);
        file_put_contents(
            $this->directory . '/views/connexion.kioo',
            '<form method="post" action="/connexion"><input name="nom"><button>Entrer</button></form>',
        );
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
        }

        rmdir($this->directory);
    }

    /**
     * Le parcours complet : afficher le formulaire, l'envoyer, être reconnu
     * à la page suivante, puis se déconnecter.
     */
    public function testAVisitorLogsInIsRememberedAndLogsOut(): void
    {
        // 1. Le formulaire : il contient le jeton, et le navigateur reçoit un cookie.
        $form = $this->app()->handle(new ServerRequest('GET', '/connexion'));
        $cookie = self::cookie($form);
        $token = self::token($form);

        self::assertSame(200, $form->getStatusCode());
        self::assertNotSame('', $cookie);
        self::assertNotSame('', $token);

        // 2. L'envoi du formulaire, avec le cookie et le jeton.
        $login = $this->app()->handle(
            new ServerRequest('POST', '/connexion')
                ->withCookieParams(['session' => $cookie])
                ->withParsedBody(['nom' => 'Alice', '_csrf' => $token]),
        );
        $cookieAfterLogin = self::cookie($login);

        self::assertSame(303, $login->getStatusCode());
        self::assertNotSame('', $cookieAfterLogin);
        self::assertNotSame($cookie, $cookieAfterLogin, 'L\'identifiant change à la connexion.');

        // 3. La page suivante reconnaît le visiteur.
        $profile = $this->app()->handle(new ServerRequest('GET', '/profil')->withCookieParams(['session' => $cookieAfterLogin]));

        self::assertSame('Bonjour Alice', (string) $profile->getBody());

        // L'identifiant d'avant la connexion ne vaut plus rien.
        $withOldCookie = $this->app()->handle(new ServerRequest('GET', '/profil')->withCookieParams(['session' => $cookie]));

        self::assertSame('Bonjour visiteur', (string) $withOldCookie->getBody());

        // 4. La déconnexion efface la session et le cookie.
        $tokenAfterLogin = self::token($this->app()->handle(new ServerRequest('GET', '/connexion')->withCookieParams(['session' => $cookieAfterLogin])));
        $logout = $this->app()->handle(
            new ServerRequest('POST', '/deconnexion')
                ->withCookieParams(['session' => $cookieAfterLogin])
                ->withParsedBody(['_csrf' => $tokenAfterLogin]),
        );

        self::assertStringContainsString('Max-Age=0', $logout->getHeaderLine('Set-Cookie'));
        self::assertSame(
            'Bonjour visiteur',
            (string) $this->app()->handle(new ServerRequest('GET', '/profil')->withCookieParams(['session' => $cookieAfterLogin]))->getBody(),
        );
    }

    /**
     * L'attaque CSRF : une page d'un autre site fait envoyer un formulaire au
     * navigateur d'un visiteur connecté. Le cookie part, pas le jeton.
     */
    public function testAFormSentFromAnotherSiteIsRefused(): void
    {
        $cookie = self::cookie($this->app()->handle(new ServerRequest('GET', '/connexion')));

        $response = $this->app()->handle(
            new ServerRequest('POST', '/connexion')->withCookieParams(['session' => $cookie])->withParsedBody(['nom' => 'Pirate']),
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('Accès refusé', (string) $response->getBody());
        self::assertSame(
            'Bonjour visiteur',
            (string) $this->app()->handle(new ServerRequest('GET', '/profil')->withCookieParams(['session' => $cookie]))->getBody(),
            'La connexion n\'a pas eu lieu.',
        );
    }

    public function testTheRefusalExplainsItselfInDevelopment(): void
    {
        $response = $this->app(development: true)->handle(new ServerRequest('POST', '/connexion')->withParsedBody(['nom' => 'x']));

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('jeton de protection des formulaires', (string) $response->getBody());
    }

    public function testARouteCanBeExemptedWithWithoutCsrf(): void
    {
        $response = $this->app()->handle(new ServerRequest('POST', '/webhook'));

        self::assertSame('reçu', (string) $response->getBody());
    }

    public function testAVisitorWhoOnlyReadsGetsNoSessionCookie(): void
    {
        $response = $this->app()->handle(new ServerRequest('GET', '/profil'));

        self::assertFalse($response->hasHeader('Set-Cookie'));
        self::assertSame([], glob($this->directory . '/var/sessions/*') ?: []);
    }

    public function testWithoutSessionsThereIsNoCookieAndNoCsrfCheck(): void
    {
        $app = new Kernel(errorHandler: new ErrorHandler(false, new MemoryErrorLog()));
        $app->router->post('/notes', static fn(): ResponseInterface => new Response(200, [], 'créée'));

        $response = $app->handle(new ServerRequest('POST', '/notes'));

        self::assertSame('créée', (string) $response->getBody());
        self::assertFalse($response->hasHeader('Set-Cookie'));
        self::assertFalse($app->container->has('session.inconnue'));
    }

    public function testSecurityHeadersStillWrapTheSession(): void
    {
        $response = $this->app()->handle(new ServerRequest('GET', '/connexion'));

        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertTrue($response->hasHeader('Set-Cookie'));
    }

    // --- Outils ------------------------------------------------------------

    /**
     * Une application neuve à chaque requête, comme avec PHP : seuls les
     * fichiers de session relient deux requêtes.
     */
    private function app(bool $development = false): Kernel
    {
        $app = new Kernel(
            errorHandler: new ErrorHandler($development, new MemoryErrorLog()),
            views: $this->directory . '/views',
            sessions: $this->directory . '/var/sessions',
        );

        $app->router->get('/connexion', static fn(): ResponseInterface => $app->container->get(Kioo::class)->page('connexion'));

        $app->router->post('/connexion', static function (ServerRequestInterface $request) use ($app): ResponseInterface {
            $session = $app->container->get(Session::class);
            $body = $request->getParsedBody();
            $name = is_array($body) && is_string($body['nom'] ?? null) ? $body['nom'] : '';

            $session->set('nom', $name);
            // Après une connexion réussie : un identifiant neuf.
            $session->regenerate();

            return new Response(303, ['Location' => '/profil']);
        });

        $app->router->get('/profil', static function () use ($app): ResponseInterface {
            $name = $app->container->get(Session::class)->get('nom', 'visiteur');

            return new Response(200, [], 'Bonjour ' . (is_string($name) ? $name : ''));
        });

        $app->router->post('/deconnexion', static function () use ($app): ResponseInterface {
            $app->container->get(Session::class)->clear();

            return new Response(303, ['Location' => '/']);
        });

        $app->router->post('/webhook', static fn(): ResponseInterface => new Response(200, [], 'reçu'), [WithoutCsrf::class]);

        return $app;
    }

    private static function cookie(ResponseInterface $response): string
    {
        return preg_match('/session=([a-f0-9]{64});/', $response->getHeaderLine('Set-Cookie'), $match) === 1 ? $match[1] : '';
    }

    private static function token(ResponseInterface $response): string
    {
        return preg_match('/name="_csrf" value="([a-f0-9]{64})"/', (string) $response->getBody(), $match) === 1 ? $match[1] : '';
    }
}
