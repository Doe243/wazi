<?php

declare(strict_types=1);

namespace Wazi\Http;

use Psr\Http\Message\StreamInterface;
use Wazi\Http\Exception\InvalidMessageException;

/**
 * Ce que toute requête et toute réponse HTTP ont en commun (MessageInterface de PSR-7) :
 * une version du protocole, des en-têtes et un corps.
 *
 *     HTTP/1.1 200 OK                    ← première ligne (propre à chaque type de message)
 *     Content-Type: text/html            ┐
 *     Cache-Control: no-cache            ┘ en-têtes
 *
 *     <h1>Bonjour</h1>                   ← corps
 *
 * Pourquoi un trait ? Request, ServerRequest et Response partagent exactement
 * ce code, et ce sont des classes « final » : pas d'héritage entre elles (ADR-007).
 * Un trait se lit comme si son contenu était écrit dans la classe qui l'utilise.
 *
 * Sécurité (ADR-006) : chaque nom et chaque valeur d'en-tête sont validés.
 * Un retour à la ligne dans une valeur permettrait d'injecter de faux en-têtes
 * (un Set-Cookie par exemple), voire un faux corps de réponse.
 *
 * @internal utilisé uniquement par les messages de Wazi\Http
 */
trait MessageTrait
{
    private const string NO_DISCARD = 'Un message HTTP est immuable : cette méthode retourne un nouveau message,'
        . ' récupérez-le, par exemple $response = $response->withHeader(\'Content-Type\', \'text/html\').';

    /** Un « mot » au sens de HTTP (RFC 9110) : nom d'en-tête ou méthode. */
    private const string TOKEN = '/^[a-zA-Z0-9!#$%&\'*+\-.^_`|~]+$/D';

    /**
     * Texte tenant sur une ligne : caractères visibles, espace et tabulation.
     * Aucun retour à la ligne, aucun caractère de contrôle. Le « D » final
     * empêche « $ » d'accepter un retour à la ligne en fin de chaîne.
     */
    private const string SINGLE_LINE = '/^[\x20\x09\x21-\x7E\x80-\xFF]*$/D';

    private const string PROTOCOL_VERSION = '/^\d(?:\.\d)?$/D';

    private readonly string $protocolVersion;

    /** @var array<string, list<string>> Les en-têtes, sous leur nom tel qu'il a été écrit. */
    private readonly array $headers;

    /**
     * Les noms d'en-têtes ne tiennent pas compte de la casse : « content-type »
     * et « Content-Type » désignent le même en-tête. Cet index permet de
     * retrouver le nom d'origine à partir du nom en minuscules.
     *
     * @var array<string, string>
     */
    private readonly array $headerNames;

    private readonly StreamInterface $body;

    // ------------------------------------------------------------------
    // Version du protocole
    // ------------------------------------------------------------------

    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    #[\NoDiscard(self::NO_DISCARD)]
    public function withProtocolVersion(string $version): self
    {
        return clone($this, ['protocolVersion' => self::validProtocolVersion($version)]);
    }

    // ------------------------------------------------------------------
    // En-têtes
    // ------------------------------------------------------------------

    /**
     * @return array<string, list<string>>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headerNames[strtolower($name)]);
    }

    /**
     * Les valeurs d'un en-tête, ou un tableau vide s'il est absent.
     *
     * @return list<string>
     */
    public function getHeader(string $name): array
    {
        $originalName = $this->headerNames[strtolower($name)] ?? null;

        return $originalName === null ? [] : $this->headers[$originalName];
    }

    /**
     * Les valeurs d'un en-tête réunies par des virgules, ou '' s'il est absent.
     */
    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    /**
     * Définit un en-tête, en remplaçant ses valeurs s'il existe déjà.
     *
     * @param string|int|array<string|int> $value
     */
    #[\NoDiscard(self::NO_DISCARD)]
    public function withHeader(string $name, mixed $value): self
    {
        $name = self::validHeaderName($name);
        $values = self::validHeaderValues($name, $value);
        $key = strtolower($name);

        $headers = $this->headers;
        $headerNames = $this->headerNames;

        // L'en-tête existe peut-être sous une autre casse : on retire l'ancien nom.
        if (isset($headerNames[$key])) {
            unset($headers[$headerNames[$key]]);
        }

        $headers[$name] = $values;
        $headerNames[$key] = $name;

        return clone($this, ['headers' => $headers, 'headerNames' => $headerNames]);
    }

