<?php

declare(strict_types=1);

namespace Wazi\Tests\Routing;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wazi\Routing\Exception\InvalidRouteException;
use Wazi\Routing\Route;

final class RouteTest extends TestCase
{
    // --- Correspondance ----------------------------------------------------

    public function testTheHomePageMatchesASingleEmptySegment(): void
    {
        $route = $this->route('/');

        self::assertSame([], $route->match(['']));
        self::assertNull($route->match(['articles']));
    }

    public function testAFixedPathMatchesExactly(): void
    {
        $route = $this->route('/articles/nouveau');

        self::assertSame([], $route->match(['articles', 'nouveau']));
        self::assertNull($route->match(['articles']));
        self::assertNull($route->match(['articles', 'nouveau', '']), 'Un « / » final fait un segment de plus.');
        self::assertNull($route->match(['Articles', 'nouveau']), 'Un chemin est sensible à la casse.');
    }

    public function testParametersAreReturnedByName(): void
    {
        $route = $this->route('/blog/{annee:int}/{slug:slug}');

        self::assertSame(['annee' => 2026, 'slug' => 'mon-article'], $route->match(['blog', '2026', 'mon-article']));
    }

    public function testAParameterWithoutConstraintAcceptsAnyText(): void
    {
        self::assertSame(['pseudo' => 'René Mumba'], $this->route('/profil/{pseudo}')->match(['profil', 'René Mumba']));
    }

    public function testAValueThatBreaksTheConstraintDoesNotMatch(): void
    {
        $route = $this->route('/articles/{id:int}');

        self::assertNull($route->match(['articles', 'abc']));
        self::assertNull($route->match(['articles', '']));
        self::assertNull($route->match(['articles', '-1']));
    }

    public function testALiteralSegmentCanContainAccents(): void
    {
        self::assertSame([], $this->route('/café')->match(['café']));
    }

    // --- Méthodes ----------------------------------------------------------

    public function testItAcceptsItsOwnMethods(): void
    {
        $route = new Route(['GET', 'POST', 'GET'], '/contact', static fn(): null => null);

        self::assertSame(['GET', 'POST'], $route->methods);
        self::assertTrue($route->accepts('POST'));
        self::assertFalse($route->accepts('DELETE'));
        self::assertFalse($route->accepts('get'), 'Une méthode HTTP est sensible à la casse.');
    }

    public function testAGetRouteAlsoAnswersHead(): void
    {
        self::assertTrue($this->route('/')->accepts('HEAD'));
        self::assertFalse(new Route(['POST'], '/', static fn(): null => null)->accepts('HEAD'));
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function invalidMethods(): iterable
    {
        yield 'aucune' => [[]];
        yield 'minuscules' => [['get']];
        yield 'vide' => [['']];
        yield 'espace' => [['GET POST']];
        yield 'retour à la ligne' => [["GET\n"]];
    }

    /**
     * @param list<string> $methods
     */
    #[DataProvider('invalidMethods')]
    public function testItRejectsInvalidMethods(array $methods): void
    {
        $this->expectException(InvalidRouteException::class);

        new Route($methods, '/', static fn(): null => null);
    }

    // --- Déclarations invalides -------------------------------------------

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidPaths(): iterable
    {
        yield 'sans barre au début' => ['articles', 'commencer par'];
        yield 'vide' => ['', 'commencer par'];
        yield 'barre finale' => ['/articles/', 'segment vide'];
        yield 'deux barres' => ['/articles//42', 'segment vide'];
        yield 'paramètre collé à du texte' => ['/article-{id}', 'invalide'];
        yield 'accolade non fermée' => ['/articles/{id', 'invalide'];
        yield 'nom de paramètre avec tiret' => ['/articles/{mon-id}', 'invalide'];
        yield 'nom de paramètre commençant par un chiffre' => ['/articles/{1id}', 'invalide'];
        yield 'paramètre sans nom' => ['/articles/{}', 'invalide'];
        yield 'expression régulière libre' => ['/articles/{id:\d+}', 'invalide'];
        yield 'point' => ['/articles/.', 'invalide'];
        yield 'deux points' => ['/articles/../admin', 'invalide'];
        yield 'requête dans le chemin' => ['/articles?page=1', 'invalide'];
        yield 'fragment' => ['/articles#haut', 'invalide'];
        yield 'encodage' => ['/caf%C3%A9', 'invalide'];
        yield 'espace' => ['/mes articles', 'invalide'];
        yield 'barre oblique inversée' => ['/articles\\42', 'invalide'];
        yield 'contrainte inconnue' => ['/articles/{id:entier}', 'any, int, slug, uuid'];
        yield 'paramètre en double' => ['/{id}/commentaires/{id}', 'deux fois'];
    }

    #[DataProvider('invalidPaths')]
    public function testItRejectsAnInvalidPathAndExplainsWhy(string $path, string $expectedHint): void
    {
        try {
            $this->route($path);
            self::fail('Une exception était attendue.');
        } catch (InvalidRouteException $exception) {
            self::assertStringContainsString($expectedHint, $exception->getMessage());
        }
    }

    private function route(string $path): Route
    {
        return new Route(['GET'], $path, static fn(): null => null);
    }
}
