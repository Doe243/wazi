<?php

declare(strict_types=1);

namespace Wazi\Http;

use Psr\Http\Message\StreamInterface;
use Wazi\Http\Exception\InvalidStreamException;
use Wazi\Http\Exception\StreamException;

/**
 * Un flux : le contenu (le « corps ») d'une requête ou d'une réponse, conforme à PSR-7.
 *
 * Pourquoi un flux plutôt qu'une simple chaîne ? Parce qu'un corps peut être
 * très gros (un fichier envoyé, une vidéo). Un flux se lit morceau par morceau,
 * avec une position qui avance, sans tout charger en mémoire :
 *
 *     B o n j o u r
 *     ▲       ▲
 *     │       └─ position après read(4)
 *     └───────── position au départ, ou après rewind()
 *
 * Cette classe enveloppe une « ressource » PHP, c'est-à-dire ce que retourne fopen().
 *
 * Contrairement aux autres objets PSR-7, un flux n'est PAS immuable : lire ou
 * écrire déplace sa position, et close() le rend inutilisable.
 *
 * Sécurité (ADR-006) :
 *   - le constructeur n'accepte qu'une ressource déjà ouverte, jamais une
 *     adresse sous forme de texte. Si un nom de fichier venu d'une requête
 *     arrivait ici, il ne pourrait donc rien ouvrir : ni fichier du serveur,
 *     ni adresse distante, ni archive phar:// ;
 *   - chaque opération vérifie d'abord que le flux la permet, et échoue avec
 *     une explication plutôt qu'avec un avertissement PHP ;
 *   - (string) $flux et getContents() chargent tout le contenu en mémoire.
 *     La taille des corps de requête est limitée en amont, à la création de
 *     la requête, pas ici.
 */
final class Stream implements StreamInterface
{
    /** @var resource|null La ressource PHP, ou null une fois le flux fermé ou détaché. */
    private $resource;

    /** Le mode d'ouverture de la ressource (« r », « w+ »...), gardé pour les messages d'erreur. */
    private string $mode;

    private bool $readable;
    private bool $writable;
    private bool $seekable;

    /**
     * @param mixed $resource une ressource de flux ouverte, par exemple le résultat de fopen()
     *
     * @throws InvalidStreamException si la valeur n'est pas une ressource de flux ouverte
     */
    public function __construct(mixed $resource)
    {
        if (!is_resource($resource) || get_resource_type($resource) !== 'stream') {
            throw InvalidStreamException::notAStream(get_debug_type($resource));
        }

        $metadata = stream_get_meta_data($resource);

        $this->resource = $resource;
        $this->mode = $metadata['mode'];
        $this->seekable = $metadata['seekable'];

        // Le mode dit ce que la ressource autorise : « r » lit, « w », « a »,
        // « x » et « c » écrivent, et un « + » ajoute l'autre droit.
        $this->readable = strpbrk($this->mode, 'r+') !== false;
        $this->writable = strpbrk($this->mode, 'waxc+') !== false;
    }

    /**
     * Crée un flux qui contient ce texte, prêt à être lu depuis le début.
     *
     * Le texte est rangé dans php://temp : en mémoire tant qu'il est petit,
     * puis dans un fichier temporaire au-delà de 2 Mo, pour ménager la mémoire.
     * Il n'est jamais interprété : « php://input » reste le texte « php://input ».
     */
    public static function fromString(string $content = ''): self
    {
        $resource = fopen('php://temp', 'r+b');

        if ($resource === false) {
            throw StreamException::temporaryStreamUnavailable();
        }

        $stream = new self($resource);
        $stream->write($content);
        $stream->rewind();

        return $stream;
    }

    // ------------------------------------------------------------------
    // Ce que le flux permet
    // ------------------------------------------------------------------

    public function isReadable(): bool
    {
        return $this->readable;
    }

    public function isWritable(): bool
    {
        return $this->writable;
    }

    public function isSeekable(): bool
    {
        return $this->seekable;
    }

    // ------------------------------------------------------------------
    // Lecture
    // ------------------------------------------------------------------

    /**
     * Lit au plus $length octets à partir de la position actuelle.
     */
    public function read(int $length): string
    {
        $resource = $this->resourceFor('lire le flux');

        if (!$this->readable) {
            throw StreamException::notReadable($this->mode);
        }

        if ($length < 0) {
            throw StreamException::negativeLength($length);
        }

        if ($length === 0) {
            return '';
        }

        $data = fread($resource, $length);

        return $data !== false ? $data : throw StreamException::operationFailed('lire le flux');
    }

