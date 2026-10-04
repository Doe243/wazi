<?php

declare(strict_types=1);

namespace Wazi\Tests\Kernel\Command;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Wazi\Console\Application;
use Wazi\Console\Output;
use Wazi\Http\Response;
use Wazi\Kernel\Command\ExplainCommand;
use Wazi\Kernel\Kernel;
use Wazi\Middleware\WithoutCsrf;
use Wazi\Tests\Routing\Fixtures\NoteController;
use Wazi\Tests\Routing\Fixtures\RequireToken;

final class ExplainCommandTest extends TestCase
{
    /** @var resource */
    private mixed $standard;

    /** @var resource */
    private mixed $errors;

    /** Vrai si le code d'une route a été exécuté : il ne doit jamais l'être. */
    private bool $executed = false;

    protected function setUp(): void
    {
        $standard = fopen('php://memory', 'w+');
        $errors = fopen('php://memory', 'w+');
        self::assertIsResource($standard);
        self::assertIsResource($errors);
        $this->standard = $standard;
        $this->errors = $errors;
        $this->executed = false;
    }

    // --- Une route trouvée ---------------------------------------------------

    public function testItExplainsTheRouteTheJourneyAndTheCode(): void
    {
        $code = $this->explain(['/notes/42']);
        $written = $this->written();

        self::assertSame(0, $code);
        self::assertStringStartsWith("GET /notes/42\n", $written);

        // 1. La route.
        self::assertStringContainsString('GET /notes/{id:int}   (la 3e des 14 route(s) déclarée(s))', $written);
        self::assertStringContainsString('{id} = 42   (un nombre entier)', $written);

        // 2. Le trajet, dans l'ordre.
        self::assertMatchesRegularExpression(
            '/1\. SecurityHeaders .*\n +2\. CsrfCookie .*\n +3\. le routeur .*\n +4\. CsrfProtection +ne demande rien : GET ne fait que lire\n +5\. .*NoteController::show\(\)/',
            $written,
        );

        // 3. Le code.
        self::assertStringContainsString(NoteController::class . '::show()', $written);
        self::assertStringContainsString('NoteController.php, ligne ', $written);
        self::assertStringContainsString('Greeter $greeter', $written);
        self::assertStringContainsString('int $id   ← le paramètre {id} de la route : 42', $written);
    }

    public function testTheFirstRouteIsThePremiere(): void
    {
        $this->explain(['/']);

        self::assertStringContainsString('(la 1re des 14 route(s) déclarée(s))', $this->written());
    }

    public function testATextParameterIsShownAsText(): void
    {
        $this->explain(['/bonjour/Alice']);

        self::assertStringContainsString('{name} = Alice   (un texte)', $this->written());
        self::assertStringContainsString('string $name   ← le paramètre {name} de la route : Alice', $this->written());
    }

    public function testRouteMiddlewaresComeBeforeTheOnesOfEveryRoute(): void
    {
        $this->explain(['/notes', '--method=POST']);

        self::assertMatchesRegularExpression(
            '/le routeur .*\n +\d+\. RequireToken .*\n +\d+\. CsrfProtection +exige le jeton du formulaire ; sans lui, la réponse est 403\n/',
            $this->written(),
        );
    }

    /**
     * Un middleware du projet se présente avec la première phrase de son propre commentaire.
     */
    public function testAProjectMiddlewareIsDescribedByItsOwnComment(): void
    {
        $this->explain(['/notes', '--method=POST']);

        $comment = (string) new \ReflectionClass(RequireToken::class)->getDocComment();

        self::assertSame(1, preg_match('#/\*\*\s*\*\s*(.+)#', $comment, $firstLine), 'La classe d\'essai a un commentaire.');
        self::assertStringContainsString(mb_strimwidth(trim($firstLine[1] ?? ''), 0, 60), $this->written());
    }

    public function testARouteExemptedFromCsrfSaysSo(): void
    {
        $this->explain(['/webhook', '--method=POST']);

        self::assertStringContainsString('ne demande rien : la route en est dispensée par WithoutCsrf', $this->written());
    }

    public function testAFunctionHandlerIsLocatedAndItsArgumentsExplained(): void
    {
        $this->explain(['/articles/7']);
        $written = $this->written();

        self::assertStringContainsString('Une fonction, écrite dans ', $written);
        self::assertStringContainsString('ExplainCommandTest.php, ligne ', $written);
        self::assertStringContainsString('ServerRequestInterface $request   ← la requête', $written);
        self::assertStringContainsString('int $id   ← le paramètre {id} de la route : 7', $written);
        self::assertStringContainsString('string $format   ← sa valeur par défaut', $written);
        self::assertStringContainsString('$inconnu   ← RIEN ne le fournit', $written);
    }

    public function testTheMethodIsNotCaseSensitive(): void
    {
        $this->explain(['/notes', '--method=post']);

        self::assertStringStartsWith("POST /notes\n", $this->written());
    }

    public function testTheQueryStringIsNotPartOfTheRoute(): void
    {
        $this->explain(['/notes/42?page=2#haut']);

        self::assertStringStartsWith("GET /notes/42\n", $this->written());
        self::assertStringContainsString('{id} = 42', $this->written());
    }

    public function testTheAddressCanBeWrittenWithoutItsLeadingSlash(): void
    {
        $this->explain(['notes/42']);

        self::assertStringStartsWith("GET /notes/42\n", $this->written());
    }

    // --- Routes masquées ----------------------------------------------------------

