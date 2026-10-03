<?php

declare(strict_types=1);

namespace Wazi\Http;

/**
 * Une adresse IP, ou une plage d'adresses écrite « adresse/taille » :
 *
 *     203.0.113.7         une seule adresse
 *     10.0.0.0/8          toutes les adresses qui commencent par 10.
 *     2001:db8::/32       une plage IPv6
 *
 * Le nombre après la barre dit combien de bits du début doivent être
 * identiques : /8 compare le premier nombre d'une adresse IPv4, /24 les trois premiers.
 *
 * Sert à reconnaître un proxy de confiance (voir ServerRequestCreator).
 *
 * @internal réservé aux classes de Wazi\Http
 */
final readonly class IpRange
{
    /**
     * @param string $network l'adresse de départ, sous forme d'octets (4 pour IPv4, 16 pour IPv6)
     * @param int    $bits    le nombre de bits à comparer
     */
    private function __construct(private string $network, private int $bits) {}

    /**
     * @return self|null null si le texte n'est ni une adresse ni une plage valide
     */
    public static function fromString(string $range): ?self
    {
        $parts = explode('/', $range, 2);
        $size = $parts[1] ?? null;
        $network = self::pack($parts[0]);

        if ($network === null) {
            return null;
        }

        $maximum = strlen($network) * 8;

        if ($size === null) {
            return new self($network, $maximum);
        }

        if (!ctype_digit($size) || (int) $size > $maximum) {
            return null;
        }

        return new self($network, (int) $size);
    }

    public function contains(string $ip): bool
    {
        $address = self::pack($ip);

        // Une adresse IPv4 n'est jamais dans une plage IPv6, et inversement.
        if ($address === null || strlen($address) !== strlen($this->network)) {
            return false;
        }

        // On compare d'abord les octets entiers, puis les bits restants du dernier octet.
        $fullBytes = intdiv($this->bits, 8);
        $remainingBits = $this->bits % 8;

        if (substr($address, 0, $fullBytes) !== substr($this->network, 0, $fullBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        // Un masque qui ne garde que les premiers bits : 3 bits donnent 11100000.
        $mask = 0xFF << (8 - $remainingBits) & 0xFF;

        return (ord($address[$fullBytes]) & $mask) === (ord($this->network[$fullBytes]) & $mask);
    }

    /**
     * Une adresse sous forme d'octets, ou null si ce n'en est pas une.
     */
    private static function pack(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = inet_pton($ip);

        if ($packed === false) {
            return null;
        }

        // « ::ffff:203.0.113.7 » est l'écriture IPv6 d'une adresse IPv4 : on la
        // ramène à ses 4 octets, pour qu'elle soit reconnue dans une plage IPv4.
        if (strlen($packed) === 16 && str_starts_with($packed, "\0\0\0\0\0\0\0\0\0\0\xFF\xFF")) {
            return substr($packed, 12);
        }

        return $packed;
    }
}
