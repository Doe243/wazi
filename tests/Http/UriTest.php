<?php

declare(strict_types=1);

namespace Wazi\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UriInterface;
use Wazi\Http\Exception\InvalidUriException;
use Wazi\Http\Uri;

final class UriTest extends TestCase
{
    // --- Lecture -----------------------------------------------------------

    public function testItReadsEveryComponent(): void
    {
        $uri = new Uri('https://alice:secret@exemple.com:8080/articles?page=2#commentaires');

        self::assertSame('https', $uri->getScheme());
        self::assertSame('alice:secret', $uri->getUserInfo());
        self::assertSame('exemple.com', $uri->getHost());
        self::assertSame(8080, $uri->getPort());
        self::assertSame('alice:secret@exemple.com:8080', $uri->getAuthority());
        self::assertSame('/articles', $uri->getPath());
        self::assertSame('page=2', $uri->getQuery());
        self::assertSame('commentaires', $uri->getFragment());
    }

    public function testItImplementsPsr7(): void
    {
        self::assertInstanceOf(UriInterface::class, new Uri());
    }

    public function testAnEmptyUriIsValid(): void
    {
        $uri = new Uri('');

        self::assertSame('', (string) $uri);
        self::assertSame('', $uri->getHost());
        self::assertNull($uri->getPort());
    }

    public function testSchemeAndHostAreLowercased(): void
    {
        $uri = new Uri('HTTPS://EXEMPLE.COM/Chemin');

        self::assertSame('https', $uri->getScheme());
        self::assertSame('exemple.com', $uri->getHost());
        self::assertSame('/Chemin', $uri->getPath(), 'Le chemin, lui, est sensible à la casse.');
    }

    public function testStandardPortIsHidden(): void
    {
        $uri = new Uri('https://exemple.com:443/');

        self::assertNull($uri->getPort());
        self::assertSame('exemple.com', $uri->getAuthority());
        self::assertSame('https://exemple.com/', (string) $uri);
    }

    public function testIpv6HostKeepsItsBrackets(): void
    {
        $uri = new Uri('http://[::1]:8080/');

        self::assertSame('[::1]', $uri->getHost());
        self::assertSame(8080, $uri->getPort());
    }

