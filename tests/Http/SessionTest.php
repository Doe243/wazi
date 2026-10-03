<?php

declare(strict_types=1);

namespace Wazi\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wazi\Http\Exception\SessionException;
use Wazi\Http\Session;

final class SessionTest extends TestCase
{
    private const string KNOWN_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    // --- Noter et relire ---------------------------------------------------

    public function testItRemembersValues(): void
    {
        $session = self::started();

        $session->set('user_id', 42);
        $session->set('panier', ['a' => 2, 'b' => ['x', null, true, 1.5]]);

        self::assertSame(42, $session->get('user_id'));
        self::assertTrue($session->has('user_id'));
        self::assertSame(['user_id' => 42, 'panier' => ['a' => 2, 'b' => ['x', null, true, 1.5]]], $session->all());
    }

    public function testAMissingKeyGivesTheDefault(): void
    {
        $session = self::started();

        self::assertNull($session->get('absente'));
        self::assertSame('défaut', $session->get('absente', 'défaut'));
        self::assertFalse($session->has('absente'));
    }

    public function testAKeySetToNullExists(): void
    {
        $session = self::started();
        $session->set('choix', null);

        self::assertTrue($session->has('choix'));
        self::assertNull($session->get('choix', 'défaut'));
    }

    public function testRemoveForgetsOneValue(): void
    {
        $session = self::started();
        $session->set('a', 1);
        $session->set('b', 2);

        $session->remove('a');
        $session->remove('jamais-notée');

        self::assertSame(['b' => 2], $session->all());
    }

    public function testItStartsWithWhatWasRemembered(): void
    {
        $session = new Session();
        $session->start(self::KNOWN_ID, ['user_id' => 42]);

        self::assertSame(42, $session->get('user_id'));
        self::assertSame(self::KNOWN_ID, $session->id());
        self::assertFalse($session->isNew());
        self::assertFalse($session->hasChanged());
    }

    public function testItKnowsWhetherSomethingChanged(): void
    {
        $session = self::started();

        self::assertFalse($session->hasChanged());

        $session->get('a');
        $session->has('a');
        $session->remove('a');

        self::assertFalse($session->hasChanged(), 'Lire, ou retirer une clé absente, ne change rien.');

        $session->set('a', 1);

        self::assertTrue($session->hasChanged());
    }

    // --- Ce qu'une session accepte -----------------------------------------

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unsupportedValues(): iterable
    {
        yield 'objet' => [new \stdClass()];
        yield 'date' => [new \DateTimeImmutable()];
        yield 'fonction' => [static fn(): null => null];
        yield 'objet dans un tableau' => [['a' => ['b' => new \stdClass()]]];
        yield 'nombre infini' => [INF];
        yield 'pas un nombre' => [NAN];
        yield 'texte qui n\'est pas de l\'UTF-8' => ["\xFF\xFE"];
    }

    /**
     * Sécurité : aucun objet n'entre en session, donc aucun objet n'en sort.
     */
    #[DataProvider('unsupportedValues')]
    public function testOnlySimpleValuesAreAccepted(mixed $value): void
    {
        $session = self::started();

        try {
            $session->set('cle', $value);
            self::fail('Une exception était attendue.');
        } catch (SessionException $exception) {
            self::assertStringContainsString('« cle »', $exception->getMessage());
            self::assertStringContainsString('user_id', $exception->getMessage());
        }

        self::assertFalse($session->has('cle'));
    }

    public function testKeysStartingWithAnUnderscoreAreReserved(): void
    {
        $session = self::started();

        $this->expectException(SessionException::class);
        $this->expectExceptionMessage('réservées');

        $session->set('_csrf', 'jeton choisi par le développeur');
    }

    // --- Sécurité : identifiant --------------------------------------------

