<?php

declare(strict_types=1);

namespace Wazi\Tests\Errors;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wazi\Contracts\HttpError;
use Wazi\Errors\ErrorHandler;
use Wazi\Errors\ErrorLog;
use Wazi\Errors\ErrorPage;
use Wazi\Http\Exception\InvalidUriException;
use Wazi\Http\Uri;
use Wazi\Tests\Errors\Fixtures\MemoryErrorLog;

final class ErrorHandlerTest extends TestCase
{
    /** Le journal où le gestionnaire écrit pendant le test. */
    private MemoryErrorLog $log;

    protected function setUp(): void
    {
        $this->log = new MemoryErrorLog();
    }

    // --- Production (le mode par défaut) ----------------------------------

    public function testAFailureGivesAGeneric500PageInProduction(): void
    {
        $response = $this->handler()->handle(new \RuntimeException('Connexion à la base refusée pour root'));
        $html = (string) $response->getBody();

        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('Erreur interne', $html);
        self::assertStringNotContainsString('Connexion à la base', $html);
        self::assertStringNotContainsString('RuntimeException', $html);
        self::assertStringNotContainsString(basename(__FILE__), $html);
        self::assertStringNotContainsString('mode développement', $html);
    }

    public function testProductionIsTheDefaultMode(): void
    {
        $handler = new ErrorHandler(log: $this->log);

        self::assertStringNotContainsString('message secret', (string) $handler->handle(new \RuntimeException('message secret'))->getBody());
    }

    public function testAFailureShowsAReferenceThatIsAlsoInTheLog(): void
    {
        $html = (string) $this->handler()->handle(new \RuntimeException('panne'))->getBody();

        self::assertCount(1, $this->log->entries);
        self::assertSame(1, preg_match('/Erreur ([0-9a-f]{16}) /', $this->log->entries[0], $matches));
        self::assertStringContainsString($matches[1] ?? 'référence absente', $html);
    }

    public function testTwoFailuresHaveDifferentReferences(): void
    {
        $handler = $this->handler();
        $handler->handle(new \RuntimeException('une'));
        $handler->handle(new \RuntimeException('deux'));

        self::assertNotSame(substr($this->log->entries[0], 0, 40), substr($this->log->entries[1], 0, 40));
    }

    public function testAnHttpErrorGivesItsOwnStatusAndHeaders(): void
    {
        $response = $this->handler()->handle(self::httpError(405, ['Allow' => 'GET, HEAD'], 'détail pour le développeur'));
        $html = (string) $response->getBody();

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET, HEAD', $response->getHeaderLine('Allow'));
        self::assertStringContainsString('Méthode non permise', $html);
        self::assertStringNotContainsString('détail pour le développeur', $html);
    }

    public function testAnHttpErrorIsNotLoggedInProduction(): void
    {
        $html = (string) $this->handler()->handle(self::httpError(404))->getBody();

        self::assertSame([], $this->log->entries, 'Une page introuvable n\'est pas un incident.');
        self::assertStringContainsString('Page introuvable', $html);
        self::assertStringNotContainsString('Référence', $html);
    }

    /**
     * @return iterable<string, array{int, int, string}>
     */
    public static function statusCodes(): iterable
    {
        yield '400' => [400, 400, 'Requête incorrecte'];
        yield '403' => [403, 403, 'Accès refusé'];
        yield '413' => [413, 413, 'Contenu trop volumineux'];
        yield '4xx sans page prévue' => [418, 418, 'Requête incorrecte'];
        yield '503' => [503, 503, 'Service indisponible'];
        yield '5xx sans page prévue' => [507, 507, 'Erreur interne'];
        yield 'code de succès : erreur de programmation' => [200, 500, 'Erreur interne'];
        yield 'code de redirection' => [302, 500, 'Erreur interne'];
        yield 'code hors limites' => [999, 500, 'Erreur interne'];
        yield 'code négatif' => [-1, 500, 'Erreur interne'];
    }

    #[DataProvider('statusCodes')]
    public function testTheStatusOfAnHttpErrorIsCheckedBeforeBeingUsed(int $announced, int $expected, string $title): void
    {
        $response = $this->handler()->handle(self::httpError($announced));

        self::assertSame($expected, $response->getStatusCode());
        self::assertStringContainsString($title, (string) $response->getBody());
    }

    public function testAnHttpErrorOf5xxIsLoggedLikeAFailure(): void
    {
        $this->handler()->handle(self::httpError(503));

        self::assertCount(1, $this->log->entries);
    }

    // --- Développement -----------------------------------------------------

    public function testDevelopmentShowsTheMessageTheTypeAndWhereItHappened(): void
    {
        $line = __LINE__ + 1;
        $error = new \RuntimeException('La table « articles » n\'existe pas.');

        $html = (string) $this->handler(development: true)->handle($error)->getBody();

        self::assertStringContainsString('La table « articles »', $html);
        self::assertStringContainsString('RuntimeException', $html);
        self::assertStringContainsString(htmlspecialchars(__FILE__ . ', ligne ' . $line), $html);
        self::assertStringContainsString('mode développement', $html);
    }

