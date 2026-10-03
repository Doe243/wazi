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

        $session->set('_interne', 'valeur choisie par le développeur');
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
        $session->start(self::KNOWN_ID, ['user_id' => 42, 'panier' => [3, 7]]);

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

    // --- Messages pour la page suivante ------------------------------------

    public function testAFlashMessageIsReadOnce(): void
    {
        $session = self::started();
        $session->flash('succes', 'Note ajoutée.');

        // La requête suivante retrouve ce qui a été enregistré.
        $next = new Session();
        $next->start(self::KNOWN_ID, $session->all());

        self::assertFalse($next->hasChanged());
        self::assertSame('Note ajoutée.', $next->takeFlash('succes'));
        self::assertTrue($next->hasChanged(), 'Le message lu doit être effacé du fichier.');
        self::assertNull($next->takeFlash('succes'), 'Un message ne s\'affiche qu\'une fois.');
        self::assertSame([], $next->all(), 'Il ne reste rien à enregistrer.');
    }

    public function testAFlashMessageWaitsUntilItIsRead(): void
    {
        $session = self::started();
        $session->flash('succes', 'Note ajoutée.');

        // Une requête passe sans lire le message (une image, une autre page).
        $between = new Session();
        $between->start(self::KNOWN_ID, $session->all());
        $between->set('vu', true);

        $next = new Session();
        $next->start(self::KNOWN_ID, $between->all());

        self::assertSame('Note ajoutée.', $next->takeFlash('succes'));
        self::assertSame(['vu' => true], $next->all());
    }

    public function testAFlashMessageCanBeReadInTheSameRequest(): void
    {
        $session = self::started();
        $session->flash('erreur', 'Le texte est vide.');

        self::assertSame('Le texte est vide.', $session->takeFlash('erreur'));
        self::assertSame([], $session->all());
    }

    public function testEachFlashMessageHasItsOwnKey(): void
    {
        $session = self::started();
        $session->flash('succes', 'Enregistré.');
        $session->flash('erreurs', ['nom' => 'obligatoire']);
        $session->flash('succes', 'Enregistré, vraiment.');

        self::assertSame(['nom' => 'obligatoire'], $session->takeFlash('erreurs'));
        self::assertSame('Enregistré, vraiment.', $session->takeFlash('succes'));
    }

    public function testAMissingFlashMessageGivesTheDefaultAndChangesNothing(): void
    {
        $session = new Session();
        $session->start(self::KNOWN_ID, ['user_id' => 42]);

        self::assertNull($session->takeFlash('absent'));
        self::assertSame('rien', $session->takeFlash('absent', 'rien'));
        self::assertFalse($session->hasChanged());
    }

    public function testAFlashMessageDoesNotMixWithOrdinaryValues(): void
    {
        $session = self::started();
        $session->set('succes', 'valeur ordinaire');
        $session->flash('succes', 'message');

        self::assertSame('valeur ordinaire', $session->get('succes'));
        self::assertSame('message', $session->takeFlash('succes'));
        self::assertSame('valeur ordinaire', $session->get('succes'));
    }

    #[DataProvider('unsupportedValues')]
    public function testAFlashMessageIsASimpleValueToo(mixed $value): void
    {
        $session = self::started();

        $this->expectException(SessionException::class);

        $session->flash('succes', $value);
    }

    public function testClearForgetsFlashMessages(): void
    {
        $session = self::started();
        $session->flash('succes', 'Enregistré.');

        $session->clear();

        self::assertNull($session->takeFlash('succes'));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function damagedInternalValues(): iterable
    {
        yield 'texte' => ['abc'];
        yield 'nombre' => [12];
        yield 'null' => [null];
        yield 'vrai' => [true];
    }

    /**
     * Le contenu vient d'un fichier : s'il a été abîmé, il est ignoré.
     */
    #[DataProvider('damagedInternalValues')]
    public function testDamagedFlashMessagesAreIgnored(mixed $stored): void
    {
        $session = new Session();
        $session->start(self::KNOWN_ID, ['_flash' => $stored]);

        self::assertSame('défaut', $session->takeFlash('succes', 'défaut'));

        $session->flash('succes', 'Enregistré.');

        self::assertSame('Enregistré.', $session->takeFlash('succes'));
    }

    // --- « Se souvenir de moi » ---------------------------------------------

    public function testAnOrdinarySessionHasNoLifetimeOfItsOwn(): void
    {
        self::assertNull(self::started()->lifetime());
    }

    public function testRememberGivesTheSessionALifetimeInDays(): void
    {
        $session = self::started();

        $session->remember();

        self::assertSame(30 * 86400, $session->lifetime());
        self::assertTrue($session->hasChanged());

        $session->remember(7);

        self::assertSame(7 * 86400, $session->lifetime());
    }

    public function testTheLifetimeIsFoundAgainOnTheNextRequest(): void
    {
        $session = self::started();
        $session->remember(7);

        $next = new Session();
        $next->start(self::KNOWN_ID, $session->all());

        self::assertSame(7 * 86400, $next->lifetime());
    }

    public function testRegenerateKeepsTheLifetimeAndClearEndsIt(): void
    {
        $session = self::started();
        $session->remember(7);

        $session->regenerate();

        self::assertSame(7 * 86400, $session->lifetime());

        $session->clear();

        self::assertNull($session->lifetime(), 'Se déconnecter met fin à « se souvenir de moi ».');
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidDurations(): iterable
    {
        yield 'zéro' => [0];
        yield 'négative' => [-1];
        yield 'plus d\'un an' => [366];
        yield 'démesurée' => [PHP_INT_MAX];
    }

    #[DataProvider('invalidDurations')]
    public function testRememberRefusesADurationOutOfBounds(int $days): void
    {
        $session = self::started();

        try {
            $session->remember($days);
            self::fail('Une exception était attendue.');
        } catch (SessionException $exception) {
            self::assertStringContainsString('entre 1 et 365 jours', $exception->getMessage());
        }

        self::assertNull($session->lifetime());
        self::assertFalse($session->hasChanged());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function damagedLifetimes(): iterable
    {
        yield 'texte' => ['2592000'];
        yield 'éternelle' => [PHP_INT_MAX];
        yield 'plus d\'un an' => [366 * 86400];
        yield 'négative' => [-86400];
        yield 'nulle' => [0];
        yield 'moins d\'un jour' => [60];
        yield 'tableau' => [[86400]];
        yield 'décimale' => [86400.5];
        yield 'vrai' => [true];
    }

    /**
     * Sécurité : la durée est relue d'un fichier. Une valeur hors des bornes
     * ne doit jamais donner une session qui n'expire pas.
     */
    #[DataProvider('damagedLifetimes')]
    public function testADamagedLifetimeIsIgnored(mixed $stored): void
    {
        $session = new Session();
        $session->start(self::KNOWN_ID, ['_remember' => $stored]);

        self::assertNull($session->lifetime());
    }

    public function testInternalKeysCannotBeSetDirectly(): void
    {
        $session = self::started();

        foreach (['_flash', '_remember'] as $key) {
            try {
                $session->set($key, PHP_INT_MAX);
                self::fail('Une exception était attendue.');
            } catch (SessionException $exception) {
                self::assertStringContainsString('réservées', $exception->getMessage());
            }
        }

        self::assertNull($session->lifetime());
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
        yield 'id' => [static fn(Session $session): string => $session->id()];
        yield 'flash' => [static fn(Session $session) => $session->flash('a', 1)];
        yield 'takeFlash' => [static fn(Session $session): mixed => $session->takeFlash('a')];
        yield 'remember' => [static fn(Session $session) => $session->remember()];
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
