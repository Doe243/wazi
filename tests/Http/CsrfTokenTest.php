<?php

declare(strict_types=1);

namespace Wazi\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wazi\Http\CsrfToken;

final class CsrfTokenTest extends TestCase
{
    private const string KNOWN = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    // --- Le visiteur a déjà un jeton ----------------------------------------

    public function testTheTokenOfTheCookieIsKept(): void
    {
        $token = self::started(self::KNOWN);

        self::assertSame(self::KNOWN, $token->value());
        self::assertNull($token->toSend(), 'Le navigateur a déjà ce jeton : rien à renvoyer.');
    }

    public function testTheFieldMustEqualTheCookie(): void
    {
        $token = self::started(self::KNOWN);

        self::assertTrue($token->matches(self::KNOWN));
        self::assertFalse($token->matches(str_repeat('a', 64)));
        self::assertFalse($token->matches(''));
        self::assertFalse($token->matches(strtoupper(self::KNOWN)));
        self::assertFalse($token->matches(self::KNOWN . ' '));
    }

    // --- Le visiteur n'a pas encore de jeton --------------------------------

    public function testWithoutCookieATokenIsCreatedOnlyWhenAsked(): void
    {
        $token = self::started(null);

        self::assertNull($token->toSend(), 'Tant qu\'aucun formulaire n\'en a besoin, il n\'y a pas de jeton.');

        $value = $token->value();

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $value);
        self::assertSame($value, $token->value(), 'Le même jeton pour tous les formulaires de la page.');
        self::assertSame($value, $token->toSend());
    }

    public function testEachVisitorGetsADifferentToken(): void
    {
        self::assertNotSame(self::started(null)->value(), self::started(null)->value());
    }

    /**
     * Sécurité : sans cookie, rien ne prouve que le formulaire vient d'une
     * page du site. Un jeton créé pendant cette même requête ne compte pas.
     */
    public function testATokenCreatedDuringThisRequestProvesNothing(): void
    {
        $token = self::started(null);
        $created = $token->value();

        self::assertFalse($token->matches($created));
        self::assertFalse($token->matches(''));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedCookies(): iterable
    {
        yield 'vide' => [''];
        yield 'trop court' => ['abc123'];
        yield 'trop long' => [self::KNOWN . 'a'];
        yield 'majuscules' => [strtoupper(self::KNOWN)];
        yield 'pas de l\'hexadécimal' => [str_repeat('z', 64)];
        yield 'saut de ligne final' => [self::KNOWN . "\n"];
        yield 'balise' => ['"><script>alert(1)</script>'];
        yield 'en-tête glissé' => ["abc\r\nSet-Cookie: session=pirate"];
    }

    /**
     * Sécurité : le cookie vient du navigateur, donc de n'importe qui. S'il
     * n'a pas la forme d'un jeton, il est traité comme absent : il n'est ni
     * comparé, ni recopié dans une page.
     */
    #[DataProvider('malformedCookies')]
    public function testAMalformedCookieIsTreatedAsAbsent(string $cookie): void
    {
        $token = self::started($cookie);

        self::assertFalse($token->matches($cookie));
        self::assertNotSame($cookie, $token->value());
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $token->value());
        self::assertSame($token->value(), $token->toSend());
    }

    // --- Cycle de vie ---------------------------------------------------------

    public function testBeforeStartNothingMatches(): void
    {
        $token = new CsrfToken();

        self::assertFalse($token->isStarted());
        self::assertFalse($token->matches(self::KNOWN));
        self::assertFalse($token->matches(''));
    }

    public function testStartForgetsThePreviousRequest(): void
    {
        $token = self::started(null);
        $first = $token->value();

        $token->start(self::KNOWN);

        self::assertTrue($token->isStarted());
        self::assertSame(self::KNOWN, $token->value());
        self::assertNull($token->toSend());
        self::assertFalse($token->matches($first));

        $token->start(null);

        self::assertFalse($token->matches(self::KNOWN));
        self::assertNotSame(self::KNOWN, $token->value());
    }

    private static function started(?string $fromCookie): CsrfToken
    {
        $token = new CsrfToken();
        $token->start($fromCookie);

        return $token;
    }
}
