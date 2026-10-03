<?php

declare(strict_types=1);

namespace Wazi\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wazi\Http\Exception\InvalidMessageException;
use Wazi\Http\Exception\RequestRejectedException;
use Wazi\Http\ServerRequestCreator;

/**
 * Ce que ServerRequestCreator croit des en-têtes X-Forwarded-*, selon que la
 * requête arrive ou non d'un proxy déclaré de confiance (ADR-022).
 */
final class TrustedProxiesTest extends TestCase
{
    /** Ce que voit PHP derrière un proxy qui a reçu « https://exemple.com/page » d'un visiteur. */
    private const array BEHIND_PROXY = [
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI' => '/page',
        'HTTP_HOST' => 'exemple.com',
        'REMOTE_ADDR' => '10.0.0.5',
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.7',
    ];

    // --- Derrière un proxy de confiance ------------------------------------

    public function testBehindATrustedProxyTheSchemeIsTheOneTheVisitorUsed(): void
    {
        $request = new ServerRequestCreator(trustedProxies: ['10.0.0.5'])->fromArrays(self::BEHIND_PROXY);

        self::assertSame('https://exemple.com/page', (string) $request->getUri());
    }

    public function testBehindATrustedProxyTheClientIpIsTheVisitors(): void
    {
        $request = new ServerRequestCreator(trustedProxies: ['10.0.0.0/8'])->fromArrays(self::BEHIND_PROXY);

        self::assertSame('203.0.113.7', $request->getAttribute(ServerRequestCreator::CLIENT_IP));
        self::assertSame('10.0.0.5', $request->getServerParams()['REMOTE_ADDR'], 'L\'adresse du proxy reste lisible.');
    }

    public function testTheHostAnnouncedByATrustedProxyIsUsed(): void
    {
        $server = ['HTTP_HOST' => 'interne:8080', 'HTTP_X_FORWARDED_HOST' => 'exemple.com'] + self::BEHIND_PROXY;

        $request = new ServerRequestCreator(trustedProxies: ['10.0.0.5'])->fromArrays($server);

        self::assertSame('https://exemple.com/page', (string) $request->getUri());
    }

    public function testSeveralProxiesInARow(): void
    {
        $server = ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '203.0.113.7, 10.0.0.9, 10.0.0.8'] + self::BEHIND_PROXY;

        $request = new ServerRequestCreator(trustedProxies: ['10.0.0.0/8'])->fromArrays($server);