    /**
     * Une exception de Wazi naît dans le code de Wazi. Ce qui aide le
     * développeur, c'est la ligne de SON code qui l'a provoquée.
     */
    public function testTheOriginPointsToYourCodeNotToTheFramework(): void
    {
        $line = 0;

        try {
            $line = __LINE__ + 1;
            new Uri('https://exemple.com/mon article');
            self::fail('Une exception était attendue.');
        } catch (InvalidUriException $error) {
            $html = (string) $this->handler(development: true)->handle($error)->getBody();
        }

        self::assertStringContainsString(htmlspecialchars(__FILE__ . ', ligne ' . $line), $html);
        self::assertStringNotContainsString('InvalidUriException.php', $html);
        self::assertStringNotContainsString('Uri.php', $html);
    }

    public function testDevelopmentShowsTheMessageOfAnHttpErrorAndLogsIt(): void
    {
        $html = (string) $this->handler(development: true)->handle(self::httpError(404, [], 'Aucune route pour /inconnu'))->getBody();

        self::assertStringContainsString('Aucune route pour /inconnu', $html);
        self::assertCount(1, $this->log->entries);
    }

    // --- Sécurité : ce que le navigateur ne voit jamais ---------------------

    /**
     * @return iterable<string, array{bool}>
     */
    public static function modes(): iterable
    {
        yield 'production' => [false];
        yield 'développement' => [true];
    }

    #[DataProvider('modes')]
    public function testThePageNeverShowsTheTraceOrTheValueOfAVariable(bool $development): void
    {
        $error = $this->failWithSecret('mot-de-passe-tres-secret');

        $html = (string) $this->handler($development)->handle($error)->getBody();

        self::assertStringNotContainsString('mot-de-passe-tres-secret', $html);
        self::assertStringNotContainsString('failWithSecret', $html, 'Aucune trace dans le navigateur.');
        self::assertStringNotContainsString('#0', $html);
    }

    #[DataProvider('modes')]
    public function testThePageNeverShowsEnvironmentValues(bool $development): void
    {
        $previous = $_SERVER;
        $_SERVER['DATABASE_PASSWORD'] = 'valeur-d-environnement-secrete';
        $_ENV['DATABASE_PASSWORD'] = 'valeur-d-environnement-secrete';

        try {
            $html = (string) $this->handler($development)->handle(new \RuntimeException('panne'))->getBody();
        } finally {
            $_SERVER = $previous;
            unset($_ENV['DATABASE_PASSWORD']);
        }

        self::assertStringNotContainsString('valeur-d-environnement-secrete', $html);
        self::assertStringNotContainsString('valeur-d-environnement-secrete', implode('', $this->log->entries));
    }

    public function testTheMessageIsEscapedSoItCannotRunAsScript(): void
    {
        $error = new \RuntimeException('<script>alert("xss")</script><img src=x onerror=alert(1)>');

        $html = (string) $this->handler(development: true)->handle($error)->getBody();

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringContainsString('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', $html);
    }

    public function testInvalidBytesInTheMessageDoNotBreakThePage(): void
    {
        $html = (string) $this->handler(development: true)->handle(new \RuntimeException("octets \xFF\xFE invalides"))->getBody();

        self::assertStringContainsString('octets', $html);
        self::assertStringContainsString('invalides', $html);
    }

    #[DataProvider('modes')]
    public function testTheResponseCarriesStrictSecurityHeaders(bool $development): void
    {
        $response = $this->handler($development)->handle(new \RuntimeException('panne'));
        $policy = $response->getHeaderLine('Content-Security-Policy');

        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringContainsString("default-src 'none'", $policy);
        self::assertStringNotContainsString('unsafe-inline', $policy);
    }

    /**
     * La politique n'autorise qu'une feuille de style, reconnue à son empreinte :
     * elle doit être exactement celle de la page.
     */
    public function testTheStyleAllowedByThePolicyIsTheOneInThePage(): void
    {
        $response = $this->handler()->handle(new \RuntimeException('panne'));

        self::assertSame(1, preg_match('#<style>(.*?)</style>#s', (string) $response->getBody(), $style));
        self::assertStringContainsString(
            "style-src 'sha256-" . base64_encode(hash('sha256', $style[1] ?? '', true)) . "'",
            $response->getHeaderLine('Content-Security-Policy'),
        );
    }

    public function testAnHttpErrorCannotReplaceASecurityHeader(): void
    {
        $response = $this->handler()->handle(self::httpError(404, [
            'content-security-policy' => "default-src *",
            'Content-Type' => 'text/plain',
            'X-Autre' => 'conservé',
        ]));

        self::assertStringContainsString("default-src 'none'", $response->getHeaderLine('Content-Security-Policy'));
        self::assertStringNotContainsString('default-src *', $response->getHeaderLine('Content-Security-Policy'));
        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('conservé', $response->getHeaderLine('X-Autre'));
    }

    // --- Journal -----------------------------------------------------------

