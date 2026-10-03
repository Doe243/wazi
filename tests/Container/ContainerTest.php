<?php

declare(strict_types=1);

namespace Wazi\Tests\Container;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Wazi\Container\Container;
use Wazi\Container\Exception\ContainerException;
use Wazi\Container\Exception\ServiceNotFoundException;
use Wazi\Tests\Container\Fixtures\AbstractReport;
use Wazi\Tests\Container\Fixtures\CircularA;
use Wazi\Tests\Container\Fixtures\CircularB;
use Wazi\Tests\Container\Fixtures\Clock;
use Wazi\Tests\Container\Fixtures\FakeMailer;
use Wazi\Tests\Container\Fixtures\Mailer;
use Wazi\Tests\Container\Fixtures\NeedsText;
use Wazi\Tests\Container\Fixtures\Newsletter;
use Wazi\Tests\Container\Fixtures\NullableDependency;
use Wazi\Tests\Container\Fixtures\PrivateConstructor;
use Wazi\Tests\Container\Fixtures\SmtpMailer;
use Wazi\Tests\Container\Fixtures\Status;
use Wazi\Tests\Container\Fixtures\UnionDependency;
use Wazi\Tests\Container\Fixtures\WithDefaults;

final class ContainerTest extends TestCase
{
    public function testItImplementsPsr11(): void
    {
        self::assertInstanceOf(ContainerInterface::class, new Container());
    }

    // --- Autowiring --------------------------------------------------------

    public function testItBuildsAClassWithoutConstructor(): void
    {
        self::assertInstanceOf(Clock::class, new Container()->get(Clock::class));
    }

    public function testItBuildsAClassAndItsDependencies(): void
    {
        $container = new Container();
        $container->bind(Mailer::class, FakeMailer::class);

        $newsletter = $container->get(Newsletter::class);

        self::assertInstanceOf(Newsletter::class, $newsletter);
        self::assertInstanceOf(FakeMailer::class, $newsletter->mailer);
        self::assertInstanceOf(Clock::class, $newsletter->clock);
    }

    public function testAServiceIsBuiltOnlyOnce(): void
    {
        $container = new Container();
        $container->bind(Mailer::class, FakeMailer::class);

        self::assertSame($container->get(Clock::class), $container->get(Clock::class));
        self::assertSame($container->get(Clock::class), $container->get(Newsletter::class)->clock);
    }

    public function testDefaultValuesAreUsedForWhatCannotBeGuessed(): void
    {
        $service = new Container()->get(WithDefaults::class);

        self::assertInstanceOf(Clock::class, $service->clock);
        self::assertSame(10, $service->limit);
        self::assertNull($service->mailer, 'Aucune liaison pour Mailer : la valeur par défaut est gardée.');
    }

    public function testAnOptionalDependencyIsProvidedWhenTheContainerKnowsIt(): void
    {
        $container = new Container();
        $container->bind(Mailer::class, FakeMailer::class);

        self::assertInstanceOf(FakeMailer::class, $container->get(WithDefaults::class)->mailer);
    }

    public function testANullableDependencyWithoutBindingIsNull(): void
    {
        self::assertNull(new Container()->get(NullableDependency::class)->mailer);
    }

    // --- Recettes ----------------------------------------------------------

    public function testARecipeBuildsWhatCannotBeGuessed(): void
    {
        $container = new Container();
        $container->set(SmtpMailer::class, static fn(): SmtpMailer => new SmtpMailer('smtp.exemple.com'));

        self::assertSame('smtp.exemple.com', $container->get(SmtpMailer::class)->host);
    }

    public function testARecipeIsRunOnceAndOnlyWhenNeeded(): void
    {
        $runs = 0;
        $container = new Container();
        $container->set(Clock::class, static function () use (&$runs): Clock {
            $runs++;

            return new Clock();
        });

        self::assertSame(0, $runs, 'Une recette ne s\'exécute pas tant que personne ne demande le service.');

        $container->get(Clock::class);
        $container->get(Clock::class);

        self::assertSame(1, $runs);
    }

    public function testARecipeReceivesTheContainer(): void
    {
        $container = new Container();
        $container->set(Mailer::class, static fn(): Mailer => new FakeMailer());
        $container->set(Newsletter::class, static fn(Container $c): Newsletter => new Newsletter(
            $c->get(Mailer::class),
            $c->get(Clock::class),
        ));

        self::assertInstanceOf(FakeMailer::class, $container->get(Newsletter::class)->mailer);
    }

