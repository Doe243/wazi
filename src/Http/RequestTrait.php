<?php

declare(strict_types=1);

namespace Wazi\Http;

use Psr\Http\Message\UriInterface;
use Wazi\Http\Exception\InvalidMessageException;

/**
 * Ce que toute requête HTTP a en plus d'un message (RequestInterface de PSR-7) :
 * une méthode, une URI et une « cible », qui forment sa première ligne.
 *
 *     GET /articles?page=2 HTTP/1.1
 *     └┬┘ └───────┬──────┘
 *   méthode     cible
 *
 * Partagé par Request (une requête qu'on envoie) et ServerRequest (une requête
 * qu'on reçoit), pour la même raison que MessageTrait (ADR-007).
 *
 * @internal utilisé uniquement par les requêtes de Wazi\Http
 */
trait RequestTrait
{
    use MessageTrait;

    /** Tout sauf un espace, un retour à la ligne ou un caractère de contrôle. */
    private const string REQUEST_TARGET = '/^[^\x00-\x20\x7F]+$/D';

    private readonly string $method;

    private readonly UriInterface $uri;

    /** La cible imposée par withRequestTarget(), ou null pour la déduire de l'URI. */
    private readonly ?string $requestTarget;

    public function getMethod(): string
    {
        return $this->method;
    }

    #[\NoDiscard(self::NO_DISCARD)]
    public function withMethod(string $method): self
    {
        return clone($this, ['method' => self::validMethod($method)]);
    }

    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    /**
     * Change l'URI. L'en-tête Host suit le nouvel hôte, sauf si $preserveHost
     * vaut true et qu'un en-tête Host existe déjà.
     */
    #[\NoDiscard(self::NO_DISCARD)]
    public function withUri(UriInterface $uri, bool $preserveHost = false): self
    {
        if ($uri->getHost() === '' || ($preserveHost && $this->hasHeader('Host'))) {
            return clone($this, ['uri' => $uri]);
        }

        [$headers, $headerNames] = self::withHostHeader($this->headers, $this->headerNames, $uri);

        return clone($this, ['uri' => $uri, 'headers' => $headers, 'headerNames' => $headerNames]);
    }

    /**
     * La cible est ce qui suit la méthode dans la première ligne de la requête.
     * Sauf demande contraire, c'est le chemin de l'URI suivi de sa requête.
     */
    public function getRequestTarget(): string
    {
        if ($this->requestTarget !== null) {
            return $this->requestTarget;
        }

        $target = $this->uri->getPath() !== '' ? $this->uri->getPath() : '/';

        if ($this->uri->getQuery() !== '') {
            $target .= '?' . $this->uri->getQuery();
        }

        return $target;
    }

    #[\NoDiscard(self::NO_DISCARD)]
    public function withRequestTarget(string $requestTarget): self
    {
        // Un espace ou un retour à la ligne ici couperait la première ligne
        // de la requête et permettrait d'en injecter une autre.
        if (preg_match(self::REQUEST_TARGET, $requestTarget) !== 1) {
            throw InvalidMessageException::invalidRequestTarget($requestTarget);
        }

        return clone($this, ['requestTarget' => $requestTarget]);
    }

    // ------------------------------------------------------------------
    // Outils internes
    // ------------------------------------------------------------------

    /**
     * La méthode est sensible à la casse (« GET » n'est pas « get ») : on la
     * valide sans la modifier.
     */
    private static function validMethod(string $method): string
    {
        if (preg_match(self::TOKEN, $method) !== 1) {
            throw InvalidMessageException::invalidMethod($method);
        }

        return $method;
    }

    /**
     * Ajoute aux en-têtes un Host tiré de l'URI s'ils n'en ont pas déjà un.
     *
     * @param array<string, list<string>> $headers
     * @param array<string, string>       $headerNames
     *
     * @return array{array<string, list<string>>, array<string, string>}
     */
    private static function withDefaultHostHeader(array $headers, array $headerNames, UriInterface $uri): array
    {
        if (isset($headerNames['host']) || $uri->getHost() === '') {
            return [$headers, $headerNames];
        }

        return self::withHostHeader($headers, $headerNames, $uri);
    }

    /**
     * Remplace l'en-tête Host par l'hôte de l'URI (et son port s'il n'est pas standard).
     * PSR-7 demande que Host soit le premier en-tête de la requête.
     *
     * @param array<string, list<string>> $headers
     * @param array<string, string>       $headerNames
     *
     * @return array{array<string, list<string>>, array<string, string>}
     */
    private static function withHostHeader(array $headers, array $headerNames, UriInterface $uri): array
    {
        $host = $uri->getHost();

        if ($uri->getPort() !== null) {
            $host .= ':' . $uri->getPort();
        }

        if (isset($headerNames['host'])) {
            unset($headers[$headerNames['host']]);
        }

        $headerNames['host'] = 'Host';

        // L'URI peut venir d'une autre bibliothèque : son hôte est validé comme
        // n'importe quelle valeur d'en-tête.
        return [['Host' => self::validHeaderValues('Host', $host)] + $headers, $headerNames];
    }
}