    public function testTheLogEntryDescribesTheErrorAndItsCauses(): void
    {
        $cause = new \LogicException('cause première');
        $this->handler()->handle(new \RuntimeException('panne visible', 0, $cause));

        $entry = $this->log->entries[0];

        self::assertStringContainsString('[wazi] Erreur ', $entry);
        self::assertStringContainsString('500 — RuntimeException : panne visible', $entry);
        self::assertStringContainsString(__FILE__, $entry);
        self::assertStringContainsString('#0 ', $entry);
        self::assertStringContainsString('causée par : LogicException : cause première', $entry);
    }

    /**
     * Sécurité : les arguments des fonctions ne sont pas consignés. Un mot de
     * passe passé à une fonction se retrouverait sinon dans le journal.
     */
    public function testTheLoggedTraceHasNoFunctionArguments(): void
    {
        $this->handler()->handle($this->failWithSecret('mot-de-passe-tres-secret'));

        self::assertStringContainsString('failWithSecret()', $this->log->entries[0]);
        self::assertStringNotContainsString('mot-de-passe-tres-secret', $this->log->entries[0]);
    }

    public function testAMessageCannotForgeALogLine(): void
    {
        $this->handler()->handle(new \RuntimeException("panne\n[wazi] Erreur 0000000000000000 — 500 — fausse entrée\r\n\x1B[31m"));

        $lines = explode("\n", $this->log->entries[0]);

        self::assertStringContainsString('panne?[wazi] Erreur 0000000000000000', $lines[0]);
        self::assertStringNotContainsString("\r", $this->log->entries[0]);
        self::assertStringNotContainsString("\x1B", $this->log->entries[0]);

        foreach (array_slice($lines, 1) as $line) {
            self::assertStringStartsWith('  ', $line, 'Chaque ligne suivante est une ligne de trace, indentée.');
        }
    }

    // --- Le gestionnaire ne tombe jamais en panne ---------------------------

    public function testAFailingLogDoesNotPreventTheResponse(): void
    {
        $brokenLog = new class implements ErrorLog {
            public function write(string $entry): void
            {
                throw new \RuntimeException('disque plein');
            }
        };

        $response = new ErrorHandler(false, $brokenLog)->handle(new \RuntimeException('panne'));

        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('Erreur interne', (string) $response->getBody());
    }

    public function testAnHttpErrorWithAnInvalidHeaderStillGivesAResponse(): void
    {
        $response = $this->handler()->handle(self::httpError(404, ['X-Test' => "a\r\nSet-Cookie: session=piege"]));

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('Erreur interne.', (string) $response->getBody());
        self::assertFalse($response->hasHeader('Set-Cookie'));
    }

    public function testAnHttpErrorThatFailsItselfStillGivesAResponse(): void
    {
        $error = new class extends \RuntimeException implements HttpError {
            public function getStatusCode(): int
            {
                throw new \LogicException('exception dans l\'exception');
            }

            public function getResponseHeaders(): array
            {
                return [];
            }
        };

        self::assertSame(500, $this->handler()->handle($error)->getStatusCode());
    }

    public function testAPhpErrorIsHandledLikeAnyFailure(): void
    {
        $response = $this->handler()->handle(new \TypeError('mauvais type'));

        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('TypeError : mauvais type', $this->log->entries[0]);
    }

    // --- Page --------------------------------------------------------------

    public function testThePageIsACompleteFrenchHtmlDocument(): void
    {
        $html = new ErrorPage()->render(404, 'Page introuvable', 'Texte.', ['Libellé <b>' => 'Valeur <i>'], 'Note <u>');

        self::assertStringStartsWith('<!DOCTYPE html><html lang="fr">', $html);
        self::assertStringContainsString('<meta charset="utf-8">', $html);
        self::assertStringContainsString('<title>404 — Page introuvable</title>', $html);
        self::assertStringContainsString('<dt>Libellé &lt;b&gt;</dt><dd>Valeur &lt;i&gt;</dd>', $html);
        self::assertStringContainsString('<p class="note">Note &lt;u&gt;</p>', $html);
    }

    public function testThePageWithoutDetailsHasNoEmptyBlocks(): void
    {
        $html = new ErrorPage()->render(404, 'Page introuvable', 'Texte.');

        self::assertStringNotContainsString('<dl>', $html);
        self::assertStringNotContainsString('class="note"', $html);
    }

    // --- Outils ------------------------------------------------------------

    private function handler(bool $development = false): ErrorHandler
    {
        return new ErrorHandler($development, $this->log);
    }

    /**
     * Une exception née dans une fonction qui a reçu un secret en argument.
     */
    private function failWithSecret(string $secret): \RuntimeException
    {
        return new \RuntimeException('panne' . substr($secret, 0, 0));
    }

    /**
     * @param array<string, string> $headers
     */
    private static function httpError(int $statusCode, array $headers = [], string $message = 'erreur'): HttpError
    {
        return new class ($message, $statusCode, $headers) extends \RuntimeException implements HttpError {
            /**
             * @param array<string, string> $headers
             */
            public function __construct(string $message, private readonly int $statusCode, private readonly array $headers)
            {
                parent::__construct($message);
            }

            public function getStatusCode(): int
            {
                return $this->statusCode;
            }

            public function getResponseHeaders(): array
            {
                return $this->headers;
            }
        };
    }
}