    /**
     * Lit tout ce qui reste, de la position actuelle jusqu'à la fin.
     */
    public function getContents(): string
    {
        $resource = $this->resourceFor('lire le contenu du flux');

        if (!$this->readable) {
            throw StreamException::notReadable($this->mode);
        }

        $contents = stream_get_contents($resource);

        return $contents !== false ? $contents : throw StreamException::operationFailed('lire le contenu du flux');
    }

    /**
     * Retourne tout le contenu, depuis le début, quelle que soit la position.
     *
     * PSR-7 interdit à cette méthode de lever une exception : si le flux
     * n'est pas lisible ou n'est plus utilisable, elle retourne une chaîne vide.
     * Pour être averti d'un problème, utilisez rewind() puis getContents().
     */
    public function __toString(): string
    {
        try {
            if ($this->seekable) {
                $this->rewind();
            }

            return $this->getContents();
        } catch (StreamException) {
            return '';
        }
    }

    // ------------------------------------------------------------------
    // Écriture
    // ------------------------------------------------------------------

    /**
     * Écrit à la position actuelle et retourne le nombre d'octets écrits.
     */
    public function write(string $string): int
    {
        $resource = $this->resourceFor('écrire dans le flux');

        if (!$this->writable) {
            throw StreamException::notWritable($this->mode);
        }

        $written = fwrite($resource, $string);

        return $written !== false ? $written : throw StreamException::operationFailed('écrire dans le flux');
    }

    // ------------------------------------------------------------------
    // Position
    // ------------------------------------------------------------------

    public function tell(): int
    {
        $position = ftell($this->resourceFor('connaître la position dans le flux'));

        return $position !== false
            ? $position
            : throw StreamException::operationFailed('connaître la position dans le flux');
    }

    /**
     * Vrai quand une lecture a atteint la fin du contenu.
     */
    public function eof(): bool
    {
        return !is_resource($this->resource) || feof($this->resource);
    }

    /**
     * Déplace la position. $whence indique le point de départ, comme pour fseek() :
     * SEEK_SET (le début), SEEK_CUR (la position actuelle) ou SEEK_END (la fin).
     */
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $resource = $this->resourceFor('se déplacer dans le flux');

        if (!$this->seekable) {
            throw StreamException::notSeekable();
        }

        if (fseek($resource, $offset, $whence) === -1) {
            throw StreamException::operationFailed('se déplacer dans le flux');
        }
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    // ------------------------------------------------------------------
    // Informations
    // ------------------------------------------------------------------

    /**
     * La taille en octets, ou null si elle est inconnue.
     */
    public function getSize(): ?int
    {
        if (!is_resource($this->resource)) {
            return null;
        }

        $statistics = fstat($this->resource);

        return $statistics !== false ? $statistics['size'] : null;
    }

    /**
     * Les informations de PHP sur la ressource (voir stream_get_meta_data()),
     * en entier, ou pour une seule clé.
     */
    public function getMetadata(?string $key = null): mixed
    {
        $metadata = is_resource($this->resource) ? stream_get_meta_data($this->resource) : [];

        return $key === null ? $metadata : $metadata[$key] ?? null;
    }

    // ------------------------------------------------------------------
    // Fermeture
    // ------------------------------------------------------------------

    /**
     * Ferme la ressource. Le flux n'est plus utilisable ensuite.
     */
    public function close(): void
    {
        $resource = $this->detach();

        if (is_resource($resource)) {
            fclose($resource);
        }
    }

    /**
     * Sépare le flux de sa ressource et la retourne, sans la fermer :
     * c'est à l'appelant de s'en occuper. Le flux n'est plus utilisable ensuite.
     *
     * @return resource|null
     */
    public function detach()
    {
        $resource = $this->resource;

        $this->resource = null;
        $this->readable = false;
        $this->writable = false;
        $this->seekable = false;

        return $resource;
    }

    // ------------------------------------------------------------------
    // Outils internes
    // ------------------------------------------------------------------

    /**
     * Retourne la ressource, ou explique pourquoi l'opération est impossible.
     *
     * is_resource() retourne aussi false pour une ressource fermée ailleurs
     * par fclose() : sans cette vérification, PHP lèverait une TypeError
     * bien moins claire.
     *
     * @return resource
     */
    private function resourceFor(string $operation)
    {
        return is_resource($this->resource) ? $this->resource : throw StreamException::detached($operation);
    }
}
