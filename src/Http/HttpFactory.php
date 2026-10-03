<?php

declare(strict_types=1);

namespace Wazi\Http;

use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\UriInterface;
use Wazi\Http\Exception\InvalidStreamException;
use Wazi\Http\Exception\InvalidUriException;
use Wazi\Http\Exception\StreamException;

/**
 * La fabrique des objets HTTP de Wazi, conforme à PSR-17.
 *
 * À quoi sert une fabrique, puisqu'on peut écrire « new Response() » ? À ne pas
 * dépendre de Wazi. Une bibliothèque qui reçoit une ResponseFactoryInterface
 * peut créer des réponses sans savoir quelle classe se cache derrière : elle
 * fonctionnera aussi bien avec Wazi qu'avec un autre framework.
 *
 * Dans votre propre application, « new Response(...) » reste le plus simple.
 *
 * Une seule classe implémente les six interfaces de PSR-17 : chaque méthode
 * tient en une ligne, les séparer n'apporterait rien.
 */
final class HttpFactory implements
    RequestFactoryInterface,
    ResponseFactoryInterface,
    ServerRequestFactoryInterface,
    StreamFactoryInterface,
    UploadedFileFactoryInterface,
    UriFactoryInterface
{
    /** Les modes de fopen() : une lettre, puis « + » et « b » ou « t » dans un ordre ou l'autre. */
    private const string FILE_MODE = '/^[rwaxc](?:[bt]?\+?|\+[bt]?)$/D';

    public function createUri(string $uri = ''): Uri
    {
        return new Uri($uri);
    }

    /**
     * @param UriInterface|string $uri
     */
    public function createRequest(string $method, $uri): Request
    {
        return new Request($method, self::uriFrom($uri));
    }

    /**
     * Crée une requête « reçue », SANS lire les variables globales de PHP.
     * Pour la requête réellement reçue par votre application, voir ServerRequestCreator.
     *
     * @param UriInterface|string     $uri
     * @param array<array-key, mixed> $serverParams
     */
    public function createServerRequest(string $method, $uri, array $serverParams = []): ServerRequest
    {
        return new ServerRequest($method, self::uriFrom($uri), [], null, '1.1', $serverParams);
    }

    public function createResponse(int $code = 200, string $reasonPhrase = ''): Response
    {
        return new Response($code, [], null, '1.1', $reasonPhrase);
    }

    public function createStream(string $content = ''): Stream
    {
        return Stream::fromString($content);
    }

    /**
     * @param resource $resource une ressource de flux ouverte, par exemple le résultat de fopen()
     */
    public function createStreamFromResource($resource): Stream
    {
        return new Stream($resource);
    }

    /**
     * Ouvre un fichier du disque.
     *
     * Sécurité (ADR-006) : seul un chemin de fichier ordinaire est accepté.
     * Une adresse à protocole (« php://... », « phar://... », « http://... »)
     * est refusée : si le chemin venait d'une requête, elle permettrait de lire
     * un fichier quelconque, d'exécuter du code ou d'interroger un autre serveur.
     *
     * @throws InvalidStreamException si le mode n'est pas un mode de fopen()
     * @throws StreamException        si le chemin est refusé ou si le fichier ne peut pas être ouvert
     */
    public function createStreamFromFile(string $filename, string $mode = 'r'): Stream
    {
        if (preg_match(self::FILE_MODE, $mode) !== 1) {
            throw InvalidStreamException::invalidMode($mode);
        }

        if (!LocalPath::isPlain($filename)) {
            throw StreamException::notALocalFile();
        }

        // On vérifie avant d'ouvrir : fopen() ne lève pas d'exception en cas
        // d'échec, il émet un simple avertissement.
        if (!self::canBeOpened($filename, $mode)) {
            throw StreamException::cannotOpenFile($mode);
        }

        $resource = fopen($filename, $mode);

        return $resource !== false ? new Stream($resource) : throw StreamException::cannotOpenFile($mode);
    }

    public function createUploadedFile(
        StreamInterface $stream,
        ?int $size = null,
        int $error = \UPLOAD_ERR_OK,
        ?string $clientFilename = null,
        ?string $clientMediaType = null,
    ): UploadedFile {
        return new UploadedFile($stream, $size ?? $stream->getSize(), $error, $clientFilename, $clientMediaType);
    }

    // ------------------------------------------------------------------
    // Outils internes
    // ------------------------------------------------------------------

    private static function uriFrom(mixed $uri): UriInterface
    {
        return match (true) {
            $uri instanceof UriInterface => $uri,
            is_string($uri) => new Uri($uri),
            default => throw InvalidUriException::notAnUri(get_debug_type($uri)),
        };
    }

    private static function canBeOpened(string $filename, string $mode): bool
    {
        if (is_dir($filename)) {
            return false;
        }

        $exists = is_file($filename);
        $writes = $mode[0] !== 'r' || str_contains($mode, '+');

        return match ($mode[0]) {
            // « r » lit un fichier qui existe.
            'r' => $exists && is_readable($filename) && (!$writes || is_writable($filename)),
            // « x » crée un fichier qui n'existe pas encore.
            'x' => !$exists && is_writable(dirname($filename)),
            // « w », « a » et « c » créent le fichier au besoin.
            default => $exists ? is_writable($filename) : is_writable(dirname($filename)),
        };
    }
}
