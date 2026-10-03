<?php

declare(strict_types=1);

namespace Wazi\Http;

use Psr\Http\Message\UriInterface;
use Uri\Rfc3986\Uri as NativeUri;
use Wazi\Http\Exception\InvalidUriException;

/**
 * Une URI (l'adresse d'une ressource), immuable et conforme à PSR-7.
 *
 *     https://alice:secret@exemple.com:8080/articles?page=2#commentaires
 *     └─┬─┘   └────┬─────┘ └────┬────┘ └┬─┘└───┬───┘ └──┬──┘ └────┬─────┘
 *     schéma   userinfo       hôte    port  chemin  requête   fragment
 *
 * Wazi n'analyse pas les URI lui-même : il confie ce travail à l'analyseur
 * natif de PHP 8.5, Uri\Rfc3986\Uri, conforme à la RFC 3986 (ADR-005).
 * Cette classe ajoute ce que PSR-7 exige en plus :
 *   - le schéma et l'hôte sont mis en minuscules ;
 *   - le port standard du schéma (80 pour http, 443 pour https) est masqué ;
 *   - les caractères spéciaux passés aux méthodes with...() sont encodés.
 *
 * Immuable : une Uri ne change jamais. Chaque méthode with...() retourne
 * une NOUVELLE Uri et laisse l'originale intacte.
 */