    public function testAServiceCanHaveAnyNameAndAnyValue(): void
    {
        $container = new Container();
        $container->set('reglages.pagination', static fn(): array => ['par_page' => 20]);
        $container->set('rien', static fn(): null => null);

        self::assertSame(['par_page' => 20], $container->get('reglages.pagination'));
        self::assertNull($container->get('rien'));
        self::assertTrue($container->has('rien'));
    }

    public function testTheLastDefinitionWins(): void
    {
        $container = new Container();
        $container->bind(Mailer::class, FakeMailer::class);
        $container->set(Mailer::class, static fn(): Mailer => new SmtpMailer('smtp.exemple.com'));

        self::assertInstanceOf(SmtpMailer::class, $container->get(Mailer::class));

        $other = new Container();
        $other->set(Mailer::class, static fn(): Mailer => new SmtpMailer('smtp.exemple.com'));
        $other->bind(Mailer::class, FakeMailer::class);

        self::assertInstanceOf(FakeMailer::class, $other->get(Mailer::class));
    }

    public function testAServiceAlreadyBuiltCannotBeRedefined(): void
    {
        $container = new Container();
        $container->get(Clock::class);

        try {
            $container->set(Clock::class, static fn(): Clock => new Clock());
            self::fail('Une exception était attendue.');
        } catch (ContainerException $exception) {
            self::assertStringContainsString('déjà été fabriqué', $exception->getMessage());
        }

        $this->expectException(ContainerException::class);

        $container->bind(Clock::class, Clock::class);
    }

    // --- Liaisons ----------------------------------------------------------

