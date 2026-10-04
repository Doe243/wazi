<?php

declare(strict_types=1);

namespace Wazi\Tests\Kernel;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Wazi\Debug\Panel;
use Wazi\Debug\Trace;
use Wazi\Errors\ErrorHandler;
use Wazi\Http\Response;
use Wazi\Http\ServerRequest;
use Wazi\Http\Session;
use Wazi\Kernel\DebugPanels;
use Wazi\Kernel\Kernel;
use Wazi\Routing\Router;
use Wazi\Tests\Errors\Fixtures\MemoryErrorLog;
use Wazi\View\Kioo;

/**
 * La barre de débogage, vue depuis le noyau : quand elle est écrite, et ce
 * qu'elle montre d'une requête (ADR-035).
 */
final class KernelDebugBarTest extends TestCase
{
    private const string PAGE = '<!DOCTYPE html><html><body><h1>Accueil</h1></body></html>';

    // --- Sécurité : quand la barre existe ---------------------------------------

    public function testInDevelopmentALocalPageGetsTheBar(): void
    {
        $html = (string) $this->kernel(development: true)->handle(self::local('/'))->getBody();
        $page = \Dom\HTMLDocument::createFromString($html);

        self::assertNotNull($page->getElementById('wz-barre'));
        self::assertSame(
            ['Requête', 'Route', 'Middlewares', 'Templates', 'Session', 'Wazi'],
            array_map(static fn(\Dom\Node $name): string => (string) $name->textContent, iterator_to_array($page->querySelectorAll('#wz-barre .wz-nom'))),
        );
        // La page elle-même est intacte.
        self::assertSame('Accueil', $page->querySelector('h1')?->textContent);
    }

    /**
     * Sécurité : le mode production est le défaut, et la barre n'y existe
     * pas, même pour une requête venue de la machine elle-même.
     */
    public function testInProductionTheBarNeverExists(): void
    {
        $app = $this->kernel(development: false);

        self::assertSame(self::PAGE, (string) $app->handle(self::local('/'))->getBody());
        // Aucun objet du composant Debug n'est créé : son code n'est pas chargé.
        self::assertNull(new \ReflectionProperty($app, 'debugBar')->getValue($app));
        self::assertNull(new \ReflectionProperty($app, 'trace')->getValue($app));
    }

    public function testTheBarCanBeTurnedOffInDevelopment(): void
    {
        $app = $this->kernel(development: true, debugBar: false);

        self::assertSame(self::PAGE, (string) $app->handle(self::local('/'))->getBody());
    }

    /**
     * Sécurité : un site laissé en mode développement ne montre pas la barre
     * à ses visiteurs.
     */
    public function testInDevelopmentAVisitorFromElsewhereNeverGetsTheBar(): void
    {
        $app = $this->kernel(development: true);

        $requests = [
            'autre machine' => new ServerRequest('GET', 'http://localhost/', [], null, '1.1', ['REMOTE_ADDR' => '203.0.113.7']),
            'sans adresse' => new ServerRequest('GET', 'http://localhost/'),
            'derrière un proxy local' => new ServerRequest('GET', 'http://localhost/', ['X-Forwarded-For' => '203.0.113.7'], null, '1.1', ['REMOTE_ADDR' => '127.0.0.1']),
            'nom public' => new ServerRequest('GET', 'http://exemple.com/', [], null, '1.1', ['REMOTE_ADDR' => '127.0.0.1']),
        ];

        foreach ($requests as $case => $request) {
            self::assertSame(self::PAGE, (string) $app->handle($request)->getBody(), $case);
        }
    }

    /**
     * Sécurité : la page d'erreur garde sa politique « default-src 'none' »,
     * et rien ne lui est ajouté.
     */
    public function testAnErrorPageNeverCarriesTheBar(): void
    {
        $response = $this->kernel(development: true)->handle(self::local('/nulle-part'));

        self::assertSame(404, $response->getStatusCode());
        self::assertStringNotContainsString('wz-barre', (string) $response->getBody());
        self::assertStringStartsWith("default-src 'none'", $response->getHeaderLine('Content-Security-Policy'));
    }