    /**
     * Ajoute des valeurs à un en-tête, en gardant celles qu'il a déjà.
     *
     * @param string|int|array<string|int> $value
     */
    #[\NoDiscard(self::NO_DISCARD)]
    public function withAddedHeader(string $name, mixed $value): self
    {
        $originalName = $this->headerNames[strtolower($name)] ?? null;

        if ($originalName === null) {
            return $this->withHeader($name, $value);
        }

        $headers = $this->headers;
        $headers[$originalName] = [...$headers[$originalName], ...self::validHeaderValues($name, $value)];

        return clone($this, ['headers' => $headers]);
    }

    #[\NoDiscard(self::NO_DISCARD)]
    public function withoutHeader(string $name): self
    {
        $key = strtolower($name);
        $originalName = $this->headerNames[$key] ?? null;

        $headers = $this->headers;
        $headerNames = $this->headerNames;

        if ($originalName !== null) {
            unset($headers[$originalName], $headerNames[$key]);
        }

        return clone($this, ['headers' => $headers, 'headerNames' => $headerNames]);
    }

    // ------------------------------------------------------------------
    // Corps
    // ------------------------------------------------------------------

    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    #[\NoDiscard(self::NO_DISCARD)]
    public function withBody(StreamInterface $body): self
    {
        return clone($this, ['body' => $body]);
    }

    // ------------------------------------------------------------------
    // Outils internes
    // ------------------------------------------------------------------

    private static function validProtocolVersion(string $version): string
    {
        // Cette valeur finira dans la première ligne du message (« HTTP/1.1 ») :
        // elle ne doit contenir qu'un numéro de version.
        if (preg_match(self::PROTOCOL_VERSION, $version) !== 1) {
            throw InvalidMessageException::invalidProtocolVersion($version);
        }

        return $version;
    }

    /**
     * Valide un tableau d'en-têtes et construit l'index des noms.
     * Deux noms qui ne diffèrent que par la casse sont réunis en un seul en-tête.
     *
     * @param array<array-key, mixed> $headers
     *
     * @return array{array<string, list<string>>, array<string, string>} les en-têtes, puis l'index des noms
     */
    private static function indexedHeaders(array $headers): array
    {
        $indexed = [];
        $headerNames = [];

        foreach ($headers as $name => $value) {
            // PHP transforme une clé de tableau comme '123' en nombre : on la remet en texte.
            $name = self::validHeaderName((string) $name);
            $values = self::validHeaderValues($name, $value);
            $key = strtolower($name);

            if (isset($headerNames[$key])) {
                $indexed[$headerNames[$key]] = [...$indexed[$headerNames[$key]], ...$values];

                continue;
            }

            $indexed[$name] = $values;
            $headerNames[$key] = $name;
        }

        return [$indexed, $headerNames];
    }

    private static function validHeaderName(string $name): string
    {
        if (preg_match(self::TOKEN, $name) !== 1) {
            throw InvalidMessageException::invalidHeaderName($name);
        }

        return $name;
    }

    /**
     * @return list<string>
     */
    private static function validHeaderValues(string $name, mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];

        if ($values === []) {
            throw InvalidMessageException::emptyHeaderValues($name);
        }

        $valid = [];

        foreach ($values as $item) {
            // Un nombre entier est accepté par commodité : withHeader('Content-Length', 42).
            if (is_int($item)) {
                $item = (string) $item;
            }

            if (!is_string($item)) {
                throw InvalidMessageException::headerValueNotText($name, get_debug_type($item));
            }

            // Les espaces autour d'une valeur n'ont pas de sens en HTTP.
            $item = trim($item, " \t");

            if (preg_match(self::SINGLE_LINE, $item) !== 1) {
                throw InvalidMessageException::invalidHeaderValue($name);
            }

            $valid[] = $item;
        }

        return $valid;
    }

    /**
     * Un texte est rangé dans un flux ; l'absence de corps donne un flux vide.
     */
    private static function bodyFrom(StreamInterface|string|null $body): StreamInterface
    {
        return $body instanceof StreamInterface ? $body : Stream::fromString($body ?? '');
    }
}
