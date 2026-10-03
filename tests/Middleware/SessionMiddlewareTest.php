<?php

declare(strict_types=1);

namespace Wazi\Tests\Middleware;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Wazi\Http\Response;
use Wazi\Http\ServerRequest;
use Wazi\Http\Session;
use Wazi\Middleware\Exception\SessionStoreException;
use Wazi\Middleware\FileSessionStore;
use Wazi\Middleware\SessionMiddleware;

/**
 * Le middleware des sessions et son rangement en fichiers, testés ensemble :
 * chaque test joue une ou plusieurs requêtes, comme le ferait un navigateur.
 */
final class SessionMiddlewareTest extends TestCase
{
    /** Un dossier temporaire propre à chaque test, supprimé ensuite. */
    private string $directory;

    private string $sessions;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wazi-' . bin2hex(random_bytes(8));
        $this->sessions = $this->directory . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'sessions';
        mkdir($this->directory . DIRECTORY_SEPARATOR . 'public', 0o777, true);
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

    // --- D'une requête à l'autre -------------------------------------------

    public function testWhatIsNotedIsFoundAgainOnTheNextRequest(): void
    {
        $first = $this->request(static fn(Session $session) => $session->set('user_id', 42));
        $cookie = self::cookieValue($first);

        $second = $this->request(static fn(Session $session): string => 'utilisateur ' . json_encode($session->get('user_id')), $cookie);

        self::assertSame('utilisateur 42', (string) $second->getBody());
        self::assertFalse($second->hasHeader('Set-Cookie'), 'Le navigateur a déjà le cookie : inutile de le renvoyer.');
    }

    /**
     * Un visiteur qui ne fait que lire ne reçoit pas de cookie et ne crée pas
     * de fichier : pas de bandeau de cookies à afficher, pas de disque à remplir.
     */
    public function testAVisitorWhoOnlyReadsGetsNoCookieAndNoFile(): void
    {
        $response = $this->request(static fn(Session $session): mixed => $session->get('user_id'));

        self::assertFalse($response->hasHeader('Set-Cookie'));
        self::assertSame([], $this->sessionFiles());
    }

    public function testTheSessionIsAlsoAvailableAsARequestAttribute(): void
    {
        $session = new Session();
        $middleware = new SessionMiddleware($session, new FileSessionStore($this->sessions));

        $handler = new class implements RequestHandlerInterface {
            public ?object $seen = null;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $attribute = $request->getAttribute(Session::class);
                $this->seen = is_object($attribute) ? $attribute : null;

                return new Response();
            }
        };

        $middleware->process(new ServerRequest('GET', '/'), $handler);

