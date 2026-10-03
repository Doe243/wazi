<?php

declare(strict_types=1);

namespace Wazi\Http;

use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Wazi\Http\Exception\InvalidMessageException;
use Wazi\Http\Exception\InvalidUploadedFileException;
use Wazi\Http\Exception\InvalidUriException;
use Wazi\Http\Exception\RequestRejectedException;

/**
 * Construit la ServerRequest que votre application reçoit, à partir de ce que
 * PHP range dans ses variables globales.
 *
 *     $request = new ServerRequestCreator()->fromGlobals();
 *
 *     $_SERVER  ──► méthode, URI, en-têtes, version du protocole
 *     $_GET     ──► getQueryParams()
 *     $_POST    ──► getParsedBody()   (seulement pour un formulaire envoyé en POST)
 *     $_COOKIE  ──► getCookieParams()
 *     $_FILES   ──► getUploadedFiles()
 *     php://input ► getBody()
 *
 * C'est le SEUL endroit de Wazi qui lit ces variables globales.
 *
 * Sécurité (ADR-006 et ADR-008) : tout ce qui arrive ici est écrit par le client.
 *   - Un corps annoncé plus gros que la limite est refusé avant d'être lu.
 *   - L'en-tête Host doit être un nom d'hôte valide ; si vous déclarez des
 *     hôtes de confiance, tout autre hôte est refusé.
 *   - Les en-têtes X-Forwarded-* (posés par un proxy... ou inventés par un
 *     attaquant) ne sont utilisés que si la requête arrive d'un proxy que vous
 *     avez déclaré de confiance (ADR-022). Sinon, ils sont ignorés.
 *   - Aucune « méthode de remplacement » (champ _method, en-tête
 *     X-HTTP-Method-Override) : la méthode est celle de la requête, point.
 *   - L'URI est assemblée puis confiée à l'unique analyseur d'URI de Wazi.
 */