    public function testAResponseThatIsNotAPageIsLeftAlone(): void
    {
        $app = $this->kernel(development: true);
        $app->router->get('/api', static fn(): ResponseInterface => new Response(200, ['Content-Type' => 'application/json'], '{"ok":true}'));
        $app->router->get('/ailleurs', static fn(): ResponseInterface => new Response(303, ['Location' => '/']));

        self::assertSame('{"ok":true}', (string) $app->handle(self::local('/api'))->getBody());
        self::assertSame('', (string) $app->handle(self::local('/ailleurs'))->getBody());
    }

    // --- Ce que la barre montre -----------------------------------------------------

    public function testItShowsTheRouteThatAnswered(): void
    {
        $app = $this->kernel(development: true);
        $app->router->get('/notes/{id:int}', static fn(): ResponseInterface => new Response(200, ['Content-Type' => 'text/html'], self::PAGE));

        $text = self::barText((string) $app->handle(self::local('/notes/42'))->getBody());

        self::assertStringContainsString('GET /notes/42 · 200', $text);
        self::assertStringContainsString('GET /notes/{id:int}', $text);
        self::assertStringContainsString('id = 42', $text);
        self::assertStringContainsString('fonction (KernelDebugBarTest.php, ligne', $text);
        self::assertStringContainsString('Wazi\Middleware\SecurityHeaders', $text);
    }

    public function testItShowsTheTemplatesThatWereRendered(): void
    {
        $views = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wazi-' . bin2hex(random_bytes(8));
        mkdir($views);
        file_put_contents($views . '/accueil.kioo', '<!DOCTYPE html><html><body><h1>{titre}</h1></body></html>');

        try {
            $app = new Kernel(development: true, errorHandler: new ErrorHandler(true, log: new MemoryErrorLog()), views: $views);
            $app->router->get('/', static fn(): ResponseInterface => $app->container->get(Kioo::class)->page('accueil', ['titre' => 'Bonjour']));

            $text = self::barText((string) $app->handle(self::local('/'))->getBody());

            self::assertMatchesRegularExpression('/Templates1 · \d+(,\d)? ms/u', $text);
            self::assertStringContainsString('1. accueil', $text);
        } finally {
            unlink($views . '/accueil.kioo');
            rmdir($views);
        }
    }

    /**
     * Sécurité (ADR-035) : d'une session, d'un formulaire et d'une adresse,
     * la barre montre le NOM des clés. Aucune valeur n'est collectée, donc
     * aucune ne peut fuiter : ni mot de passe, ni cookie, ni en-tête.
     */
    public function testNoValueThatCouldBeASecretIsEverCollected(): void
    {
        $session = new Session();
        $session->start('identifiant-de-session-secret', ['utilisateur' => 'valeur-de-session-secrete', 'panier' => ['x']]);

        $request = new ServerRequest(
            'POST',
            'http://localhost/connexion?jeton=valeur-d-adresse-secrete',
            ['Authorization' => 'Bearer en-tete-secret', 'Cookie' => 'session=cookie-secret'],
            null,
            '1.1',
            ['REMOTE_ADDR' => '127.0.0.1'],
        )
            ->withQueryParams(['jeton' => 'valeur-d-adresse-secrete'])
            ->withParsedBody(['nom' => 'alice', 'mot_de_passe' => 'mot-de-passe-secret'])
            ->withCookieParams(['session' => 'cookie-secret']);

        $panels = DebugPanels::collect($request, new Response(200), new Router(), [], $session, new Trace(), 1.0);
        $everything = self::flatten($panels);

        foreach (['valeur-de-session-secrete', 'identifiant-de-session-secret', 'valeur-d-adresse-secrete', 'mot-de-passe-secret', 'en-tete-secret', 'cookie-secret', 'alice'] as $secret) {
            self::assertStringNotContainsString($secret, $everything, $secret);
        }

        // Les noms, eux, sont là : ils suffisent à comprendre ce qui s'est passé.
        self::assertStringContainsString('nom, mot_de_passe', $everything);
        self::assertStringContainsString('jeton', $everything);
        self::assertStringContainsString('utilisateur, panier', $everything);
    }

    /**
     * Sécurité : ni variable d'environnement, ni réglage dans la barre.
     */
    public function testTheEnvironmentPanelOnlyShowsVersions(): void
    {
        putenv('WAZI_TEST_SECRET=secret-d-environnement');

        try {
            $everything = self::flatten(DebugPanels::collect(self::local('/'), new Response(200), new Router(), [], null, new Trace(), 1.0));

            self::assertStringNotContainsString('secret-d-environnement', $everything);
            self::assertStringContainsString(PHP_VERSION, $everything);
        } finally {
            putenv('WAZI_TEST_SECRET');
        }
    }

