<?php

declare(strict_types=1);

namespace Wazi\Http;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriInterface;
use Wazi\Http\Exception\InvalidMessageException;
use Wazi\Http\Exception\InvalidUriException;

/**
 * La requête que votre application reçoit d'un navigateur, immuable et conforme à PSR-7.
 *
 * En plus d'une requête ordinaire (méthode, URI, en-têtes, corps), elle porte
 * ce que PHP range d'habitude dans ses variables globales :
 *
 *     getServerParams()    ← $_SERVER
 *     getCookieParams()    ← $_COOKIE
 *     getQueryParams()     ← $_GET
 *     getParsedBody()      ← $_POST (ou le JSON décodé)
 *     getUploadedFiles()   ← $_FILES
 *
 * et des « attributs » : des valeurs que le framework y dépose en chemin,
 * par exemple les paramètres trouvés par le routeur ({id} dans /articles/{id}).
 *
 * Pourquoi ne pas lire $_GET directement ? Une variable globale peut être
 * modifiée par n'importe quel code, à n'importe quel moment. Cet objet, lui,
 * ne change jamais : ce que reçoit votre contrôleur est ce qui a été validé.
 *
 * Sécurité (ADR-006) : cette classe ne lit AUCUNE variable globale et ne fait
 * confiance à rien d'elle-même. Tout ce qu'elle contient vient du navigateur
 * et doit être traité comme tel : à valider avant usage, à échapper avant affichage.
 */
final readonly class ServerRequest implements ServerRequestInterface
{
    use RequestTrait;

    /** @var array<array-key, mixed> */
    private array $serverParams;

    /** @var array<array-key, mixed> */
    private array $cookieParams;

    /** @var array<array-key, mixed> */
    private array $queryParams;

    /** @var array<array-key, mixed> Une arborescence de UploadedFileInterface. */
    private array $uploadedFiles;

    /** @var array<array-key, mixed>|object|null */
    private array|object|null $parsedBody;

    /** @var array<string, mixed> */
    private array $attributes;

    /**
     * @param array<array-key, mixed> $headers      nom de l'en-tête => valeur, ou liste de valeurs
     * @param array<array-key, mixed> $serverParams en général $_SERVER
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
        array $serverParams = [],
    ) {
        $this->method = self::validMethod($method);
        $this->uri = is_string($uri) ? new Uri($uri) : $uri;
        $this->requestTarget = null;

        [$headers, $headerNames] = self::indexedHeaders($headers);
        [$this->headers, $this->headerNames] = self::withDefaultHostHeader($headers, $headerNames, $this->uri);

        $this->body = self::bodyFrom($body);
        $this->protocolVersion = self::validProtocolVersion($protocolVersion);

        $this->serverParams = $serverParams;
        $this->cookieParams = [];
        $this->queryParams = [];
        $this->uploadedFiles = [];
        $this->parsedBody = null;
        $this->attributes = [];
    }

    // ------------------------------------------------------------------
    // Ce que le navigateur a envoyé
    // ------------------------------------------------------------------

    /**
     * @return array<array-key, mixed>
     */
    public function getServerParams(): array
    {
        return $this->serverParams;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function getCookieParams(): array
    {
        return $this->cookieParams;
    }

    /**
     * @param array<array-key, mixed> $cookies
     */
    #[\NoDiscard(self::NO_DISCARD)]
    public function withCookieParams(array $cookies): self
    {
        return clone($this, ['cookieParams' => $cookies]);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    /**
     * @param array<array-key, mixed> $query
     */
    #[\NoDiscard(self::NO_DISCARD)]
    public function withQueryParams(array $query): self
    {
        return clone($this, ['queryParams' => $query]);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function getUploadedFiles(): array
    {
        return $this->uploadedFiles;
    }

    /**
     * @param array<array-key, mixed> $uploadedFiles des UploadedFileInterface, éventuellement rangés dans des tableaux
     */
    #[\NoDiscard(self::NO_DISCARD)]
    public function withUploadedFiles(array $uploadedFiles): self
    {
        self::assertOnlyUploadedFiles($uploadedFiles);

        return clone($this, ['uploadedFiles' => $uploadedFiles]);
    }

    /**
     * Le corps une fois analysé : les champs d'un formulaire, ou un JSON décodé.
     *
     * @return array<array-key, mixed>|object|null
     */
    public function getParsedBody(): array|object|null
    {
        return $this->parsedBody;
    }

    /**
     * @param array<array-key, mixed>|object|null $data
     */
    #[\NoDiscard(self::NO_DISCARD)]
    public function withParsedBody(mixed $data): self
    {
        if ($data !== null && !is_array($data) && !is_object($data)) {
            throw InvalidMessageException::invalidParsedBody(get_debug_type($data));
        }

        return clone($this, ['parsedBody' => $data]);
    }

    // ------------------------------------------------------------------
    // Ce que le framework ajoute en chemin
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Retourne $default si l'attribut n'existe pas. Un attribut qui vaut
     * null existe bel et bien : c'est pourquoi on n'utilise pas « ?? » ici.
     */
    public function getAttribute(string $name, mixed $default = null): mixed
    {
        return array_key_exists($name, $this->attributes) ? $this->attributes[$name] : $default;
    }

    #[\NoDiscard(self::NO_DISCARD)]
    public function withAttribute(string $name, mixed $value): self
    {
        return clone($this, ['attributes' => [...$this->attributes, $name => $value]]);
    }

    #[\NoDiscard(self::NO_DISCARD)]
    public function withoutAttribute(string $name): self
    {
        return clone($this, ['attributes' => array_diff_key($this->attributes, [$name => true])]);
    }

    // ------------------------------------------------------------------
    // Outils internes
    // ------------------------------------------------------------------

    /**
     * Les fichiers peuvent être rangés dans des tableaux imbriqués
     * (un champ <input name="photos[]"> en envoie plusieurs) : on les parcourt tous.
     *
     * @param array<array-key, mixed> $uploadedFiles
     */
    private static function assertOnlyUploadedFiles(array $uploadedFiles): void
    {
        foreach ($uploadedFiles as $file) {
            if (is_array($file)) {
                self::assertOnlyUploadedFiles($file);

                continue;
            }

            if (!$file instanceof UploadedFileInterface) {
                throw InvalidMessageException::notAnUploadedFile(get_debug_type($file));
            }
        }
    }
}