final readonly class ServerRequestCreator
{
    /** 8 Mo : la limite par défaut de PHP lui-même (post_max_size). */
    public const int DEFAULT_MAX_BODY_SIZE = 8 * 1024 * 1024;

    private const string PROTOCOL = '#^HTTP/(\d(?:\.\d)?)$#D';

    /** Les en-têtes que PHP range dans $_SERVER sans le préfixe « HTTP_ ». */
    private const array HEADERS_WITHOUT_PREFIX = ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'];

    /** Les types de contenu d'un formulaire HTML, pour lesquels PHP remplit $_POST. */
    private const array FORM_CONTENT_TYPES = ['application/x-www-form-urlencoded', 'multipart/form-data'];

    /** Le nom de l'attribut de la requête qui porte l'adresse IP du visiteur. */
    public const string CLIENT_IP = 'client_ip';

    /** @var list<string> */
    private array $trustedHosts;

    /** @var list<IpRange> */
    private array $trustedProxies;

    /**
     * @param int          $maxBodySize    taille maximale du corps d'une requête, en octets
     * @param list<string> $trustedHosts   les noms d'hôtes de votre site ('exemple.com') ; vide : tout hôte valide est accepté
     * @param list<string> $trustedProxies les adresses de VOS proxies ('10.0.0.5', '10.0.0.0/8') ; vide : aucun en-tête X-Forwarded-* n'est cru
     *
     * @throws InvalidMessageException si la limite est négative, ou si une adresse de proxy est invalide
     */
    public function __construct(
        private int $maxBodySize = self::DEFAULT_MAX_BODY_SIZE,
        array $trustedHosts = [],
        array $trustedProxies = [],
    ) {
        if ($maxBodySize < 0) {
            throw InvalidMessageException::negativeBodyLimit($maxBodySize);
        }

        // Un nom d'hôte ne tient pas compte de la casse.
        $this->trustedHosts = array_map(strtolower(...), $trustedHosts);

        $ranges = [];

        foreach ($trustedProxies as $proxy) {
            $ranges[] = IpRange::fromString($proxy) ?? throw InvalidMessageException::invalidProxy($proxy);
        }

        $this->trustedProxies = $ranges;
    }

    /**
     * Construit la requête reçue, à partir des variables globales de PHP.
     *
     * @throws RequestRejectedException si la requête est refusée (corps trop gros, hôte invalide, requête mal formée...)
     */
    public function fromGlobals(): ServerRequest
    {
        // php://input donne le corps brut de la requête, sans le charger en mémoire.
        $input = fopen('php://input', 'rb');

        return $this->fromArrays(
            $_SERVER,
            $_GET,
            $_POST,
            $_COOKIE,
            $_FILES,
            $input !== false ? new Stream($input) : null,
        );
    }

    /**
     * Construit la requête à partir de tableaux de même forme que les variables
     * globales. C'est ce que fait fromGlobals() ; cette méthode permet de le
     * faire dans un test, sans toucher aux variables globales.
     *
     * @param array<array-key, mixed> $server  de la forme de $_SERVER
     * @param array<array-key, mixed> $query   de la forme de $_GET
     * @param array<array-key, mixed> $post    de la forme de $_POST
     * @param array<array-key, mixed> $cookies de la forme de $_COOKIE
     * @param array<array-key, mixed> $files   de la forme de $_FILES
     *
     * @throws RequestRejectedException si la requête est refusée (corps trop gros, hôte invalide, requête mal formée...)
     */
    public function fromArrays(
        array $server,
        array $query = [],
        array $post = [],
        array $cookies = [],
        array $files = [],
        StreamInterface|string|null $body = null,
    ): ServerRequest {
        $this->assertBodySizeIsAllowed($server);

        try {
            $request = new ServerRequest(
                is_string($server['REQUEST_METHOD'] ?? null) ? $server['REQUEST_METHOD'] : 'GET',
                $this->uriFrom($server),
                self::headersFrom($server),
                $body,
                self::protocolVersionFrom($server),
                $server,
            );
        } catch (InvalidUriException|InvalidMessageException $exception) {
            // Une méthode, une adresse ou un en-tête invalide vient ici du client,
            // pas de votre code : c'est une requête à refuser (400), pas un bogue.
            throw RequestRejectedException::malformedRequest($exception);
        }

        $request = $request
            ->withQueryParams($query)
            ->withCookieParams($cookies)
            ->withUploadedFiles(self::uploadedFilesFrom($files));

        // $_POST n'a de sens que pour un formulaire : pour du JSON, par exemple,
        // PHP le laisse vide et le corps reste à analyser.
        // L'adresse du visiteur : vos contrôleurs la lisent par $request->getAttribute('client_ip').
        $request = $request->withAttribute(self::CLIENT_IP, $this->clientIp($server));

        return self::isFormSubmission($request) ? $request->withParsedBody($post) : $request;
    }

    // ------------------------------------------------------------------
    // Taille du corps
    // ------------------------------------------------------------------

    /**
     * @param array<array-key, mixed> $server
     */
    private function assertBodySizeIsAllowed(array $server): void
    {
        $length = $server['CONTENT_LENGTH'] ?? $server['HTTP_CONTENT_LENGTH'] ?? '';

        if ($length === '') {
            return;
        }

        if (!is_string($length) || !ctype_digit($length)) {
            throw RequestRejectedException::invalidContentLength();
        }

        // Au-delà de 18 chiffres, le nombre ne tient plus dans un entier : il est forcément trop grand.
        if (strlen($length) > 18 || (int) $length > $this->maxBodySize) {
            throw RequestRejectedException::bodyTooLarge($this->maxBodySize);
        }
    }

    // ------------------------------------------------------------------
    // URI
    // ------------------------------------------------------------------

    /**
     * @param array<array-key, mixed> $server
     */
    private function uriFrom(array $server): Uri
    {
        // Sans proxy de confiance, seul le serveur web dit si la connexion est
        // chiffrée : l'en-tête X-Forwarded-Proto, que n'importe quel client
        // peut écrire lui-même, est ignoré.
        $https = $server['HTTPS'] ?? '';
        $scheme = is_string($https) && $https !== '' && strtolower($https) !== 'off' ? 'https' : 'http';

        // Derrière un de VOS proxies, c'est lui qui a reçu la connexion du
        // visiteur : on croit ce qu'il en dit.
        $forwardedScheme = $this->forwarded($server, 'HTTP_X_FORWARDED_PROTO');

        if ($forwardedScheme !== null && in_array(strtolower($forwardedScheme), ['http', 'https'], true)) {
            $scheme = strtolower($forwardedScheme);
        }

        $authority = $this->authorityFrom($server, $scheme);
        $target = is_string($server['REQUEST_URI'] ?? null) ? $server['REQUEST_URI'] : '/';

        if (!str_starts_with($target, '/')) {
            // Forme rare : la cible est une URI complète (« GET http://exemple.com/page »).
            // On n'en garde que le chemin et la requête ; l'hôte reste celui validé plus haut.
            $parsed = new Uri($target);

            return new Uri($authority === '' ? '' : $scheme . '://' . $authority)
                ->withPath($parsed->getPath())
                ->withQuery($parsed->getQuery());
        }

        if ($authority === '') {
            // Sans hôte, une cible « //pirate.com/page » serait lue comme l'hôte
            // pirate.com : on ramène les barres obliques du début à une seule.
            return new Uri('/' . ltrim($target, '/'));
        }

        return new Uri($scheme . '://' . $authority . $target);
    }

    /**
     * L'hôte et le port de la requête (« exemple.com:8080 »), ou '' s'ils sont inconnus.
     *
     * @param array<array-key, mixed> $server
     */
    private function authorityFrom(array $server, string $scheme): string
    {
        // L'hôte annoncé par un proxy de confiance passe, comme tout autre,
        // par les vérifications qui suivent (forme valide, hôtes de confiance).
        $host = $this->forwarded($server, 'HTTP_X_FORWARDED_HOST') ?? $server['HTTP_HOST'] ?? '';

        if (!is_string($host) || $host === '') {
            $host = self::configuredHost($server);
        }

        if ($host === '') {
            return '';
        }

        try {
            $uri = new Uri($scheme . '://' . $host);
        } catch (InvalidUriException) {
            throw RequestRejectedException::invalidHost($host);
        }

        // Un Host ne contient qu'un hôte et un port. Les caractères qui ouvrent
        // une autre partie d'URI (« exemple.com/chemin », « utilisateur@exemple.com »)
        // signalent un Host falsifié.
        if ($uri->getHost() === '' || strpbrk($host, '/?#@\\') !== false) {
            throw RequestRejectedException::invalidHost($host);
        }

        if ($this->trustedHosts !== [] && !in_array($uri->getHost(), $this->trustedHosts, true)) {
            throw RequestRejectedException::untrustedHost($host);
        }

        return $uri->getAuthority();
    }

    // ------------------------------------------------------------------
    // Proxies de confiance
    // ------------------------------------------------------------------

    /**
     * Vrai si la requête arrive directement d'un proxy déclaré de confiance.
     *
     * Sécurité (ADR-022) : REMOTE_ADDR est l'adresse de la machine qui s'est
     * réellement connectée à PHP. Elle ne se falsifie pas par un en-tête.
     *
     * @param array<array-key, mixed> $server
     */
    private function comesFromTrustedProxy(array $server): bool
    {
        $remote = $server['REMOTE_ADDR'] ?? null;

        return is_string($remote) && $this->isTrustedProxy($remote);
    }

    private function isTrustedProxy(string $ip): bool
    {
        return array_any($this->trustedProxies, static fn(IpRange $range): bool => $range->contains($ip));
    }

    /**
     * La valeur d'un en-tête X-Forwarded-*, seulement si la requête vient d'un
     * proxy de confiance. S'il contient plusieurs valeurs, la première.
     *
     * @param array<array-key, mixed> $server
     */
    private function forwarded(array $server, string $key): ?string
    {
        $value = $server[$key] ?? null;

        if (!is_string($value) || !$this->comesFromTrustedProxy($server)) {
            return null;
        }

        $first = trim(explode(',', $value, 2)[0]);

        return $first !== '' ? $first : null;
    }

    /**
     * L'adresse IP du visiteur.
     *
     * Sans proxy de confiance, c'est l'adresse qui s'est connectée à PHP.
     * Derrière vos proxies, chacun ajoute à droite de X-Forwarded-For l'adresse
     * qu'il a vue : « visiteur, proxy1, proxy2 ». On lit de DROITE à GAUCHE et
     * on s'arrête à la première adresse qui n'est pas un de vos proxies.
     *
     * Sécurité : un client peut écrire ce qu'il veut à GAUCHE de cette liste.
     * En partant de la droite, on ne lit que ce que vos proxies ont écrit.
     *
     * @param array<array-key, mixed> $server
     */
    private function clientIp(array $server): string
    {
        $remote = is_string($server['REMOTE_ADDR'] ?? null) ? $server['REMOTE_ADDR'] : '';
        $chain = $server['HTTP_X_FORWARDED_FOR'] ?? null;

        if (!is_string($chain) || !$this->comesFromTrustedProxy($server)) {
            return $remote;
        }

        foreach (array_reverse(explode(',', $chain)) as $address) {
            $address = trim($address);

            // Une entrée qui n'est pas une adresse : on ne va pas plus loin.
            if (filter_var($address, FILTER_VALIDATE_IP) === false) {
                break;
            }

            if (!$this->isTrustedProxy($address)) {
                return $address;
            }
        }

        return $remote;
    }

    /**
     * Sans en-tête Host (vieux clients HTTP/1.0), on prend le nom réglé dans le serveur web.
     *
     * @param array<array-key, mixed> $server
     */
    private static function configuredHost(array $server): string
    {
        $name = $server['SERVER_NAME'] ?? '';

        if (!is_string($name) || $name === '') {
            return '';
        }

        // Une adresse IPv6 s'écrit entre crochets dans une URI : [::1]
        if (str_contains($name, ':') && !str_starts_with($name, '[')) {
            $name = '[' . $name . ']';
        }

        $port = $server['SERVER_PORT'] ?? '';

        return is_numeric($port) ? $name . ':' . (int) $port : $name;
    }

    // ------------------------------------------------------------------
    // En-têtes et version du protocole
    // ------------------------------------------------------------------

    /**
     * PHP range l'en-tête « Accept-Language » dans $_SERVER['HTTP_ACCEPT_LANGUAGE'] :
     * on fait le chemin inverse.
     *
     * @param array<array-key, mixed> $server
     *
     * @return array<string, string>
     */
    private static function headersFrom(array $server): array
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }

            if (str_starts_with($key, 'HTTP_') && $key !== 'HTTP_') {
                $headers[self::headerName(substr($key, 5))] = $value;
            } elseif (in_array($key, self::HEADERS_WITHOUT_PREFIX, true) && $value !== '') {
                $headers[self::headerName($key)] = $value;
            }
        }

        // Certains serveurs (Apache en mode CGI) déplacent l'en-tête Authorization.
        $authorization = $server['REDIRECT_HTTP_AUTHORIZATION'] ?? null;

        if (!isset($headers['Authorization']) && is_string($authorization)) {
            $headers['Authorization'] = $authorization;
        }

        return $headers;
    }

    /**
     * « ACCEPT_LANGUAGE » devient « Accept-Language ».
     */
    private static function headerName(string $serverKey): string
    {
        return ucwords(strtolower(str_replace('_', '-', $serverKey)), '-');
    }

    /**
     * @param array<array-key, mixed> $server
     */
    private static function protocolVersionFrom(array $server): string
    {
        $protocol = $server['SERVER_PROTOCOL'] ?? '';

        return is_string($protocol) && preg_match(self::PROTOCOL, $protocol, $matches) === 1 ? $matches[1] : '1.1';
    }

    private static function isFormSubmission(ServerRequest $request): bool
    {
        // « multipart/form-data; boundary=... » : seul ce qui précède le « ; » compte.
        $contentType = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'), 2)[0]));

        return $request->getMethod() === 'POST' && in_array($contentType, self::FORM_CONTENT_TYPES, true);
    }

    // ------------------------------------------------------------------
    // Fichiers envoyés
    // ------------------------------------------------------------------

    /**
     * Transforme $_FILES en arborescence d'objets UploadedFile.
     *
     * @param array<array-key, mixed> $files
     *
     * @return array<array-key, mixed>
     */
    private static function uploadedFilesFrom(array $files): array
    {
        $uploadedFiles = [];

        foreach ($files as $field => $value) {
            if ($value instanceof UploadedFileInterface) {
                $uploadedFiles[$field] = $value;

                continue;
            }

            if (!is_array($value)) {
                throw RequestRejectedException::malformedUploadedFiles();
            }

            $uploadedFiles[$field] = array_key_exists('tmp_name', $value)
                ? self::uploadedFileFrom(
                    $value['tmp_name'],
                    $value['size'] ?? null,
                    $value['error'] ?? null,
                    $value['name'] ?? null,
                    $value['type'] ?? null,
                )
                : self::uploadedFilesFrom($value);
        }

        return $uploadedFiles;
    }

    /**
     * Pour un champ <input name="photos[]" multiple>, PHP ne donne pas une liste
     * de fichiers mais une liste par propriété :
     *
     *     ['tmp_name' => ['/tmp/a', '/tmp/b'], 'size' => [10, 20], ...]
     *
     * On la retourne comme on l'attendrait : un fichier par entrée.
     *
     * @return UploadedFile|array<array-key, mixed>
     */
    private static function uploadedFileFrom(
        mixed $temporaryName,
        mixed $size,
        mixed $error,
        mixed $name,
        mixed $type,
    ): UploadedFile|array {
        if (is_array($temporaryName)) {
            $uploadedFiles = [];

            foreach ($temporaryName as $key => $item) {
                $uploadedFiles[$key] = self::uploadedFileFrom(
                    $item,
                    is_array($size) ? $size[$key] ?? null : null,
                    is_array($error) ? $error[$key] ?? null : null,
                    is_array($name) ? $name[$key] ?? null : null,
                    is_array($type) ? $type[$key] ?? null : null,
                );
            }

            return $uploadedFiles;
        }

        if (!is_string($temporaryName) || !is_int($error)) {
            throw RequestRejectedException::malformedUploadedFiles();
        }

        try {
            return new UploadedFile(
                $temporaryName,
                is_int($size) ? $size : null,
                $error,
                is_string($name) ? $name : null,
                is_string($type) ? $type : null,
            );
        } catch (InvalidUploadedFileException) {
            throw RequestRejectedException::malformedUploadedFiles();
        }
    }
}
