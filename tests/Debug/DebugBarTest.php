<?php

declare(strict_types=1);

namespace Wazi\Tests\Debug;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wazi\Debug\DebugBar;
use Wazi\Debug\Panel;
use Wazi\Http\Response;
use Wazi\Http\ServerRequest;

final class DebugBarTest extends TestCase
{
    // --- Sécurité : à qui la barre est montrée ----------------------------------

    public function testALocalRequestMayHaveTheBar(): void
    {
        self::assertNull(DebugBar::refusal(self::request()));
        self::assertNull(DebugBar::refusal(self::request(peer: '::1', url: 'http://[::1]:8000/')));
        self::assertNull(DebugBar::refusal(self::request(peer: '127.0.0.5', url: 'http://127.0.0.1/')));
        self::assertNull(DebugBar::refusal(self::request(url: 'http://mon-site.localhost:8000/notes')));
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function remotePeers(): iterable
    {
        yield 'adresse absente' => [null];
        yield 'adresse d\'Internet' => ['203.0.113.7'];
        yield 'réseau local' => ['192.168.1.20'];
        yield 'réseau privé' => ['10.0.0.3'];
        yield 'IPv6 d\'Internet' => ['2001:db8::1'];
        yield 'adresse qui commence comme une locale' => ['127.0.0.1.exemple.com'];
        yield 'texte vide' => [''];
    }

    #[DataProvider('remotePeers')]
    public function testARequestFromAnotherMachineNeverHasTheBar(?string $peer): void
    {
        $request = new ServerRequest('GET', 'http://localhost:8000/', [], null, '1.1', $peer === null ? [] : ['REMOTE_ADDR' => $peer]);

        self::assertSame('la connexion ne vient pas de cette machine', DebugBar::refusal($request));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function proxyHeaders(): iterable
    {
        foreach (['X-Forwarded-For', 'X-Forwarded-Host', 'X-Forwarded-Proto', 'X-Forwarded-Port', 'Forwarded', 'Via', 'X-Real-IP', 'X-Client-IP', 'CF-Connecting-IP', 'True-Client-IP'] as $header) {
            yield $header => [$header];
        }
    }

    /**
     * Sécurité : derrière un proxy installé sur la même machine (nginx, un
     * tunnel), TOUTES les requêtes arrivent de 127.0.0.1. C'est la fuite
     * classique des outils « réservés à localhost ».
     */
    #[DataProvider('proxyHeaders')]
    public function testARequestThatWentThroughAProxyNeverHasTheBar(string $header): void
    {
        $refusal = DebugBar::refusal(self::request(headers: [$header => '203.0.113.7']));

        self::assertNotNull($refusal);
        self::assertStringContainsString('en-tête de proxy (' . $header . ')', $refusal);
    }

    /**
     * Sécurité : une adresse locale ANNONCÉE par un en-tête ne compte pas.
     */
    public function testAnAddressClaimedByAHeaderIsNeverTrusted(): void
    {
        $request = new ServerRequest('GET', 'http://localhost/', ['X-Forwarded-For' => '127.0.0.1'], null, '1.1', ['REMOTE_ADDR' => '203.0.113.7']);

        self::assertNotNull(DebugBar::refusal($request));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function publicHosts(): iterable
    {
        yield 'nom public' => ['http://exemple.com/'];
        yield 'nom qui contient localhost' => ['http://localhost.exemple.com/'];
        yield 'nom qui finit presque par localhost' => ['http://monlocalhost/'];
        yield 'adresse du réseau' => ['http://192.168.1.20:8000/'];
    }

    #[DataProvider('publicHosts')]
    public function testASiteAskedUnderAPublicNameNeverHasTheBar(string $url): void
    {
        self::assertSame(
            'le site n\'est pas demandé sous un nom local (localhost, 127.0.0.1)',
            DebugBar::refusal(self::request(url: $url)),
        );
    }

    // --- Où la barre est écrite ---------------------------------------------------

    public function testTheBarIsAddedJustBeforeTheEndOfTheBody(): void
    {
        $response = new Response(200, ['Content-Type' => 'text/html; charset=utf-8', 'Content-Length' => '40'], '<html><body><h1>Page</h1></body></html>');

        $result = new DebugBar()->inject($response, [new Panel('Route', 'accueil')]);
        $html = (string) $result->getBody();

        self::assertStringStartsWith('<html><body><h1>Page</h1><aside id="wz-barre"', $html);
        self::assertStringEndsWith('</aside></body></html>', $html);
        self::assertSame(200, $result->getStatusCode());
        // La taille a changé : l'ancienne n'est plus annoncée.
        self::assertFalse($result->hasHeader('Content-Length'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function responsesLeftAlone(): iterable
    {
        yield 'JSON' => ['application/json', '{"a":"</body>"}'];
        yield 'texte' => ['text/plain; charset=utf-8', 'Bonjour </body>'];
        yield 'morceau de HTML, sans fin de page' => ['text/html; charset=utf-8', '<li>Une ligne</li>'];
        yield 'sans type' => ['', '<html><body></body></html>'];
    }

    #[DataProvider('responsesLeftAlone')]
    public function testOnlyACompleteHtmlPageGetsTheBar(string $type, string $body): void
    {
        $response = new Response(200, $type === '' ? [] : ['Content-Type' => $type], $body);

        $result = new DebugBar()->inject($response, [new Panel('Route', 'accueil')]);

        self::assertSame($body, (string) $result->getBody());
    }

    public function testTheBarGoesBeforeTheLastEndOfBody(): void
    {
        $body = '<html><body><pre>&lt;/body&gt; </body> dans un exemple</pre></body></html>';

        $html = (string) new DebugBar()->inject(new Response(200, ['Content-Type' => 'text/html'], $body), [])->getBody();

        self::assertStringEndsWith('</aside></body></html>', $html);
        self::assertSame(1, substr_count($html, '<aside id="wz-barre"'));
    }

    // --- Ce qu'elle écrit -----------------------------------------------------------

    public function testEachPanelShowsItsNameSummaryAndRows(): void
    {
        $html = new DebugBar()->render([
            new Panel('Route', 'NoteController::voir', ['Motif' => 'GET /notes/{id:int}', 'Paramètres' => 'id = 3']),
            new Panel('Requête', 'GET /nulle-part · 404', [], alert: true),
        ]);
        $page = \Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><body>' . $html . '</body></html>');

        $first = $page->querySelector('#wz-barre > details:nth-of-type(1)');
        $second = $page->querySelector('#wz-barre > details:nth-of-type(2)');

        self::assertCount(2, $page->querySelectorAll('#wz-barre > details'));
        self::assertSame('Route', $page->querySelector('details .wz-nom')?->textContent);
        self::assertSame('NoteController::voir', $page->querySelector('details .wz-resume')?->textContent);
        self::assertSame('GET /notes/{id:int}', $page->querySelector('dl dd')?->textContent);
        // Le même attribut name : une seule rubrique ouverte à la fois, sans JavaScript.
        self::assertSame('wz-barre', $first?->getAttribute('name'));
        self::assertSame('wz-alerte', $second?->getAttribute('class'));
        // Une rubrique sans ligne n'a pas de liste vide.
        self::assertNull($second->querySelector('dl'));
    }

    /**
     * Sécurité : une adresse ou un nom de champ peut contenir du HTML envoyé
     * par un visiteur. Relue comme le ferait un navigateur, la barre ne
     * contient aucune balise qu'elle n'a pas écrite elle-même.
     */
    public function testEverythingWrittenInTheBarIsEscaped(): void
    {
        $attack = '"><script>alert(1)</script><img src=x onerror=alert(2)>';

        $html = new DebugBar()->render([new Panel($attack, $attack, [$attack => $attack])]);
        $page = \Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><body>' . $html . '</body></html>');

        self::assertCount(0, $page->querySelectorAll('script, img'));
        self::assertSame($attack, $page->querySelector('.wz-nom')?->textContent);
        self::assertSame($attack, $page->querySelector('dd')?->textContent);
        self::assertCount(0, $page->querySelectorAll('[onerror]'));
    }

    /**
     * Sécurité (ADR-035) : la barre ne fait que montrer. Ni formulaire, ni
     * lien, ni script : rien n'y déclenche quoi que ce soit.
     */
    public function testTheBarOnlyShows(): void
    {
        $html = new DebugBar()->render([new Panel('Route', 'accueil', ['Motif' => 'GET /'])]);
        $page = \Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><body>' . $html . '</body></html>');

        self::assertCount(0, $page->querySelectorAll('form, button, input, a, script, iframe, link'));
        // Sa feuille de style ne charge rien.
        self::assertStringNotContainsString('url(', $html);
        self::assertStringNotContainsString('@import', $html);
    }

    /**
     * @param array<string, string> $headers
     */
    private static function request(string $peer = '127.0.0.1', string $url = 'http://localhost:8000/', array $headers = []): ServerRequest
    {
        return new ServerRequest('GET', $url, $headers, null, '1.1', ['REMOTE_ADDR' => $peer]);
    }
}