    /**
     * La première route déclarée gagne (ADR-009). Quand une autre aurait
     * aussi convenu, plus loin, l'explication le signale.
     */
    public function testARouteHiddenByAnEarlierOneIsReported(): void
    {
        $this->explain(['/pages/nouveau']);
        $written = $this->written();

        self::assertStringContainsString('GET /pages/{slug}', $written);
        self::assertStringContainsString('Attention  Cette adresse convient aussi à une route déclarée plus loin', $written);
        self::assertStringContainsString('GET /pages/nouveau', $written);
        self::assertStringContainsString('déclarez la plus précise avant l\'autre', $written);
    }

    public function testWithoutHiddenRouteThereIsNoWarning(): void
    {
        $this->explain(['/notes/42']);

        self::assertStringNotContainsString('Attention', $this->written());
    }

    // --- Aucune route --------------------------------------------------------------

    public function testAnUnknownAddressIsA404WithWhatToCheck(): void
    {
        $code = $this->explain(['/nulle-part']);

        self::assertSame(0, $code);
        self::assertStringContainsString('Réponse : 404, page introuvable.', $this->written());
        self::assertStringContainsString('wazi routes', $this->written());
    }

    public function testAConstraintThatIsNotMetIsA404(): void
    {
        $this->explain(['/notes/abc']);

        self::assertStringContainsString('Réponse : 404', $this->written());
        self::assertStringContainsString('{id:int} n\'accepte qu\'un nombre entier', $this->written());
    }

    public function testAnotherMethodIsA405WithTheAcceptedOnes(): void
    {
        $this->explain(['/webhook']);

        self::assertStringContainsString('Réponse : 405, méthode non permise.', $this->written());
        self::assertStringContainsString('Méthodes acceptées : POST.', $this->written());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeAddresses(): iterable
    {
        yield 'remontée de dossier' => ['/notes/../42'];
        yield 'barre oblique encodée' => ['/notes/a%2Fb'];
        yield 'point seul' => ['/notes/./42'];
        yield 'octet nul encodé' => ['/notes/%00'];
    }

    #[DataProvider('unsafeAddresses')]
    public function testAnAddressRefusedByTheRouterIsExplainedAsAProtection(string $address): void
    {
        $this->explain([$address]);

        self::assertStringContainsString('Réponse : 404', $this->written());
        self::assertStringContainsString('c\'est une protection', $this->written());
    }

    // --- Sécurité : rien n'est exécuté ----------------------------------------------

    /**
     * Sécurité (ADR-029) : expliquer une adresse ne l'appelle pas. On peut
     * expliquer « DELETE /compte » sans rien supprimer.
     */
    public function testNothingIsExecuted(): void
    {
        foreach ([['/articles/7'], ['/dangereux', '--method=DELETE'], ['/notes', '--method=POST']] as $typed) {
            $this->explain($typed);
        }

        self::assertFalse($this->executed);
    }

    /**
     * Sécurité : l'adresse tapée est affichée. Elle ne peut pas piloter le terminal.
     */
    public function testATrappedAddressCannotDriveTheTerminal(): void
    {
        $this->explain(["/notes/\e[2J\e]0;piège\x07"]);

        self::assertDoesNotMatchRegularExpression('/[\x00-\x08\x0B-\x1F\x7F]/', $this->written());
    }

    // --- Ce qui est mal écrit ----------------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedMethods(): iterable
    {
        yield 'vide' => [''];
        yield 'espace' => ['GET POST'];
        yield 'chiffres' => ['GET2'];
        yield 'démesurée' => [str_repeat('A', 21)];
    }

    #[DataProvider('refusedMethods')]
    public function testAMethodThatIsNotOneIsRefused(string $method): void
    {
        $code = $this->explain(['/notes', '--method=' . $method]);

        self::assertSame(Application::USAGE_ERROR, $code);
        self::assertStringContainsString('--method', $this->errorsWritten());
    }

    /**
     * Sous Windows, Git Bash transforme « /notes/42 » en chemin de fichier
     * avant de le donner à la commande : on l'explique.
     */
    public function testAWindowsFilePathIsExplained(): void
    {
        $code = $this->explain(['C:/Program Files/Git/notes/42']);

        self::assertSame(Application::USAGE_ERROR, $code);
        self::assertStringContainsString('wazi explain notes/42', $this->errorsWritten());
    }

    public function testTheAddressIsRequired(): void
    {
        self::assertSame(Application::USAGE_ERROR, $this->explain([]));
    }

    // --- Outils --------------------------------------------------------------------------

    /**
     * @param list<string> $typed ce qui est tapé après « wazi explain »
     */
    private function explain(array $typed): int
    {
        $mark = function (): ResponseInterface {
            $this->executed = true;

            return new Response();
        };

        $app = new Kernel();
        $app->router->get('/', $mark);
        $app->router->addController(NoteController::class);
        $app->router->post('/webhook', $mark, [WithoutCsrf::class]);
        $app->router->delete('/dangereux', $mark);
        $app->router->get('/articles/{id:int}', function (ServerRequestInterface $request, int $id, $inconnu, string $format = 'html'): ResponseInterface {
            $this->executed = true;

            return new Response();
        });
        // Mal rangées exprès : la première masque la seconde.
        $app->router->get('/pages/{slug}', $mark);
        $app->router->get('/pages/nouveau', $mark);

        // Le compte : 1 + 8 du contrôleur + webhook + dangereux + articles + 2 pages = 14 routes.
        $console = new Application('wazi', 'cli');
        $console->add(new ExplainCommand($app));

        return $console->run(['wazi', 'explain', ...$typed], new Output($this->standard, $this->errors, false));
    }

    private function written(): string
    {
        rewind($this->standard);

        return str_replace("\r\n", "\n", (string) stream_get_contents($this->standard));
    }

    private function errorsWritten(): string
    {
        rewind($this->errors);

        return str_replace("\r\n", "\n", (string) stream_get_contents($this->errors));
    }
}
