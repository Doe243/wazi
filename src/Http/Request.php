<?php

declare(strict_types=1);

namespace Wazi\Http;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Wazi\Http\Exception\InvalidMessageException;
use Wazi\Http\Exception\InvalidUriException;

/**
 * Une requête HTTP qu'on envoie à un autre serveur, immuable et conforme à PSR-7.
 *
 *     $request = new Request('GET', 'https://exemple.com/articles?page=2');
 *
 * Pour la requête que VOTRE application reçoit d'un navigateur, c'est
 * ServerRequest qu'il faut regarder : elle ajoute cookies, formulaire, fichiers...
 *
 * Tout le comportement vient de deux traits, qui se lisent comme s'ils étaient
 * écrits ici : RequestTrait (méthode, URI, cible) et MessageTrait (en-têtes, corps).
 *
 * Immuable : une Request ne change jamais. Chaque méthode with...() retourne
 * une NOUVELLE Request et laisse l'originale intacte.
 */
final readonly class Request implements RequestInterface
{
    use RequestTrait;

    /**
     * @param array<array-key, mixed> $headers nom de l'en-tête => valeur, ou liste de valeurs
     *
     * @throws InvalidMessageException si la méthode, un en-tête ou la version est invalide
     * @throws InvalidUriException     si l'URI donnée en texte est invalide
     */
    public function __construct(
        string $method,
        UriInterface|string $uri,
        array $headers = [],
        StreamInterface|string|null $body = null,
        string $protocolVersion = '1.1',
    ) {
        $this->method = self::validMethod($method);
        $this->uri = is_string($uri) ? new Uri($uri) : $uri;
        $this->requestTarget = null;

        [$headers, $headerNames] = self::indexedHeaders($headers);
        [$this->headers, $this->headerNames] = self::withDefaultHostHeader($headers, $headerNames, $this->uri);

        $this->body = self::bodyFrom($body);
        $this->protocolVersion = self::validProtocolVersion($protocolVersion);
    }
}
