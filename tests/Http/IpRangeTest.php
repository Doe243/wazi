<?php

declare(strict_types=1);

namespace Wazi\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Wazi\Http\IpRange;

final class IpRangeTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function cases(): iterable
    {
        yield 'adresse seule : elle-même' => ['203.0.113.7', '203.0.113.7', true];
        yield 'adresse seule : une autre' => ['203.0.113.7', '203.0.113.8', false];
        yield '/8 : dedans' => ['10.0.0.0/8', '10.255.3.4', true];
        yield '/8 : dehors' => ['10.0.0.0/8', '11.0.0.1', false];
        yield '/24 : dedans' => ['192.168.1.0/24', '192.168.1.200', true];
        yield '/24 : dehors' => ['192.168.1.0/24', '192.168.2.1', false];
        yield '/20, taille non alignée sur un octet : dedans' => ['172.16.16.0/20', '172.16.31.255', true];
        yield '/20 : juste dehors' => ['172.16.16.0/20', '172.16.32.0', false];
        yield '/31 : dedans' => ['203.0.113.6/31', '203.0.113.7', true];
        yield '/31 : dehors' => ['203.0.113.6/31', '203.0.113.8', false];
        yield '/32 : une seule adresse' => ['203.0.113.7/32', '203.0.113.7', true];
        yield '/0 : tout' => ['0.0.0.0/0', '198.51.100.9', true];
        yield 'IPv6 seule' => ['2001:db8::1', '2001:db8::1', true];
        yield 'IPv6, autre écriture de la même adresse' => ['2001:db8::1', '2001:0db8:0000:0000:0000:0000:0000:0001', true];
        yield 'IPv6 /32 : dedans' => ['2001:db8::/32', '2001:db8:ffff::1', true];
        yield 'IPv6 /32 : dehors' => ['2001:db8::/32', '2001:db9::1', false];
        yield 'boucle locale IPv6' => ['::1', '::1', true];
        yield 'IPv4 jamais dans une plage IPv6' => ['::/0', '203.0.113.7', false];
        yield 'IPv6 jamais dans une plage IPv4' => ['0.0.0.0/0', '2001:db8::1', false];
        yield 'IPv4 écrite en IPv6, reconnue dans une plage IPv4' => ['10.0.0.0/8', '::ffff:10.1.2.3', true];
        yield 'ce qui n\'est pas une adresse' => ['10.0.0.0/8', 'pirate.com', false];
        yield 'adresse avec un port' => ['10.0.0.0/8', '10.0.0.1:8080', false];
        yield 'vide' => ['10.0.0.0/8', '', false];
    }

    #[DataProvider('cases')]
    public function testItTellsWhetherAnAddressIsInTheRange(string $range, string $ip, bool $expected): void
    {
        $ipRange = IpRange::fromString($range);

        self::assertNotNull($ipRange);
        self::assertSame($expected, $ipRange->contains($ip));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidRanges(): iterable
    {
        yield 'vide' => [''];
        yield 'nom d\'hôte' => ['proxy.exemple.com'];
        yield 'joker' => ['*'];
        yield 'adresse incomplète' => ['10.0.0'];
        yield 'taille trop grande pour IPv4' => ['10.0.0.0/33'];
        yield 'taille trop grande pour IPv6' => ['2001:db8::/129'];
        yield 'taille négative' => ['10.0.0.0/-1'];
        yield 'taille en texte' => ['10.0.0.0/huit'];
        yield 'taille vide' => ['10.0.0.0/'];
        yield 'deux barres' => ['10.0.0.0/8/8'];
        yield 'espace' => [' 10.0.0.1'];
    }

    #[DataProvider('invalidRanges')]
    public function testAnInvalidRangeIsRefused(string $range): void
    {
        self::assertNull(IpRange::fromString($range));
    }
}