    public function testEncodedCharactersAreKeptAsWritten(): void
    {
        $uri = new Uri('https://exemple.com/caf%C3%A9?q=a%20b');

        self::assertSame('/caf%C3%A9', $uri->getPath());
        self::assertSame('q=a%20b', $uri->getQuery());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validUris(): iterable
    {
        yield 'complète' => ['https://alice:secret@exemple.com:8080/articles?page=2#top'];
        yield 'sans schéma' => ['//exemple.com/page'];
        yield 'chemin seul' => ['/articles/42'];
        yield 'chemin relatif' => ['articles/42'];
        yield 'requête seule' => ['?page=2'];
        yield 'fragment seul' => ['#haut'];
        yield 'mailto' => ['mailto:contact@exemple.com'];
        yield 'urn' => ['urn:isbn:9782070612758'];
    }

    #[DataProvider('validUris')]
    public function testItConvertsBackToTheSameString(string $value): void
    {
        self::assertSame($value, (string) new Uri($value));
    }

    // --- URI invalides -----------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidUris(): iterable
    {
        yield 'espace' => ['https://exemple.com/mon article'];
        yield 'schéma commençant par un chiffre' => ['1http://exemple.com'];
        yield 'hôte avec espace' => ['http://exe mple.com'];
        yield 'port non numérique' => ['http://exemple.com:abc/'];
    }

    #[DataProvider('invalidUris')]
    public function testItRejectsAnInvalidUri(string $value): void
    {
        $this->expectException(InvalidUriException::class);

        new Uri($value);
    }

    public function testTheExceptionIsAnInvalidArgumentExceptionAsPsr7Requires(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Uri('http://exemple.com/a b');
    }

    public function testTheErrorMessageExplainsHowToFixIt(): void
    {
        try {
            new Uri('https://exemple.com/mon article');
            self::fail('Une exception était attendue.');
        } catch (InvalidUriException $exception) {
            self::assertStringContainsString('%20', $exception->getMessage());
        }
    }

    public function testTheErrorMessageCannotBeUsedToForgeLogLines(): void
    {
        try {
            new Uri("http://exemple.com/\nFAUSSE LIGNE DE JOURNAL");
            self::fail('Une exception était attendue.');
        } catch (InvalidUriException $exception) {
            self::assertStringNotContainsString("\n", $exception->getMessage());
        }
    }

    // --- Immuabilité -------------------------------------------------------

    public function testWithersReturnANewUriAndLeaveTheOriginalUntouched(): void
    {
        $original = new Uri('https://exemple.com/accueil');

        $copy = $original->withPath('/contact');

        self::assertNotSame($original, $copy);
        self::assertSame('/accueil', $original->getPath());
        self::assertSame('/contact', $copy->getPath());
    }

    /**
     * @return iterable<string, array{string, list<string|int|null>}>
     */
    public static function witherCalls(): iterable
    {
        yield 'withScheme' => ['withScheme', ['http']];
        yield 'withUserInfo' => ['withUserInfo', ['alice']];
        yield 'withHost' => ['withHost', ['autre.com']];
        yield 'withPort' => ['withPort', [8080]];
        yield 'withPath' => ['withPath', ['/contact']];
        yield 'withQuery' => ['withQuery', ['page=2']];
        yield 'withFragment' => ['withFragment', ['haut']];
    }

    /**
     * L'erreur la plus fréquente avec un objet immuable : appeler
     * $uri->withPath('/contact') sans récupérer le résultat.
     * Grâce à #[\NoDiscard], PHP avertit le développeur.
     *
     * @param list<string|int|null> $arguments
     */
    #[DataProvider('witherCalls')]
    public function testIgnoringTheResultOfAWitherTriggersAWarning(string $method, array $arguments): void
    {
        $uri = new Uri('https://exemple.com/');
        $warning = '';

        set_error_handler(static function (int $level, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            $uri->{$method}(...$arguments);
        } finally {
            restore_error_handler();
        }

        self::assertStringContainsString('immuable', $warning);
    }

    // --- Modification ------------------------------------------------------

    public function testWithSchemeLowercases(): void
    {
        self::assertSame('http', new Uri('https://exemple.com')->withScheme('HTTP')->getScheme());
    }

    public function testWithSchemeRejectsAnInvalidScheme(): void
    {
        $this->expectException(InvalidUriException::class);

        (void) new Uri('https://exemple.com')->withScheme('1http');
    }

    public function testWithUserInfoEncodesSpecialCharacters(): void
    {
        $uri = new Uri('https://exemple.com')->withUserInfo('alice@maison', 'mot de:passe');

        self::assertSame('alice%40maison:mot%20de:passe', $uri->getUserInfo());
    }

    public function testWithUserInfoIgnoresThePasswordWithoutUser(): void
    {
        self::assertSame('', new Uri('https://exemple.com')->withUserInfo('', 'secret')->getUserInfo());
    }

    public function testWithHostRejectsAnInvalidHost(): void
    {
        $this->expectException(InvalidUriException::class);

        (void) new Uri('https://exemple.com')->withHost('exe mple.com');
    }

    public function testWithPortRejectsAnOutOfRangePort(): void
    {
        $this->expectException(InvalidUriException::class);

        (void) new Uri('https://exemple.com')->withPort(70000);
    }

    public function testWithPortNullRemovesThePort(): void
    {
        $uri = new Uri('https://exemple.com:8080')->withPort(null);

        self::assertNull($uri->getPort());
        self::assertSame('https://exemple.com', (string) $uri);
    }

    public function testWithPathEncodesSpacesWithoutDoubleEncoding(): void
    {
        $uri = new Uri('https://exemple.com');

        self::assertSame('/mon%20article', $uri->withPath('/mon article')->getPath());
        self::assertSame('/mon%20article', $uri->withPath('/mon%20article')->getPath());
    }

    public function testWithQueryEncodesSpecialCharacters(): void
    {
        $uri = new Uri('https://exemple.com')->withQuery('q=café crème&page=2');

        self::assertSame('q=caf%C3%A9%20cr%C3%A8me&page=2', $uri->getQuery());
    }

    public function testWithFragmentEncodesSpecialCharacters(): void
    {
        self::assertSame('section%201', new Uri('https://exemple.com')->withFragment('section 1')->getFragment());
    }

    // --- Règles d'écriture de PSR-7 ---------------------------------------

    public function testARelativePathGetsASlashWhenThereIsAnAuthority(): void
    {
        $uri = new Uri('https://exemple.com')->withPath('articles');

        self::assertSame('https://exemple.com/articles', (string) $uri);
    }

    public function testLeadingSlashesAreReducedWithoutAuthority(): void
    {
        $uri = new Uri('/accueil')->withPath('//exemple.com/piege');

        self::assertSame('/exemple.com/piege', (string) $uri);
    }
}
