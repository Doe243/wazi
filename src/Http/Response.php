<?php

declare(strict_types=1);

namespace Wazi\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Wazi\Http\Exception\InvalidMessageException;

/**
 * La réponse HTTP que votre application renvoie au navigateur, immuable et conforme à PSR-7.
 *
 *     return new Response(200, ['Content-Type' => 'text/html'], '<h1>Bonjour</h1>');
 *
 *     HTTP/1.1 200 OK                    ← version, code de statut, phrase de statut
 *     Content-Type: text/html            ← en-têtes
 *
 *     <h1>Bonjour</h1>                   ← corps
 *
 * Le code de statut dit au navigateur comment la requête s'est passée :
 * 2xx tout va bien, 3xx allez voir ailleurs, 4xx la requête est en cause,
 * 5xx le serveur est en cause.
 *
 * Les en-têtes et le corps viennent de MessageTrait, qui se lit comme s'il
 * était écrit ici.
 *
 * Immuable : une Response ne change jamais. Chaque méthode with...() retourne
 * une NOUVELLE Response et laisse l'originale intacte.
 */
final readonly class Response implements ResponseInterface
{
    use MessageTrait;

    /**
     * Les phrases standard de chaque code (RFC 9110). Elles restent en anglais :
     * elles font partie du protocole, pas de ce que lit le visiteur.
     */
    private const array REASON_PHRASES = [
        100 => 'Continue',
        101 => 'Switching Protocols',
        103 => 'Early Hints',
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        307 => 'Temporary Redirect',
        308 => 'Permanent Redirect',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Content Too Large',
        414 => 'URI Too Long',
        415 => 'Unsupported Media Type',
        416 => 'Range Not Satisfiable',
        417 => 'Expectation Failed',
        421 => 'Misdirected Request',
        422 => 'Unprocessable Content',
        425 => 'Too Early',
        426 => 'Upgrade Required',
        428 => 'Precondition Required',
        429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large',
        451 => 'Unavailable For Legal Reasons',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported',
    ];

    private int $statusCode;

    private string $reasonPhrase;

    /**
     * @param array<array-key, mixed> $headers      nom de l'en-tête => valeur, ou liste de valeurs
     * @param string                  $reasonPhrase laissez vide pour obtenir la phrase standard du code
     *
     * @throws InvalidMessageException si le code, un en-tête, la version ou la phrase est invalide
     */
    public function __construct(
        int $statusCode = 200,
        array $headers = [],
        StreamInterface|string|null $body = null,
        string $protocolVersion = '1.1',
        string $reasonPhrase = '',
    ) {
        $this->statusCode = self::validStatusCode($statusCode);
        $this->reasonPhrase = self::reasonPhraseFor($statusCode, $reasonPhrase);

        [$this->headers, $this->headerNames] = self::indexedHeaders($headers);

        $this->body = self::bodyFrom($body);
        $this->protocolVersion = self::validProtocolVersion($protocolVersion);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase;
    }

    /**
     * Change le code de statut. Sans phrase, la phrase standard du code est utilisée.
     */
    #[\NoDiscard(self::NO_DISCARD)]
    public function withStatus(int $code, string $reasonPhrase = ''): self
    {
        return clone($this, [
            'statusCode' => self::validStatusCode($code),
            'reasonPhrase' => self::reasonPhraseFor($code, $reasonPhrase),
        ]);
    }

    // ------------------------------------------------------------------
    // Outils internes
    // ------------------------------------------------------------------

    private static function validStatusCode(int $code): int
    {
        if ($code < 100 || $code > 599) {
            throw InvalidMessageException::invalidStatusCode($code);
        }

        return $code;
    }

    private static function reasonPhraseFor(int $code, string $reasonPhrase): string
    {
        if ($reasonPhrase === '') {
            return self::REASON_PHRASES[$code] ?? '';
        }

        // La phrase est écrite dans la première ligne de la réponse : un retour
        // à la ligne permettrait d'y injecter des en-têtes.
        if (preg_match(self::SINGLE_LINE, $reasonPhrase) !== 1) {
            throw InvalidMessageException::invalidReasonPhrase($reasonPhrase);
        }

        return $reasonPhrase;
    }
}