final readonly class Uri implements UriInterface
{
    /** Ports utilisés par défaut par chaque schéma : inutile de les afficher. */
    private const array STANDARD_PORTS = [
        'http' => 80,
        'https' => 443,
        'ws' => 80,
        'wss' => 443,
        'ftp' => 21,
    ];

    /** Caractères autorisés tels quels partout (RFC 3986, section 2). */
    private const string UNRESERVED = 'a-zA-Z0-9_\-\.~';
    private const string SUB_DELIMS = '!\$&\'\(\)\*\+,;=';

    private const string NO_DISCARD = 'Une Uri est immuable : cette méthode retourne une nouvelle Uri,'
        . ' récupérez-la, par exemple $uri = $uri->withPath(\'/contact\').';

    private string $scheme;
    private string $userInfo;
    private string $host;
    private ?int $port;
    private string $path;
    private string $query;
    private string $fragment;

    /**
     * @throws InvalidUriException si la chaîne n'est pas une URI valide
     */
    public function __construct(string $uri = '')
    {
        $parsed = NativeUri::parse($uri) ?? throw InvalidUriException::unparsable($uri);

        // On lit les versions « brutes » (getRaw...) : PSR-7 veut les parties
        // telles qu'elles ont été écrites, encodage compris, sans décodage.
        $this->scheme = strtolower($parsed->getRawScheme() ?? '');
        $this->userInfo = $parsed->getRawUserInfo() ?? '';
        $this->host = strtolower($parsed->getRawHost() ?? '');
        $this->port = $parsed->getPort();
        $this->path = $parsed->getRawPath();
        $this->query = $parsed->getRawQuery() ?? '';
        $this->fragment = $parsed->getRawFragment() ?? '';
    }

    // ------------------------------------------------------------------
    // Lecture
    // ------------------------------------------------------------------

    public function getScheme(): string
    {
        return $this->scheme;
    }

    /**
     * L'autorité regroupe userinfo, hôte et port : alice@exemple.com:8080
     */
    public function getAuthority(): string
    {
        if ($this->host === '') {
            return '';
        }

        $authority = $this->host;

        if ($this->userInfo !== '') {
            $authority = $this->userInfo . '@' . $authority;
        }

        $port = $this->getPort();

        if ($port !== null) {
            $authority .= ':' . $port;
        }

        return $authority;
    }

    public function getUserInfo(): string
    {
        return $this->userInfo;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * Retourne null si aucun port n'est précisé, ou si c'est le port
     * standard du schéma (inutile d'écrire :443 pour du https).
     */
    public function getPort(): ?int
    {
        if ($this->port === (self::STANDARD_PORTS[$this->scheme] ?? null)) {
            return null;
        }

        return $this->port;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function getFragment(): string
    {
        return $this->fragment;
    }

    // ------------------------------------------------------------------
    // Modification (toujours sur une copie)
    // ------------------------------------------------------------------

    #[\NoDiscard(self::NO_DISCARD)]
    public function withScheme(string $scheme): self
    {
        return $this->validated(clone($this, ['scheme' => strtolower($scheme)]), 'schéma', $scheme);
    }

    #[\NoDiscard(self::NO_DISCARD)]
    public function withUserInfo(string $user, ?string $password = null): self
    {
        $userInfo = self::encode($user, '');

        if ($user !== '' && $password !== null && $password !== '') {
            $userInfo .= ':' . self::encode($password, ':');
        }

        return $this->validated(clone($this, ['userInfo' => $userInfo]), 'userinfo', $user);
    }

    #[\NoDiscard(self::NO_DISCARD)]
    public function withHost(string $host): self
    {
        return $this->validated(clone($this, ['host' => strtolower($host)]), 'hôte', $host);
    }

    #[\NoDiscard(self::NO_DISCARD)]
    public function withPort(?int $port): self
    {
        if ($port !== null && ($port < 0 || $port > 65535)) {
            throw InvalidUriException::portOutOfRange($port);
        }

        return $this->validated(clone($this, ['port' => $port]), 'port', (string) $port);
    }

    #[\NoDiscard(self::NO_DISCARD)]
    public function withPath(string $path): self
    {
        return $this->validated(clone($this, ['path' => self::encode($path, ':@\/')]), 'chemin', $path);
    }

    #[\NoDiscard(self::NO_DISCARD)]
    public function withQuery(string $query): self
    {
        return $this->validated(clone($this, ['query' => self::encode($query, ':@\/\?')]), 'requête', $query);
    }

    #[\NoDiscard(self::NO_DISCARD)]
    public function withFragment(string $fragment): self
    {
        return $this->validated(clone($this, ['fragment' => self::encode($fragment, ':@\/\?')]), 'fragment', $fragment);
    }

    // ------------------------------------------------------------------
    // Conversion en texte
    // ------------------------------------------------------------------

    public function __toString(): string
    {
        $uri = '';

        if ($this->scheme !== '') {
            $uri .= $this->scheme . ':';
        }

        $authority = $this->getAuthority();

        if ($authority !== '') {
            $uri .= '//' . $authority;
        }

        $uri .= self::normalizedPath($this->path, $authority !== '');

        if ($this->query !== '') {
            $uri .= '?' . $this->query;
        }

        if ($this->fragment !== '') {
            $uri .= '#' . $this->fragment;
        }

        return $uri;
    }

    // ------------------------------------------------------------------
    // Outils internes
    // ------------------------------------------------------------------

    /**
     * Fait vérifier la nouvelle Uri par l'analyseur natif.
     *
     * Sécurité (ADR-006) : un seul analyseur décide de ce qui est valide,
     * pour qu'aucune URI ne soit acceptée ici puis comprise autrement ailleurs.
     */
    private function validated(self $uri, string $component, string $value): self
    {
        if (NativeUri::parse((string) $uri) === null) {
            throw InvalidUriException::invalidComponent($component, $value);
        }

        return $uri;
    }

    /**
     * Encode les caractères qui n'ont pas le droit d'apparaître tels quels,
     * sans ré-encoder ce qui l'est déjà : « a b » devient « a%20b »,
     * mais « a%20b » reste « a%20b ».
     */
    private static function encode(string $value, string $allowed): string
    {
        $pattern = '/(?:[^' . self::UNRESERVED . self::SUB_DELIMS . $allowed . '%]++|%(?![A-Fa-f0-9]{2}))/';

        return preg_replace_callback(
            $pattern,
            static fn(array $match): string => rawurlencode($match[0]),
            $value,
        ) ?? throw InvalidUriException::unparsable($value);
    }

    /**
     * Règles de PSR-7 pour écrire le chemin sans ambiguïté :
     * - avec une autorité, le chemin doit commencer par « / » ;
     * - sans autorité, il ne doit pas commencer par « // », sinon la suite
     *   serait lue comme un hôte.
     */
    private static function normalizedPath(string $path, bool $hasAuthority): string
    {
        if ($hasAuthority && $path !== '' && !str_starts_with($path, '/')) {
            return '/' . $path;
        }

        if (!$hasAuthority && str_starts_with($path, '//')) {
            return '/' . ltrim($path, '/');
        }

        return $path;
    }
}
