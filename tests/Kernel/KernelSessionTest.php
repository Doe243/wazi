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
        // 1. Le formulaire : il contient le jeton, et le navigateur le reçoit
        //    aussi dans un cookie. Aucune session n'est créée pour autant.
        $form = $this->app()->handle(new ServerRequest('GET', '/connexion'));
        $csrf = self::csrfCookie($form);

        self::assertSame(200, $form->getStatusCode());
        self::assertNotSame('', $csrf);
        self::assertSame($csrf, self::token($form), 'Le champ du formulaire répète le cookie.');
        self::assertSame('', self::sessionCookie($form));
        self::assertSame([], $this->sessionFiles());

        // 2. L'envoi du formulaire, avec le cookie et le jeton : la session naît ici.
        $login = $this->app()->handle(
            new ServerRequest('POST', '/connexion')
                ->withCookieParams(['csrf' => $csrf])
                ->withParsedBody(['nom' => 'Alice', '_csrf' => $csrf]),
        );
        $session = self::sessionCookie($login);

        self::assertSame(303, $login->getStatusCode());
        self::assertNotSame('', $session);
        self::assertCount(1, $this->sessionFiles());

        // 3. La page suivante reconnaît le visiteur.
        $profile = $this->app()->handle(new ServerRequest('GET', '/profil')->withCookieParams(['session' => $session, 'csrf' => $csrf]));

        self::assertSame('Bonjour Alice', (string) $profile->getBody());

        // 4. Le formulaire suivant reprend le même jeton, sans nouveau cookie.
        $again = $this->app()->handle(new ServerRequest('GET', '/connexion')->withCookieParams(['session' => $session, 'csrf' => $csrf]));

        self::assertSame($csrf, self::token($again));
        self::assertFalse($again->hasHeader('Set-Cookie'));

        // 5. La déconnexion efface la session et son cookie.
        $logout = $this->app()->handle(
            new ServerRequest('POST', '/deconnexion')
                ->withCookieParams(['session' => $session, 'csrf' => $csrf])
                ->withParsedBody(['_csrf' => $csrf]),
        );

        self::assertStringContainsString('Max-Age=0', $logout->getHeaderLine('Set-Cookie'));
        self::assertSame(
            'Bonjour visiteur',
            (string) $this->app()->handle(new ServerRequest('GET', '/profil')->withCookieParams(['session' => $session]))->getBody(),
        );
    }

    /**
     * Sécurité (fixation de session) : l'identifiant que le visiteur avait
     * avant de se connecter ne vaut plus rien après.
     */
    public function testTheSessionIdentifierChangesAtLogin(): void
    {
        $csrf = self::csrfCookie($this->app()->handle(new ServerRequest('GET', '/connexion')));
        $before = self::sessionCookie($this->app()->handle(new ServerRequest('GET', '/panier')));

        self::assertNotSame('', $before);

        $login = $this->app()->handle(
            new ServerRequest('POST', '/connexion')
                ->withCookieParams(['session' => $before, 'csrf' => $csrf])
                ->withParsedBody(['nom' => 'Alice', '_csrf' => $csrf]),
        );
        $after = self::sessionCookie($login);

        self::assertNotSame('', $after);
        self::assertNotSame($before, $after, 'L\'identifiant change à la connexion.');
        self::assertSame(
            'Bonjour visiteur',
            (string) $this->app()->handle(new ServerRequest('GET', '/profil')->withCookieParams(['session' => $before]))->getBody(),
        );
        self::assertSame(
            'Bonjour Alice',
            (string) $this->app()->handle(new ServerRequest('GET', '/profil')->withCookieParams(['session' => $after]))->getBody(),
        );
    }

    /**
     * L'attaque CSRF : une page d'un autre site fait envoyer un formulaire au
     * navigateur d'un visiteur. Les cookies partent, pas le jeton : la page
     * piégée ne peut pas le lire.
     */
    public function testAFormSentFromAnotherSiteIsRefused(): void
    {
        $csrf = self::csrfCookie($this->app()->handle(new ServerRequest('GET', '/connexion')));
        $session = self::sessionCookie($this->app()->handle(new ServerRequest('GET', '/panier')));

        $response = $this->app()->handle(
            new ServerRequest('POST', '/connexion')
                ->withCookieParams(['session' => $session, 'csrf' => $csrf])
                ->withParsedBody(['nom' => 'Pirate']),
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('Accès refusé', (string) $response->getBody());
        self::assertSame(
            'Bonjour visiteur',
            (string) $this->app()->handle(new ServerRequest('GET', '/profil')->withCookieParams(['session' => $session]))->getBody(),
            'La connexion n\'a pas eu lieu.',
        );
    }

    /**
     * L'attaquant devine le nom du champ et y met un jeton de son choix : sans
     * le cookie correspondant, c'est refusé.
     */
    public function testAnInventedTokenIsRefused(): void
    {
        $csrf = self::csrfCookie($this->app()->handle(new ServerRequest('GET', '/connexion')));

        $response = $this->app()->handle(
            new ServerRequest('POST', '/connexion')
                ->withCookieParams(['csrf' => $csrf])
                ->withParsedBody(['nom' => 'Pirate', '_csrf' => str_repeat('a', 64)]),
        );

        self::assertSame(403, $response->getStatusCode());
    }

    public function testAJavascriptRequestSendsTheTokenInAHeader(): void
    {
        $csrf = self::csrfCookie($this->app()->handle(new ServerRequest('GET', '/connexion')));

        $response = $this->app()->handle(
            new ServerRequest('POST', '/deconnexion', ['X-CSRF-Token' => $csrf])->withCookieParams(['csrf' => $csrf]),
        );

        self::assertSame(303, $response->getStatusCode());
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

    public function testAVisitorWhoOnlyReadsGetsNoCookieAtAll(): void
    {
        $response = $this->app()->handle(new ServerRequest('GET', '/profil'));

        self::assertFalse($response->hasHeader('Set-Cookie'));
        self::assertSame([], $this->sessionFiles());
    }

    /**
     * ADR-023 : un formulaire affiché à un visiteur anonyme ne crée plus de
     * fichier de session. Un robot qui parcourt le site ne remplit pas le disque.
     */
    public function testShowingAFormCreatesNoSessionFile(): void
    {
        for ($visit = 0; $visit < 5; $visit++) {
            $this->app()->handle(new ServerRequest('GET', '/connexion'));
        }

        self::assertSame([], $this->sessionFiles());
    }

    /**
     * Sécurité (ADR-023) : la protection des formulaires ne dépend pas des
     * sessions. Une application sans sessions est protégée elle aussi.
     */
    public function testWithoutSessionsFormsAreStillProtected(): void
    {
        $refused = $this->appWithoutSessions()->handle(new ServerRequest('POST', '/notes'));

        self::assertSame(403, $refused->getStatusCode());

        $form = $this->appWithoutSessions()->handle(new ServerRequest('GET', '/connexion'));
        $csrf = self::csrfCookie($form);

        self::assertNotSame('', $csrf);
        self::assertSame($csrf, self::token($form));

        $accepted = $this->appWithoutSessions()->handle(
            new ServerRequest('POST', '/notes')->withCookieParams(['csrf' => $csrf])->withParsedBody(['_csrf' => $csrf]),
        );

        self::assertSame('créée', (string) $accepted->getBody());
        self::assertFalse($accepted->hasHeader('Set-Cookie'));
    }

    public function testOverHttpsTheCookieIsLockedToTheSite(): void
    {
        $form = $this->app()->handle(new ServerRequest('GET', 'https://exemple.com/connexion'));

        self::assertMatchesRegularExpression(
            '/^__Host-csrf=[a-f0-9]{64}; Path=\/; HttpOnly; SameSite=Lax; Secure$/D',
            $form->getHeaderLine('Set-Cookie'),
        );
    }

    public function testSecurityHeadersStillWrapTheCookies(): void
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

        // Une page qui note quelque chose pour un visiteur pas encore connecté.
        $app->router->get('/panier', static function () use ($app): ResponseInterface {
            $app->container->get(Session::class)->set('panier', [3]);

            return new Response(200, [], 'Panier');
        });

        $app->router->post('/webhook', static fn(): ResponseInterface => new Response(200, [], 'reçu'), [WithoutCsrf::class]);

        return $app;
    }

    private function appWithoutSessions(): Kernel
    {
        $app = new Kernel(errorHandler: new ErrorHandler(false, new MemoryErrorLog()), views: $this->directory . '/views');

        $app->router->get('/connexion', static fn(): ResponseInterface => $app->container->get(Kioo::class)->page('connexion'));
        $app->router->post('/notes', static fn(): ResponseInterface => new Response(200, [], 'créée'));

        return $app;
    }

    /**
     * @return list<string>
     */
    private function sessionFiles(): array
    {
        return glob($this->directory . '/var/sessions/*.json') ?: [];
    }

    private static function sessionCookie(ResponseInterface $response): string
    {
        return self::cookieNamed('session', $response);
    }

    private static function csrfCookie(ResponseInterface $response): string
    {
        return self::cookieNamed('csrf', $response);
    }

    private static function cookieNamed(string $name, ResponseInterface $response): string
    {
        foreach ($response->getHeader('Set-Cookie') as $cookie) {
            if (preg_match('/^' . $name . '=([a-f0-9]{64});/', $cookie, $match) === 1) {
                return $match[1];
            }
        }

        return '';
    }

    private static function token(ResponseInterface $response): string
    {
        return preg_match('/name="_csrf" value="([a-f0-9]{64})"/', (string) $response->getBody(), $match) === 1 ? $match[1] : '';
    }
}