    public function testASessionThatIsNotConfiguredOrNotStartedIsSaidSo(): void
    {
        $without = DebugPanels::collect(self::local('/'), new Response(200), new Router(), [], null, new Trace(), 1.0);
        $notStarted = DebugPanels::collect(self::local('/'), new Response(200), new Router(), [], new Session(), new Trace(), 1.0);

        self::assertSame('non réglée', $without[4]->summary);
        self::assertSame('aucune', $notStarted[4]->summary);
    }

    /**
     * Un envoi démesuré n'allonge pas la barre : les noms sont bornés, en
     * nombre comme en longueur.
     */
    public function testFieldNamesAreBounded(): void
    {
        $fields = array_fill_keys(array_map(static fn(int $i): string => 'champ' . $i, range(1, 100)), 'x');
        $fields[str_repeat('n', 500)] = 'x';

        $request = self::local('/')->withParsedBody($fields);
        $rows = DebugPanels::collect($request, new Response(200), new Router(), [], null, new Trace(), 1.0)[0]->rows;

        self::assertStringContainsString('champ30', $rows['Champs reçus']);
        self::assertStringNotContainsString('champ31,', $rows['Champs reçus']);
        self::assertStringContainsString('(101 en tout)', $rows['Champs reçus']);
        self::assertLessThan(700, mb_strlen($rows['Champs reçus']));
    }

    /**
     * Sécurité : un nom de champ piégé est écrit comme du texte.
     */
    public function testAFieldNameWithHtmlCannotRunInTheBar(): void
    {
        $app = $this->kernel(development: true);
        $request = self::local('/')->withQueryParams(['<img src=x onerror=alert(1)>' => '1', '</aside><script>alert(2)</script>' => '2']);

        $page = \Dom\HTMLDocument::createFromString((string) $app->handle($request)->getBody());

        self::assertCount(0, $page->querySelectorAll('script, img'));
        self::assertStringContainsString('<img src=x onerror=alert(1)>', (string) $page->getElementById('wz-barre')?->textContent);
    }

    public function testAResponseInErrorIsHighlighted(): void
    {
        $app = $this->kernel(development: true);
        $app->router->get('/introuvable-maison', static fn(): ResponseInterface => new Response(404, ['Content-Type' => 'text/html'], self::PAGE));

        $page = \Dom\HTMLDocument::createFromString((string) $app->handle(self::local('/introuvable-maison'))->getBody());

        self::assertSame('wz-alerte', $page->querySelector('#wz-barre details')?->getAttribute('class'));
    }

    // --- Outils -----------------------------------------------------------------------

    private function kernel(bool $development, bool $debugBar = true): Kernel
    {
        $app = new Kernel(
            development: $development,
            errorHandler: new ErrorHandler($development, log: new MemoryErrorLog()),
            debugBar: $debugBar,
        );
        $app->router->get('/', static fn(): ResponseInterface => new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], self::PAGE));

        return $app;
    }

    /**
     * Une requête venue de cette machine, sous un nom local, sans proxy.
     */
    private static function local(string $path): ServerRequest
    {
        return new ServerRequest('GET', 'http://localhost:8000' . $path, [], null, '1.1', ['REMOTE_ADDR' => '127.0.0.1']);
    }

    /**
     * Le texte de la barre, tel qu'un navigateur le lit.
     */
    private static function barText(string $html): string
    {
        $bar = \Dom\HTMLDocument::createFromString($html)->getElementById('wz-barre');
        self::assertNotNull($bar);
        // La feuille de style n'est pas du texte affiché.
        $bar->querySelector('style')?->remove();

        return (string) $bar->textContent;
    }

    /**
     * Tout ce que contiennent des rubriques, mis bout à bout.
     *
     * @param list<Panel> $panels
     */
    private static function flatten(array $panels): string
    {
        $text = '';

        foreach ($panels as $panel) {
            $text .= $panel->name . ' ' . $panel->summary . ' ' . implode(' ', array_keys($panel->rows)) . ' ' . implode(' ', $panel->rows) . "\n";
        }

        return $text;
    }
}