    public function testABindingSharesTheInstanceOfItsClass(): void
    {
        $container = new Container();
        $container->bind(Mailer::class, FakeMailer::class);

        self::assertSame($container->get(FakeMailer::class), $container->get(Mailer::class));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidBindings(): iterable
    {
        yield 'classe inexistante' => [Mailer::class, 'Wazi\Tests\Container\Fixtures\Inexistante'];
        yield 'classe qui n\'implémente pas l\'interface' => [Mailer::class, Clock::class];
        yield 'interface à la place d\'une classe' => [Mailer::class, Mailer::class];
        yield 'texte quelconque' => [Mailer::class, '../../etc/passwd'];
    }

    #[DataProvider('invalidBindings')]
    public function testAnInvalidBindingIsRejectedImmediately(string $id, string $class): void
    {
        $this->expectException(ContainerException::class);

        new Container()->bind($id, $class);
    }

    // --- has() -------------------------------------------------------------

    public function testHasTellsWhatTheContainerCanProvide(): void
    {
        $container = new Container();
        $container->set('service.nomme', static fn(): Clock => new Clock());
        $container->bind(Mailer::class, FakeMailer::class);

        self::assertTrue($container->has('service.nomme'));
        self::assertTrue($container->has(Mailer::class));
        self::assertTrue($container->has(Clock::class), 'Une classe existante peut être fabriquée.');
        self::assertFalse($container->has('service.inconnu'));
        self::assertFalse($container->has('Wazi\Tests\Container\Fixtures\Inexistante'));
    }

    public function testAnInterfaceWithoutBindingIsNotAvailable(): void
    {
        self::assertFalse(new Container()->has(Mailer::class));
    }

    // --- Erreurs pédagogiques ----------------------------------------------

    public function testAnUnknownServiceIsExplained(): void
    {
        try {
            new Container()->get('App\Service\Inexistant');
            self::fail('Une exception était attendue.');
        } catch (ServiceNotFoundException $exception) {
            self::assertInstanceOf(NotFoundExceptionInterface::class, $exception);
            self::assertStringContainsString('App\Service\Inexistant', $exception->getMessage());
            self::assertStringContainsString('$container->set', $exception->getMessage());
        }
    }

    public function testAnInterfaceWithoutBindingIsExplained(): void
    {
        try {
            new Container()->get(Newsletter::class);
            self::fail('Une exception était attendue.');
        } catch (ServiceNotFoundException $exception) {
            self::assertStringContainsString(Mailer::class, $exception->getMessage());
            self::assertStringContainsString('$container->bind(Mailer::class', $exception->getMessage());
        }
    }

    public function testAParameterThatCannotBeGuessedIsExplained(): void
    {
        try {
            new Container()->get(NeedsText::class);
            self::fail('Une exception était attendue.');
        } catch (ContainerException $exception) {
            self::assertInstanceOf(ContainerExceptionInterface::class, $exception);
            self::assertStringContainsString(NeedsText::class, $exception->getMessage());
            self::assertStringContainsString('$dsn (string)', $exception->getMessage());
            self::assertStringContainsString('$container->set(NeedsText::class', $exception->getMessage());
        }
    }

    public function testAUnionTypeIsNotGuessed(): void
    {
        try {
            new Container()->get(UnionDependency::class);
            self::fail('Une exception était attendue.');
        } catch (ContainerException $exception) {
            self::assertStringContainsString('$dependency', $exception->getMessage());
        }
    }

    public function testACircularDependencyIsDetectedAndShown(): void
    {
        try {
            new Container()->get(CircularA::class);
            self::fail('Une exception était attendue.');
        } catch (ContainerException $exception) {
            self::assertStringContainsString(
                CircularA::class . ' → ' . CircularB::class . ' → ' . CircularA::class,
                $exception->getMessage(),
            );
        }
    }

    public function testTheContainerStillWorksAfterAFailure(): void
    {
        $container = new Container();

        try {
            $container->get(CircularA::class);
        } catch (ContainerException) {
            // L'échec ne doit pas laisser le conteneur croire que CircularA est « en cours ».
        }

        try {
            $container->get(CircularA::class);
            self::fail('Une exception était attendue.');
        } catch (ContainerException $exception) {
            self::assertStringStartsWith('Dépendance circulaire : ' . CircularA::class, $exception->getMessage());
        }

        self::assertInstanceOf(Clock::class, $container->get(Clock::class));
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function classesThatCannotBeBuilt(): iterable
    {
        yield 'classe abstraite' => [AbstractReport::class];
        yield 'constructeur privé' => [PrivateConstructor::class];
        yield 'énumération' => [Status::class];
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('classesThatCannotBeBuilt')]
    public function testAClassThatCannotBeBuiltIsExplained(string $class): void
    {
        try {
            new Container()->get($class);
            self::fail('Une exception était attendue.');
        } catch (ContainerException $exception) {
            self::assertStringContainsString($class, $exception->getMessage());
            self::assertStringContainsString('$container->set', $exception->getMessage());
        }
    }

    // --- Sécurité ----------------------------------------------------------

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function phpInternalClasses(): iterable
    {
        yield 'fichier' => [\SplFileObject::class];
        yield 'liste' => [\ArrayObject::class];
        yield 'objet vide' => [\stdClass::class];
        yield 'base de données' => [\PDO::class];
        yield 'exception' => [\Exception::class];
        yield 'itérateur de dossier' => [\DirectoryIterator::class];
    }

    /**
     * Si un nom de classe venait un jour d'une requête, le visiteur ne doit
     * pas pouvoir faire fabriquer une classe de PHP.
     *
     * @param class-string $class
     */
    #[DataProvider('phpInternalClasses')]
    public function testAPhpInternalClassIsNeverBuiltAutomatically(string $class): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('fournie par PHP');

        new Container()->get($class);
    }

    public function testAPhpInternalClassCanStillBeRegisteredExplicitly(): void
    {
        $container = new Container();
        $container->set(\ArrayObject::class, static fn(): \ArrayObject => new \ArrayObject([1, 2]));

        self::assertCount(2, $container->get(\ArrayObject::class));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function identifiersThatAreNotClassNames(): iterable
    {
        yield 'chemin' => ['../../etc/passwd'];
        yield 'adresse à protocole' => ['phar://piege.phar/Classe'];
        yield 'retour à la ligne' => ["Classe\nFAUSSE LIGNE DE JOURNAL"];
        yield 'octet nul' => ["Classe\0"];
        yield 'barre oblique inversée au début' => ['\Wazi\Tests\Container\Fixtures\Clock'];
        yield 'espace' => ['Ma Classe'];
        yield 'vide' => [''];
    }

    /**
     * Un identifiant qui n'a pas la forme d'un nom de classe n'est jamais
     * transmis au chargeur de classes, ni recopié dans le message d'erreur.
     */
    #[DataProvider('identifiersThatAreNotClassNames')]
    public function testAnIdentifierThatIsNotAClassNameNeverReachesTheAutoloader(string $id): void
    {
        $asked = [];
        $spy = static function (string $class) use (&$asked): void {
            $asked[] = $class;
        };
        spl_autoload_register($spy, true, true);

        try {
            $container = new Container();

            self::assertFalse($container->has($id));

            try {
                $container->get($id);
                self::fail('Une exception était attendue.');
            } catch (ServiceNotFoundException $exception) {
                self::assertStringContainsString('(identifiant invalide)', $exception->getMessage());
                self::assertStringNotContainsString("\n", $exception->getMessage());
            }
        } finally {
            spl_autoload_unregister($spy);
        }

        self::assertSame([], $asked);
    }
}