    public function testANewSessionGetsARandomIdentifier(): void
    {
        $ids = [];

        for ($i = 0; $i < 20; $i++) {
            $ids[] = self::started()->id();
        }

        self::assertCount(20, array_unique($ids));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $ids[0]);
    }

    /**
     * Contre la fixation de session : après une connexion, l'identifiant
     * d'avant ne doit plus rien valoir.
     */
    public function testRegenerateChangesTheIdentifierAndKeepsTheContent(): void
    {
        $session = new Session();
        $session->start(self::KNOWN_ID, ['panier' => [1, 2]]);

        $session->regenerate();

        self::assertNotSame(self::KNOWN_ID, $session->id());
        self::assertTrue(Session::isValidId($session->id()));
        self::assertSame(self::KNOWN_ID, $session->discardedId());
        self::assertSame(['panier' => [1, 2]], $session->all());
        self::assertTrue($session->hasChanged());
    }

    public function testRegeneratingTwiceStillDiscardsTheIdentifierThatWasStored(): void
    {
        $session = new Session();
        $session->start(self::KNOWN_ID, []);

        $session->regenerate();
        $session->regenerate();

        self::assertSame(self::KNOWN_ID, $session->discardedId());
    }

    public function testRegeneratingANewSessionDiscardsNothing(): void
    {
        $session = self::started();
        $before = $session->id();

        $session->regenerate();

        self::assertNotSame($before, $session->id());
        self::assertNull($session->discardedId());
    }

    public function testClearForgetsEverythingAndChangesTheIdentifier(): void
    {
        $session = new Session();
        $session->start(self::KNOWN_ID, ['user_id' => 42, '_csrf' => 'ancien']);

        $session->clear();

        self::assertSame([], $session->all());
        self::assertNotSame(self::KNOWN_ID, $session->id());
        self::assertSame(self::KNOWN_ID, $session->discardedId());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidIds(): iterable
    {
        yield 'vide' => [''];
        yield 'trop court' => ['abc123'];
        yield 'majuscules' => [str_repeat('A', 64)];
        yield 'lettre hors hexadécimal' => [str_repeat('g', 64)];
        yield 'trop long' => [str_repeat('a', 65)];
        yield 'remontée de dossier' => ['../../../../etc/passwd'];
        yield 'remontée de dossier à la bonne longueur' => [str_pad('../', 64, 'a')];
        yield 'retour à la ligne final' => [str_repeat('a', 64) . "\n"];
        yield 'octet nul' => [str_repeat('a', 63) . "\0"];
    }

    #[DataProvider('invalidIds')]
    public function testOnlyAWellFormedIdentifierIsValid(string $id): void
    {
        self::assertFalse(Session::isValidId($id));
    }

    // --- Jeton de protection des formulaires --------------------------------

    public function testTheCsrfTokenIsCreatedOnceAndThenKept(): void
    {
        $session = self::started();

        $token = $session->csrfToken();

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $token);
        self::assertSame($token, $session->csrfToken());
        self::assertTrue($session->hasChanged());
        self::assertSame($token, $session->all()[Session::CSRF_FIELD]);
    }

    public function testTheCsrfTokenIsReadBackFromTheStoredSession(): void
    {
        $session = new Session();
        $session->start(self::KNOWN_ID, ['_csrf' => 'jeton-enregistré']);

        self::assertSame('jeton-enregistré', $session->csrfToken());
        self::assertFalse($session->hasChanged());
    }

    public function testEachSessionHasItsOwnCsrfToken(): void
    {
        self::assertNotSame(self::started()->csrfToken(), self::started()->csrfToken());
    }

    // --- Session non démarrée ----------------------------------------------

    /**
     * @return iterable<string, array{\Closure(Session): mixed}>
     */
    public static function operations(): iterable
    {
        yield 'get' => [static fn(Session $session): mixed => $session->get('a')];
        yield 'has' => [static fn(Session $session): bool => $session->has('a')];
        yield 'set' => [static fn(Session $session) => $session->set('a', 1)];
        yield 'remove' => [static fn(Session $session) => $session->remove('a')];
        yield 'all' => [static fn(Session $session): array => $session->all()];
        yield 'regenerate' => [static fn(Session $session) => $session->regenerate()];
        yield 'clear' => [static fn(Session $session) => $session->clear()];
        yield 'csrfToken' => [static fn(Session $session): string => $session->csrfToken()];
        yield 'id' => [static fn(Session $session): string => $session->id()];
    }

    /**
     * @param \Closure(Session): mixed $operation
     */
    #[DataProvider('operations')]
    public function testASessionThatWasNotStartedExplainsWhatToDo(\Closure $operation): void
    {
        $session = new Session();

        self::assertFalse($session->isStarted());

        $this->expectException(SessionException::class);
        $this->expectExceptionMessage('new Kernel(sessions:');

        $operation($session);
    }

    public function testErrorMessagesCannotForgeLogLines(): void
    {
        try {
            self::started()->set("cle\nFAUSSE LIGNE", new \stdClass());
            self::fail('Une exception était attendue.');
        } catch (SessionException $exception) {
            self::assertStringNotContainsString("\n", $exception->getMessage());
        }
    }

    private static function started(): Session
    {
        $session = new Session();
        $session->start(null);

        return $session;
    }
}