        self::assertSame($session, $handler->seen);
    }

    public function testTwoVisitorsDoNotShareTheirSessions(): void
    {
        $alice = self::cookieValue($this->request(static fn(Session $session) => $session->set('nom', 'Alice')));
        $bob = self::cookieValue($this->request(static fn(Session $session) => $session->set('nom', 'Bob')));

        self::assertNotSame($alice, $bob);
        self::assertSame('Alice', (string) $this->request(static fn(Session $session): mixed => $session->get('nom'), $alice)->getBody());
        self::assertSame('Bob', (string) $this->request(static fn(Session $session): mixed => $session->get('nom'), $bob)->getBody());
    }

    // --- Sécurité : le cookie ----------------------------------------------

    public function testTheCookieIsProtected(): void
    {
        $cookie = $this->request(static fn(Session $session) => $session->set('a', 1))->getHeaderLine('Set-Cookie');

        self::assertMatchesRegularExpression('/^session=[a-f0-9]{64}; Path=\/; HttpOnly; SameSite=Lax$/D', $cookie);
    }

    public function testTheCookieIsSecureOnHttps(): void
    {
        $cookie = $this->request(static fn(Session $session) => $session->set('a', 1), null, 'https://exemple.com/')->getHeaderLine('Set-Cookie');

        self::assertStringEndsWith('; HttpOnly; SameSite=Lax; Secure', $cookie);
    }

    public function testTheCookieNameCanBeChosen(): void
    {
        $middleware = new SessionMiddleware(new Session(), new FileSessionStore($this->sessions), 'carnet');

        $response = $middleware->process(new ServerRequest('GET', '/'), self::handler(static fn(Session $session) => $session->set('a', 1), $middleware));

        self::assertStringStartsWith('carnet=', $response->getHeaderLine('Set-Cookie'));
    }

    // --- Sécurité : identifiants -------------------------------------------

    /**
     * Contre la fixation de session : un identifiant que le serveur n'a pas
     * créé n'est jamais adopté.
     */
    public function testAnUnknownIdentifierIsNeverAdopted(): void
    {
        $chosenByAttacker = str_repeat('a', 64);

        $response = $this->request(static fn(Session $session) => $session->set('user_id', 42), $chosenByAttacker);

        self::assertNotSame($chosenByAttacker, self::cookieValue($response));
        self::assertFileDoesNotExist($this->sessions . '/' . $chosenByAttacker . '.json');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function forgedCookies(): iterable
    {
        yield 'remontée de dossier' => ['../../public/index'];
        yield 'remontée de dossier à la bonne longueur' => [str_pad('../', 64, 'a')];
        yield 'chemin absolu' => ['/etc/passwd'];
        yield 'octet nul' => [str_repeat('a', 63) . "\0"];
        yield 'vide' => [''];
        yield 'majuscules' => [str_repeat('A', 64)];
    }

    #[DataProvider('forgedCookies')]
    public function testAForgedCookieNeverReachesTheDisk(string $cookie): void
    {
        file_put_contents($this->directory . '/public/index.json', '{"user_id": 1}');

        $response = $this->request(static fn(Session $session): string => json_encode($session->all(), JSON_THROW_ON_ERROR), $cookie);

        self::assertSame('[]', (string) $response->getBody(), 'Aucun fichier n\'a été lu.');
        self::assertFalse($response->hasHeader('Set-Cookie'));
    }

    public function testACookieThatIsNotATextIsIgnored(): void
    {
        $middleware = new SessionMiddleware(new Session(), new FileSessionStore($this->sessions));
        $request = new ServerRequest('GET', '/')->withCookieParams(['session' => ['tableau']]);

        $response = $middleware->process($request, self::handler(static fn(Session $session): string => $session->isNew() ? 'nouvelle' : 'connue', $middleware));

        self::assertSame('nouvelle', (string) $response->getBody());
    }

    public function testRegenerateGivesANewCookieAndDeletesTheOldFile(): void
    {
        $before = self::cookieValue($this->request(static fn(Session $session) => $session->set('panier', [1])));

        $response = $this->request(static function (Session $session): void {
            $session->set('user_id', 42);
            $session->regenerate();
        }, $before);
        $after = self::cookieValue($response);

        self::assertNotSame($before, $after);
        self::assertSame([$after . '.json'], $this->sessionFiles());
        self::assertSame('{"panier":[1],"user_id":42}', (string) $this->request(static fn(Session $session): string => json_encode($session->all(), JSON_THROW_ON_ERROR), $after)->getBody());
        self::assertSame('[]', (string) $this->request(static fn(Session $session): string => json_encode($session->all(), JSON_THROW_ON_ERROR), $before)->getBody(), 'L\'ancien identifiant ne vaut plus rien.');
    }

    public function testClearDeletesTheFileAndTheCookie(): void
    {
        $cookie = self::cookieValue($this->request(static fn(Session $session) => $session->set('user_id', 42)));

        $response = $this->request(static fn(Session $session) => $session->clear(), $cookie);

        self::assertSame([], $this->sessionFiles());
        self::assertStringStartsWith('session=; Path=/; HttpOnly; SameSite=Lax; Max-Age=0', $response->getHeaderLine('Set-Cookie'));
    }

    // --- Rangement en fichiers ---------------------------------------------

    public function testTheSessionIsStoredAsJson(): void
    {
        $cookie = self::cookieValue($this->request(static fn(Session $session) => $session->set('nom', 'René')));

        self::assertSame('{"nom":"René"}', file_get_contents($this->sessions . '/' . $cookie . '.json'));
    }

    /**
     * Sécurité : un fichier de session n'est jamais passé à unserialize().
     * Même si quelqu'un parvenait à y écrire un objet PHP sérialisé, il ne
     * serait pas reconstruit.
     */
    public function testAStoredFileIsNeverUnserialized(): void
    {
        $id = str_repeat('b', 64);
        mkdir($this->sessions, 0o777, true);
        file_put_contents($this->sessions . '/' . $id . '.json', serialize(new \ArrayObject(['user_id' => 1])));

        $response = $this->request(static fn(Session $session): string => json_encode($session->all(), JSON_THROW_ON_ERROR), $id);

        self::assertSame('[]', (string) $response->getBody());
    }

    public function testAnExpiredSessionIsForgotten(): void
    {
        $store = new FileSessionStore($this->sessions, 60);
        $id = str_repeat('c', 64);
        $store->write($id, ['user_id' => 42]);

        self::assertSame(['user_id' => 42], $store->read($id));

        touch($this->sessions . '/' . $id . '.json', time() - 61);

        self::assertNull($store->read($id));
        self::assertSame([], $this->sessionFiles(), 'Le fichier expiré est supprimé à la lecture.');
    }

    public function testExpiredFilesAreCleanedUp(): void
    {
        $store = new FileSessionStore($this->sessions, 60);
        $store->write(str_repeat('d', 64), ['a' => 1]);
        $store->write(str_repeat('e', 64), ['a' => 2]);
        touch($this->sessions . '/' . str_repeat('d', 64) . '.json', time() - 3600);

        $store->removeExpired();

        self::assertSame([str_repeat('e', 64) . '.json'], $this->sessionFiles());
    }

    public function testEachRequestPostponesTheExpiry(): void
    {
        $cookie = self::cookieValue($this->request(static fn(Session $session) => $session->set('a', 1)));
        $file = $this->sessions . '/' . $cookie . '.json';
        touch($file, time() - 1000);

        $this->request(static fn(Session $session): mixed => $session->get('a'), $cookie);
        clearstatcache();

        self::assertGreaterThan(time() - 10, filemtime($file));
    }

    public function testTheStoreIgnoresAnIdentifierThatIsNotOne(): void
    {
        $store = new FileSessionStore($this->sessions);

        $store->write('../../public/piege', ['a' => 1]);
        $store->delete('../../public/piege');

        self::assertNull($store->read('../../public/piege'));
        self::assertFileDoesNotExist($this->directory . '/public/piege.json');
    }

    /**
     * Des sessions dans le dossier public seraient téléchargeables : un
     * attaquant y lirait les identifiants et prendrait la place des visiteurs.
     */
    public function testASessionDirectoryInsideThePublicDirectoryIsRefused(): void
    {
        $previous = $_SERVER['DOCUMENT_ROOT'] ?? null;
        $_SERVER['DOCUMENT_ROOT'] = $this->directory . '/public';

        try {
            new FileSessionStore($this->directory . '/public/sessions')->write(str_repeat('f', 64), ['a' => 1]);
            self::fail('Une exception était attendue.');
        } catch (SessionStoreException $exception) {
            self::assertStringContainsString('dossier public', $exception->getMessage());
        } finally {
            if ($previous === null) {
                unset($_SERVER['DOCUMENT_ROOT']);
            } else {
                $_SERVER['DOCUMENT_ROOT'] = $previous;
            }
        }
    }

    // --- Outils ------------------------------------------------------------

    /**
     * Joue une requête : la fonction reçoit la session, comme un contrôleur.
     * Ce qu'elle retourne (un texte) devient le corps de la réponse.
     *
     * @param \Closure(Session): mixed $controller
     */
    private function request(\Closure $controller, ?string $cookie = null, string $uri = 'http://exemple.com/'): ResponseInterface
    {
        $middleware = new SessionMiddleware(new Session(), new FileSessionStore($this->sessions));
        $request = new ServerRequest('GET', $uri);

        if ($cookie !== null) {
            $request = $request->withCookieParams(['session' => $cookie]);
        }

        return $middleware->process($request, self::handler($controller, $middleware));
    }

    /**
     * @param \Closure(Session): mixed $controller
     */
    private static function handler(\Closure $controller, SessionMiddleware $middleware): RequestHandlerInterface
    {
        return new class ($controller) implements RequestHandlerInterface {
            /**
             * @param \Closure(Session): mixed $controller
             */
            public function __construct(private readonly \Closure $controller) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $session = $request->getAttribute(Session::class);
                $result = $session instanceof Session ? ($this->controller)($session) : null;

                return new Response(200, [], is_scalar($result) ? (string) $result : '');
            }
        };
    }

    private static function cookieValue(ResponseInterface $response): string
    {
        return preg_match('/^session=([a-f0-9]{64});/', $response->getHeaderLine('Set-Cookie'), $match) === 1 ? $match[1] : '';
    }

    /**
     * @return list<string>
     */
    private function sessionFiles(): array
    {
        return array_map(basename(...), glob($this->sessions . DIRECTORY_SEPARATOR . '*') ?: []);
    }
}