        self::assertSame('203.0.113.7', $request->getAttribute(ServerRequestCreator::CLIENT_IP));
    }

    public function testAnIpv6ProxyIsRecognised(): void
    {
        $server = ['REMOTE_ADDR' => '2001:db8::5', 'HTTP_X_FORWARDED_FOR' => '2001:db8:1::7'] + self::BEHIND_PROXY;

        $request = new ServerRequestCreator(trustedProxies: ['2001:db8::/48'])->fromArrays($server);

        self::assertSame('https', $request->getUri()->getScheme());
        self::assertSame('2001:db8:1::7', $request->getAttribute(ServerRequestCreator::CLIENT_IP));
    }

    // --- Sécurité : sans confiance, rien n'est cru --------------------------

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function configurationsThatDoNotTrustTheSender(): iterable
    {
        yield 'aucun proxy déclaré' => [[]];
        yield 'un autre proxy' => [['10.0.0.6']];
        yield 'une autre plage' => [['192.168.0.0/16']];
    }

    /**
     * N'importe quel client peut écrire ces en-têtes. Ils ne comptent que
     * s'ils sont transmis par un proxy que VOUS avez déclaré.
     *
     * @param list<string> $trustedProxies
     */
    #[DataProvider('configurationsThatDoNotTrustTheSender')]
    public function testForwardedHeadersFromAnUntrustedSenderAreIgnored(array $trustedProxies): void
    {
        $server = ['HTTP_X_FORWARDED_HOST' => 'pirate.com', 'HTTP_X_FORWARDED_FOR' => '127.0.0.1'] + self::BEHIND_PROXY;

        $request = new ServerRequestCreator(trustedProxies: $trustedProxies)->fromArrays($server);

        self::assertSame('http://exemple.com/page', (string) $request->getUri());
        self::assertSame('10.0.0.5', $request->getAttribute(ServerRequestCreator::CLIENT_IP));
    }

    /**
     * Un client écrit lui-même un X-Forwarded-For pour se faire passer pour
     * une autre adresse (127.0.0.1, par exemple, pour paraître « local »).
     * Le proxy ajoute la vraie adresse à DROITE : c'est elle qui est retenue.
     */
    public function testAClientCannotForgeItsOwnAddress(): void
    {
        $server = ['HTTP_X_FORWARDED_FOR' => '127.0.0.1, 198.51.100.66, 203.0.113.7'] + self::BEHIND_PROXY;

        $request = new ServerRequestCreator(trustedProxies: ['10.0.0.5'])->fromArrays($server);

        self::assertSame('203.0.113.7', $request->getAttribute(ServerRequestCreator::CLIENT_IP));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function forwardedForValues(): iterable
    {
        yield 'entrée qui n\'est pas une adresse' => ['pirate.com', '10.0.0.5'];
        yield 'adresse avec un port' => ['203.0.113.7:4321', '10.0.0.5'];
        yield 'vide' => ['', '10.0.0.5'];
        yield 'uniquement des proxies de confiance' => ['10.0.0.9, 10.0.0.8', '10.0.0.5'];
        yield 'adresse invalide à droite d\'une adresse valide' => ['203.0.113.7, inconnu', '10.0.0.5'];
        yield 'espaces' => ['  203.0.113.7  ', '203.0.113.7'];
    }

    #[DataProvider('forwardedForValues')]
    public function testAMalformedForwardedForFallsBackToTheProxyAddress(string $forwardedFor, string $expected): void
    {
        $server = ['HTTP_X_FORWARDED_FOR' => $forwardedFor] + self::BEHIND_PROXY;

        $request = new ServerRequestCreator(trustedProxies: ['10.0.0.0/8'])->fromArrays($server);

        self::assertSame($expected, $request->getAttribute(ServerRequestCreator::CLIENT_IP));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function forwardedSchemes(): iterable
    {
        yield 'protocole inconnu' => ['javascript'];
        yield 'vide' => [''];
        yield 'avec les deux-points' => ['https:'];
    }

    #[DataProvider('forwardedSchemes')]
    public function testOnlyHttpAndHttpsAreAcceptedAsForwardedScheme(string $scheme): void
    {
        $server = ['HTTP_X_FORWARDED_PROTO' => $scheme] + self::BEHIND_PROXY;

        $request = new ServerRequestCreator(trustedProxies: ['10.0.0.5'])->fromArrays($server);

        self::assertSame('http', $request->getUri()->getScheme());
    }

    public function testTheFirstForwardedSchemeIsUsedWhenThereAreSeveral(): void
    {
        $server = ['HTTP_X_FORWARDED_PROTO' => 'HTTPS, http'] + self::BEHIND_PROXY;

        self::assertSame('https', new ServerRequestCreator(trustedProxies: ['10.0.0.5'])->fromArrays($server)->getUri()->getScheme());
    }

    /**
     * Même transmis par un proxy de confiance, un hôte passe les mêmes
     * contrôles que l'en-tête Host.
     */
    public function testAForwardedHostIsCheckedLikeAnyHost(): void
    {
        $creator = new ServerRequestCreator(trustedHosts: ['exemple.com'], trustedProxies: ['10.0.0.5']);

        foreach (['pirate.com', 'exemple.com/@pirate.com', 'exemple.com pirate.com'] as $host) {
            try {
                $creator->fromArrays(['HTTP_X_FORWARDED_HOST' => $host] + self::BEHIND_PROXY);
                self::fail('Une exception était attendue pour ' . $host);
            } catch (RequestRejectedException $exception) {
                self::assertSame(400, $exception->getStatusCode());
            }
        }
    }

    // --- Sans proxy --------------------------------------------------------

    public function testWithoutProxyTheClientIpIsTheConnectedAddress(): void
    {
        $request = new ServerRequestCreator()->fromArrays(['REMOTE_ADDR' => '203.0.113.7']);

        self::assertSame('203.0.113.7', $request->getAttribute(ServerRequestCreator::CLIENT_IP));
    }

    public function testWithoutRemoteAddressTheClientIpIsEmpty(): void
    {
        self::assertSame('', new ServerRequestCreator(trustedProxies: ['10.0.0.5'])->fromArrays([])->getAttribute(ServerRequestCreator::CLIENT_IP));
    }

    // --- Déclaration -------------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidProxies(): iterable
    {
        yield 'nom d\'hôte' => ['proxy.exemple.com'];
        yield 'joker' => ['*'];
        yield 'plage impossible' => ['10.0.0.0/40'];
        yield 'vide' => [''];
    }

    #[DataProvider('invalidProxies')]
    public function testAnInvalidProxyAddressIsRejectedWhenDeclared(string $proxy): void
    {
        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('10.0.0.0/8');

        new ServerRequestCreator(trustedProxies: [$proxy]);
    }
}
